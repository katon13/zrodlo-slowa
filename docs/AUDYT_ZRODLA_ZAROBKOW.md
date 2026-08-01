# Audyt „ŹRÓDŁA ZAROBKÓW” — ETAP Z1

Data audytu: 2026-08-01  
Repozytorium: `X:\zrodlo-slowa`  
Gałąź i punkt wyjścia: `main`, commit `9e1afb9`  
Tryb: tylko do odczytu danych produkcyjno-deweloperskich

## Wynik w skrócie

Brak bonusu nie wynika z awarii workera ani księgi. Główną przyczyną jest brak
jakichkolwiek rekordów w `activity_reward_rules` w aktualnym PostgreSQL. Logowanie
administratora poprawnie utworzyło dwa trwałe zadania, worker odebrał je po około
0,5 s i zakończył jako `completed`, ale oba zwróciły tylko:

```json
{"award": null, "awarded": false}
```

Nie powstał wpis nagrody, transakcja portfela ani powiadomienie. Dlatego interfejs
nie miał prawdziwego komunikatu do wyświetlenia. Saldo administratora nie zostało
zmienione i nadal wynosi `129362` punktów.

Osobnym problemem wydajnościowym jest `worker-earnings`, który podczas bezczynności
odpytuje PostgreSQL co 2 sekundy. Producent zadań logowania nie korzysta obecnie z
sygnału Valkey, a adapter sygnału wykonuje nieblokujące `RPOP`, nie `BRPOP/BLPOP`.

## Zakres i zabezpieczenia

- wszystkie kontrole PostgreSQL wykonano w transakcjach `READ ONLY` zakończonych
  `ROLLBACK`;
- źródłowy MySQL uruchomiono wyłącznie z kopii backupu, na porcie `3307`, w trybie
  `read_only=ON` i `super_read_only=ON`, po czym poprawnie zatrzymano;
- nie importowano i nie aktywowano reguł;
- nie uruchamiano ręcznego naliczenia;
- nie zmieniono danych administratora ani jego salda;
- Laragon nie został uruchomiony, zmieniony ani przekonfigurowany;
- nie wykonano resetu PostgreSQL, `docker compose down -v` ani wdrożenia do chmury.

## Odpowiedzi na pytania ETAPU Z1

### 1. Czy worker działa?

Tak. Kontener `worker-earnings` działał bez restartów. Proces był uruchomiony jako:

```text
php scripts/worker_earnings.php --daemon --poll-seconds=2
```

W chwili kontroli zużywał około `0,05% CPU`, `9,621 MiB / 384 MiB` RAM i miał dwa
procesy. Logi zawierały dwa poprawnie obsłużone zadania. Nie znaleziono błędów
PostgreSQL, księgi, HMAC, retry ani dead-letter.

Sam stan kontenera nie jest jednak wystarczającym dowodem: dowodem wykonania są
rekordy `background_job_events` oraz czasy `started_at` i `completed_at`.

### 2. Czy logowanie utworzyło zadanie?

Tak. Logowanie administratora `id=4` utworzyło dwa zadania w
`earnings.critical`:

| ID | Aktywność | Klucz idempotencji | Status | Opóźnienie enqueue → start |
|---:|---|---|---|---:|
| 1129 | `login_bonus` | `talent:4:login_bonus:day:2026-08-01` | `completed` | ok. 0,513 s |
| 1130 | `day_visit_bonus` | `talent:4:day_visit_bonus:day:2026-08-01` | `completed` | ok. 0,522 s |

Oba zadania miały `attempts=1`, `max_attempts=8`, bez `last_error`. Historia obu
zawierała kolejno `enqueued`, `claimed`, `completed`.

### 3. Jaki był dokładny wynik zadania?

Oba zadania zwróciły:

```json
{"award": null, "awarded": false}
```

To biznesowa odmowa zamaskowana technicznym statusem `completed`. Obecny wynik nie
zapisuje kodu przyczyny. Z kodu i stanu bazy jednoznacznie wynika, że w tych dwóch
przypadkach przyczyną był `missing_rule`.

### 4. Czy istnieją reguły bonusów?

Nie. Aktualny PostgreSQL zawiera `0` rekordów w `activity_reward_rules`. Dotyczy to
również `registration_bonus`, `login_bonus`, `day_visit_bonus`,
`article_read_bonus`, ankiet i kampanii.

Selektywna migracja świadomie pomija tę tabelę
(`config/mysql_to_postgresql_selected_migration.php`). Kod nadal tworzy zadania,
ale worker nie ma reguły, na podstawie której mógłby przyznać nagrodę.

### 5. Dlaczego użytkownik nie zobaczył komunikatu?

W tym konkretnym przypadku nie istniało powiadomienie do pokazania:

```text
brak reguły
→ awarded=false
→ brak activity_reward_logs
→ brak wallet_transactions
→ brak activity_bonus_notifications
→ brak komunikatu po zaksięgowaniu
```

Niezależnie od tego obecny mechanizm prezentacji ma wadę: każda zwykła strona
zalogowanego użytkownika pobiera nieprzeczytane powiadomienia, a jeśli je znajdzie,
od razu oznacza wszystkie jako przeczytane przed potwierdzeniem wyświetlenia przez
przeglądarkę (`app/Controllers/BaseController.php`). Może to zgubić komunikat przy
wyścigu czasowym albo nieudanym renderowaniu.

Kontrolery kampanii mają też odwrotny błąd: po samym enqueue pokazują tekst
„Bonus trafił do portfela”, mimo że worker jeszcze niczego nie zaksięgował.

### 6. Czy saldo zostało zmienione?

Nie. Przed i po audycie portfel administratora ma:

```text
points_balance = 129362
available_minor = 0
pending_minor = 0
reserved_minor = 0
```

Jedyną transakcją jest kontrolowane otwarcie selektywnej migracji:

```text
id=134
type=selective_migration_opening
points_amount=129362
amount_minor=129362
idempotency_key=selected-migration-admin-4-opening-v1
```

Nie ma transakcji bonusowej.

### 7. Gdzie leży problem?

| Warstwa | Ocena | Wniosek |
|---|---|---|
| konfiguracja/dane | **przyczyna główna** | brak wszystkich reguł w PostgreSQL |
| worker | działa, ale nieoptymalnie | wykonuje zadania; stale odpytuje DB co 2 s |
| decyzja biznesowa | wymaga naprawy | wynik nie rozróżnia przyczyn odmowy |
| uprawnienia | wymaga naprawy | kod odczytuje `talent_enabled` i `wallet_enabled`, ale ich nie egzekwuje |
| księga/HMAC | bez błędu w tym przepływie | księgowanie nie zostało rozpoczęte, bo nie było reguły |
| prezentacja | wada wtórna | globalny SELECT i zbyt wczesny ACK; fałszywe komunikaty kampanii |

Administrator jest aktywny i ma `talent_enabled=1`, `wallet_enabled=1` oraz
`payout_enabled=1`, więc jego flagi nie były przyczyną tej odmowy.

### 8. Ile zapytań wykonuje zwykła strona, aby sprawdzić bonusy?

Dla zalogowanego użytkownika każdy render przez `BaseController::view()` wykonuje:

- zawsze 1 `SELECT` nieprzeczytanych powiadomień;
- dodatkowo 1 `UPDATE`, gdy cokolwiek znaleziono.

To koszt specyficzny dla powiadomień bonusowych: 1 zapytanie przy braku wiadomości,
2 zapytania przy ich obecności. Strona portfela wykonuje osobny SELECT listy
powiadomień. Żądanie anonimowe nie wykonuje tego sprawdzenia bonusowego.

Indeks `(user_id, seen_at, created_at)` istnieje, więc pojedynczy SELECT jest
indeksowany, ale nie uzasadnia to wykonywania go na każdej stronie.

### 9. Ile pustych odpytań PostgreSQL wykonuje worker?

Pętla śpi 2 sekundy po pustej próbie. Jedna próba `claimOne()` wykonuje co najmniej:

1. SELECT wygasłych dzierżaw w `recoverExpiredLeases()`;
2. SELECT kolejnego zadania `queued/retry`.

Wynik teoretyczny dla bezczynnego workera:

| Miara | Obecnie, 2 s | Safety sweep 60 s |
|---|---:|---:|
| puste cykle/h | ok. 1 800 | maks. ok. 60 |
| puste cykle/dobę | ok. 43 200 | maks. ok. 1 440 |
| same puste SELECT-y/dobę | co najmniej ok. 86 400 | ok. 2 880 przy niezmienionym algorytmie |

Redukcja liczby pustych cykli wyniesie około `96,7%`. Rzeczywisty licznik zapytań
nie był dostępny, ponieważ `pg_stat_statements` nie jest zainstalowane; wartości
wyliczono bezpośrednio z pętli i ścieżki kodu. Transakcje i ewentualne zapisy
powiększają całkowitą liczbę komunikatów z bazą.

### 10. Czy są księgowane zdarzenia, których autor nie dostaje?

Aktualnie w bazie jest `0` zakupów artykułów, `0` wsparć i `0` płatności, więc nie
ma istniejącego zdarzenia sprzedażowego bez komunikatu. Jest jednak luka w kodzie:

- zakup synchronicznie obciąża kupującego, księguje przychód autora i przyznaje
  dostęp, lecz nie tworzy zdarzenia/outboxa powiadomienia autora;
- wsparcie synchronicznie obciąża czytelnika i uznaje autora, również bez outboxa.

Po pierwszej sprzedaży lub wsparciu autor może więc otrzymać pieniądze bez
bezpośredniego komunikatu. Zdarzenie outbox powinno powstać w tej samej transakcji
co skutek finansowy, a dostarczenie powinno być asynchroniczne.

## Stan reguł: PostgreSQL a źródłowy MySQL

Wartości MySQL są materiałem porównawczym, nie zatwierdzoną konfiguracją. Wszystkie
16 starych reguł było aktywnych. PostgreSQL nie ma żadnej. `amount_minor` poniżej
podano dokładnie tak, jak zapisano w źródle; jego znaczenie ekonomiczne i waluta
muszą zostać zatwierdzone przed użyciem.

| Typ | Stary MySQL: TT / amount_minor / limit / aktywna | PostgreSQL | Rekomendacja | Główne ryzyko |
|---|---|---|---|---|
| `registration_bonus` | 500 / 50 / 1 / tak | brak | utworzyć 0/0, wyłączoną | masowe konta, najwyższa jednorazowa wartość |
| `day_visit_bonus` | 10 / 10 / 1 / tak | brak | kandydat do późniejszej aktywacji po decyzji | boty, wiele kont, definicja dnia |
| `login_bonus` | 10 / 5 / 1 / tak | brak | domyślnie wyłączona; nie łączyć z `day_visit_bonus` | podwójna nagroda za jedno wejście |
| `article_read_bonus` | 5 / 3 / 10 / tak | brak | wyłączona do wdrożenia czasu/postępu czytania | odświeżanie, bot, nagroda za samo otwarcie |
| `comment_bonus` | 20 / 20 / 5 / tak | brak | wyłączona do reguł jakości i moderacji | spam komentarzy |
| `share_bonus` | 10 / 10 / 10 / tak | brak | wyłączona do potwierdzenia skutecznego udostępnienia | fałszywe akcje |
| `link_click_bonus` | 2 / 2 / 20 / tak | brak | wyłączona do antyfraudu | automatyczne klikanie |
| `like_bonus` | 1 / 1 / 20 / tak | brak | wyłączona do antyfraudu | masowe polubienia |
| `bug_report_bonus` | 25 / 25 / 3 / tak | brak | tylko po akceptacji zgłoszenia przez redakcję | duplikaty i zgłoszenia pozorne |
| `survey_reward` | 50 / 50 / 5 / tak | brak | dopiero z atomową rezerwacją budżetu | szybkie/automatyczne odpowiedzi |
| `sponsored_article_read_bonus` | 20 / 20 / 10 / tak | brak | wyłączona do potwierdzonego czasu czytania | koszt kampanii i boty |
| `ad_view_reward` | 30 / 30 / 20 / tak | brak | wyłączona; wymaga czasu oglądania i budżetu | 600 jednostek/dzień/konto; view droższy od click |
| `ad_click_reward` | 15 / 15 / 20 / tak | brak | wyłączona; wymaga antyfraudu i budżetu | farmy kliknięć |
| `newsletter_open_reward` | 10 / 10 / 5 / tak | brak | wyłączona do wiarygodnego zdarzenia dostawcy | piksele prywatności, automatyczne otwarcia |
| `ppv_reward` | 20 / 20 / 10 / tak | brak | wyłączona do potwierdzonego udziału | sztuczne sesje |
| `live_event_reward` | 20 / 20 / 10 / tak | brak | wyłączona do minimalnego czasu obecności | wiele kart, boty |

Bezpieczny ETAP Z2 powinien jedynie utworzyć kanoniczne rekordy z
`points_amount=0`, `amount_minor=0`, `is_active=0`, używając
`ON CONFLICT(activity_type) DO NOTHING`. Nie należy kopiować aktywnych wartości ze
starego MySQL.

## Dodatkowy wymóg: bonus interwałowy tylko dla aktualnie zalogowanych

Wymóg użytkownika z 2026-08-01: bonus przydzielany w interwale czasowym ma dotyczyć
wyłącznie użytkowników zalogowanych w tym interwale.

### Czego nie wolno uznać za obecność online

Samo istnienie rekordu w `sessions` nie oznacza obecności w danej chwili. Podczas
audytu tabela zawierała 5 uwierzytelnionych sesji administratora, ale żadna nie
miała aktywności w ostatnich 15 minutach, oraz 5 990 sesji anonimowych. TTL sesji
może utrzymywać rekord po zamknięciu karty. Periodyczne skanowanie wszystkich sesji
albo wszystkich użytkowników byłoby jednocześnie niedokładne i nieoptymalne.

### Obowiązkowy ping obecności i rekomendowany przepływ

Przeglądarki nie da się wiarygodnie odpytać z serwera w zwykłym modelu HTTP bez
SSE/WebSocket. Dlatego „ping użytkownika” powinien mieć postać lekkiego heartbeat
`przeglądarka → serwer`: tylko aktywna i widoczna karta, z poprawną uwierzytelnioną
sesją, odnawia krótką dzierżawę obecności w Valkey. Zwykłe uwierzytelnione żądania
powinny odnawiać tę samą dzierżawę, aby nie generować dodatkowego ruchu. Osobny ping
jest potrzebny dopiero wtedy, gdy karta jest widoczna, ale użytkownik nie wykonuje
normalnych żądań.

„Zalogowany w interwale” powinno więc znaczyć: aplikacja otrzymała świeży,
uwierzytelniony dowód obecności w konkretnym oknie czasu. Sam rekord sesji lub samo
wcześniejsze logowanie nie wystarcza. Okno musi być liczone w jawnej strefie
biznesowej. Dla użytkownika, reguły i okna powstaje jeden stabilny klucz:

```text
interval:<activity_type>:<bucket_start>:<user_id>
```

Przepływ bez globalnego skanowania:

```text
zwykłe uwierzytelnione żądanie albo ograniczony heartbeat widocznej karty
→ odnowienie krótkiej dzierżawy obecności w Valkey
→ potwierdzenie, że dzierżawa jest świeża dla bieżącego okna
→ lekka deduplikacja bonusu interwałowego w Valkey
→ krótki INSERT trwałego joba z unikalnym kluczem w PostgreSQL
→ COMMIT
→ sygnał Valkey dla worker-earnings
→ odpowiedź HTTP bez oczekiwania
→ worker sprawdza regułę, flagi, limit, antyfraud i idempotencję
→ księguje dokładnie raz
```

Ping nie może skanować użytkowników, wykonywać antyfraudu, czytać portfela ani
księgować środków. Ma jedynie potwierdzić sesję, odnowić dzierżawę i — najwyżej raz
na użytkownika i interwał — uruchomić lekki enqueue. Powinien być ograniczony
częstotliwościowo i wyłączany po ukryciu karty (`visibilityState`), wylogowaniu lub
wygaśnięciu sesji. Wartość TTL musi być krótka i jawnie związana z częstotliwością
pingów; nie wolno używać całodobowego TTL sesji jako dowodu bycia online.

Polityką tą zarządza SNAJPER SŁOWA: definiuje częstotliwość i TTL, rozpoznaje świeży
dowód obecności, określa typy nagród wymagające obecności, stosuje limity oraz
zwraca jawną decyzję `eligible` albo `not_present`. SNAJPER nie zapisuje salda — po
jego decyzji skutek finansowy nadal wykonuje `worker-earnings` w PostgreSQL.

Valkey przechowuje krótkotrwałą obecność i służy do deduplikacji na gorącej ścieżce,
ale nie jest źródłem prawdy skutku finansowego. Trwały job zapisuje `observed_at`,
identyfikator interwału i unikalny klucz. Worker ocenia obecność według tego
zapisanego dowodu, a nie przypadkowej chwili późniejszego wykonania joba.
Wylogowanie już po prawidłowym pingnięciu nie może zmienić wyniku zdarzenia, które
było ważne w momencie jego powstania. Unikalność w PostgreSQL nadal gwarantuje
dokładnie jedno zadanie i jedno księgowanie.

Ta definicja nie uznaje zamkniętej ani ukrytej karty za aktywność i nie próbuje
„wysyłać w ciemno”. Częstotliwość heartbeat trzeba ustalić razem z interwałem i
zmierzyć pod obciążeniem. Ping powinien przede wszystkim podpinać się pod istniejący
ruch, aby nie zastąpić jednego kosztownego pollingu innym.

Reguła interwałowa nie może być aktywowana, dopóki nie zostaną ustalone:

- długość interwału;
- częstotliwość pingów i TTL dzierżawy obecności;
- biznesowa strefa czasu;
- czy obecność oznacza widoczną kartę, czy także aktywność w tle;
- wartość i limit nagrody;
- zachowanie na granicy okna;
- polityka wielu kart i wielu równoległych instancji.

## Pozostałe ustalenia techniczne

### Worker i Valkey

- `compose.yaml` wymusza `--poll-seconds=2`;
- `TalentService` i `SurveyService` tworzą `DurableJobQueue` bez
  `QueueSignalInterface`, więc enqueue nie budzi workera;
- `ValkeyQueueSignal::consume()` używa nieblokującego `RPOP`;
- kontrakt `ValkeyClientInterface` nie ma jeszcze operacji blokującego pop;
- konfiguracja SNAJPERA nie ma sekcji `earnings_worker`;
- nie ma heartbeat workera ani metryk ostatniego sygnału/safety sweep.

Docelowo potrzebny jest jeden dispatcher dla wszystkich producentów earnings,
blokujące oczekiwanie Valkey z timeoutem 60 s oraz jeden safety sweep PostgreSQL po
timeout. Brak Valkey nie może blokować logowania ani utracić trwałego joba.

### Decyzja workera

`TalentService::award()` filtruje regułę przez `is_active=1`, więc obecnie nie
odróżnia `missing_rule` od `inactive_rule`. Zwraca również `null` dla zera, limitu,
duplikatu i nieaktywnego użytkownika. Odczytuje flagi `talent_enabled` oraz
`wallet_enabled`, ale ich nie sprawdza przed naliczeniem.

Każda próba powinna zwracać jawne `decision` i `reason`, w szczególności:
`missing_rule`, `inactive_rule`, `zero_value`, `duplicate`, `daily_limit`,
`user_inactive`, `talent_disabled`, `wallet_disabled`, `antifraud_hold` albo
`eligible/awarded`.

### Znaczenie bonusu wejścia

Logowanie kolejkuje jednocześnie `login_bonus` i `day_visit_bonus`; oba klucze są
dobowe. Aktywowanie obu reguł mogłoby przyznać dwie nagrody za jedno wejście.
Rekomendowany wariant do późniejszego zatwierdzenia:

- `day_visit_bonus` — jedna nagroda za pierwszą potwierdzoną uwierzytelnioną wizytę
  dnia w ustalonej strefie biznesowej;
- `login_bonus` — domyślnie wyłączony, ewentualnie użyty później do innej promocji.

### Zbyt wczesny bonus za artykuł

Aktualny `ArticleController` kolejkuje `article_read_bonus` już przy otwarciu
artykułu. Nie ma minimalnego czasu, potwierdzonego postępu ani dowodu przeczytania.
Reguła powinna pozostać wyłączona do wdrożenia takiego warunku.

## Rekomendowana kolejność dalszych prac

1. Z2: utworzyć kanoniczny katalog wyłączonych, zerowych reguł.
2. Z3: wprowadzić jawne decyzje i egzekwować status oraz flagi użytkownika.
3. Z4: po decyzji użytkownika pozostawić jeden mechanizm bonusu wejścia i ustalić
   strefę czasu; osobno doprecyzować interwał obecności online.
4. Z5: wspólny dispatcher, blokujące budzenie Valkey, 60-sekundowy safety sweep,
   heartbeat i limity SNAJPERA.
5. Z6: lekkie endpointy statusu/powiadomień, kursor po ID i ACK z przeglądarki.
6. Z7: transactional outbox dla sprzedaży i wsparcia.
7. Z8–Z10: poprawić dowody aktywności, panel diagnostyczny oraz testy
   idempotencji, awarii i wydajności.

## Decyzje wymagane przed aktywacją wartości

Żadna wartość nie została aktywowana. Przed aktywacją potrzebne są osobne, jawne
decyzje:

1. Czy bonus wejścia ma używać rekomendowanego `day_visit_bonus`, przy wyłączonym
   `login_bonus`?
2. Jaka jest biznesowa strefa dnia?
3. Jaka jest długość i semantyka bonusu interwałowego dla zalogowanych?
4. Czy stare liczby z MySQL są jedynie punktem odniesienia, czy mają być podstawą
   propozycji nowych stawek?
5. Czy `amount_minor` ma być aktywne obok TT; jeżeli tak, w jakiej walucie i z
   jakim budżetem dziennym/miesięcznym?

Do czasu tych decyzji kanoniczne reguły powinny pozostać zerowe i nieaktywne.
