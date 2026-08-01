# Raport diagnostyczny projektu ŹRÓDŁO SŁOWA

**Data audytu:** 24 lipca 2026  
**Badany katalog:** `X:\zrodlo-slowa`  
**Charakter audytu:** statyczny, bez modyfikowania kodu aplikacji i bez operacji na danych  
**Środowisko:** PHP 8.3.30, Laragon/Apache/MySQL

## 1. Wniosek wykonawczy

Projekt ma spójną, własną architekturę MVC, rozbudowany model domenowy i kilka dobrze
zaprojektowanych zabezpieczeń. Nie jest jednak jeszcze gotowy do bezpiecznego wdrożenia
produkcyjnego. Największe ryzyka dotyczą:

1. księgi finansowej i wypłat;
2. przepływów Apple/Google OAuth oraz webhooka Stripe;
3. instalacji i aktualizacji schematu bazy danych;
4. sesji, logowania i dostarczania poczty;
5. braku testów automatycznych chroniących krytyczne reguły biznesowe.

Najpilniejsze błędy nie są błędami składni. Kod przechodzi lint PHP, ale w kilku miejscach
może:

- nie zaksięgować drugiej strony transferu;
- utracić aktualizację salda przy równoległych żądaniach;
- uniemożliwić działanie zasady Maker–Checker;
- odrzucać prawidłowe callbacki Apple i Stripe kodem 419;
- przyjąć niezweryfikowany kryptograficznie token OAuth;
- częściowo wykonać instalację bazy i zatrzymać się na triggerze.

**Rekomendacja:** najpierw zamrozić nowe funkcje finansowe i OAuth, naprawić punkty P0,
dodać testy integracyjne, a dopiero później uruchamiać aplikację na kopii bazy.

## 2. Zakres i ograniczenia

Sprawdzono:

- strukturę projektu, autoloading, routing, kontrolery, serwisy i widoki;
- przepływy logowania, OAuth, 2FA, resetu hasła i weryfikacji e-mail;
- płatności, webhook Stripe, portfel, wypłaty, transfery i Maker–Checker;
- instalator, plik schematu SQL i skrypty finansowe;
- konfigurację Laragon/Apache, moduły PHP i pliki `.env.example`;
- tłumaczenia, cache, uploady, nagłówki bezpieczeństwa i jakość repozytorium;
- dostępne skrypty kontrolne.

Nie wykonano testów dynamicznych wymagających bazy i serwera HTTP, ponieważ w chwili
audytu Laragon nie działał, porty `8080` i `3306` nie nasłuchiwały, a
`http://localhost:8080` był niedostępny. Nie uruchamiano instalatora, resetu bazy,
migracji ani operacji finansowych.

## 3. Stan techniczny projektu

### 3.1. Repozytorium

- gałąź: `main`, śledząca `origin/main`;
- ostatni commit: `2ad728da2256d97fb743e98e6e3d8fc4940ab48c`,
  z 1 czerwca 2026;
- bieżący katalog roboczy zawiera dużą, prawdopodobnie celową przebudowę:
  54 pliki zmodyfikowane, 77 usuniętych i 75 faktycznie nieśledzonych plików;
- różnica w plikach śledzonych: 131 plików, około `+2218/-6879`;
- wszystkie dotychczasowe migracje są obecnie oznaczone jako usunięte;
- `database/zrodlo_slowa.sql` jest nieśledzonym, pełnym schematem 71 tabel;
- plik SQL nie zawiera instrukcji `INSERT`, danych użytkowników ani hashy haseł.

Tego stanu nie wolno resetować ani nadpisywać. Przed naprawami należy go zapisać na
osobnej gałęzi i utworzyć kopię zapasową.

### 3.2. Kontrole, które przeszły

- lint 175 bieżących plików PHP spoza `vendor`: **0 błędów składni**;
- 6 plików JSON: **poprawna składnia**;
- 134 zarejestrowane trasy: **0 brakujących kontrolerów lub metod**;
- 62 odwołania do widoków, 49 unikalnych widoków: **0 brakujących plików**;
- 241 analizowanych utworzeń klas aplikacji: **0 brakujących klas**;
- bieżące pliki tekstowe: **0 nieprawidłowych sekwencji UTF-8**;
- wymagane moduły PHP są aktywne, między innymi:
  `pdo_mysql`, `curl`, `openssl`, `gd`, `fileinfo`, `mbstring`, `intl`, `sodium`.

### 3.3. Kontrole, które nie przeszły

- `composer validate`: kod wyjścia 2; `composer.lock` nie odpowiada
  `composer.json`, brak pola `license`;
- skaner tłumaczeń: 5 brakujących kluczy w `resources/lang/public.json`;
- kontrola kontekstu językowego: kod wyjścia 1, ale wykrycie jest fałszywie dodatnie;
- kontrola `sites.json` wypisuje błąd dotyczący `/pl`, mimo że konfiguracja świadomie
  używa prefiksu `pl`; skrypt błędnie kończy się kodem 0;
- `git diff --check`: liczne końcowe spacje i pusty wiersz na końcu CSS;
- brak PHPUnit/Pest, PHPStan/Psalm i workflow CI.

## 4. Klasyfikacja priorytetów

| Priorytet | Znaczenie |
|---|---|
| P0 | Blokuje produkcję lub może naruszyć pieniądze, integralność albo uwierzytelnianie |
| P1 | Naprawić przed wdrożeniem produkcyjnym |
| P2 | Stabilizacja, utrzymanie i ograniczenie przyszłych regresji |

## 5. Problemy P0

### P0-1. Globalny CSRF blokuje callback Apple i webhook Stripe

**Dowód:**

- `app/Core/Router.php:20` wywołuje `verify_csrf()` dla każdego żądania POST;
- `public/index.php:53` rejestruje `POST /auth/apple/callback`;
- `public/index.php:98` rejestruje `POST /stripe/webhook`.

Apple i Stripe nie wysyłają tokenu CSRF utworzonego przez sesję tej aplikacji. Router
zatrzyma oba żądania kodem 419, zanim kontrolery zweryfikują `state` albo podpis Stripe.

**Naprawa:**

- dodać metadane/middleware per trasa;
- wyłączyć CSRF wyłącznie dla precyzyjnie wskazanych callbacków zewnętrznych;
- nadal obowiązkowo weryfikować `state` dla Apple i podpis
  `Stripe-Signature` dla webhooka;
- dodać test: prawidłowy webhook bez CSRF dochodzi do weryfikatora, zwykły POST bez
  CSRF nadal otrzymuje 419.

### P0-2. OAuth ma błędy wykonawcze i nie weryfikuje podpisów tokenów

**Dowód:**

- `app/Services/AppleOAuthService.php:86` używa nieistniejącej funkcji
  `file_get_content()` zamiast `file_get_contents()`;
- `app/Services/AppleOAuthService.php:101` koduje podpis OpenSSL w formacie DER,
  podczas gdy JWT ES256 wymaga 64-bajtowego formatu JOSE `R || S`;
- `AppleOAuthService.php:110-143` i `GoogleOAuthService.php:59-102` dekodują payload
  JWT, ale nie weryfikują podpisu JWS za pomocą kluczy JWKS dostawcy;
- przepływy używają `state`, ale nie używają `nonce`;
- `config/oauth.php` zwraca surowe teksty z `env()` jako flagi `enabled`; tekst
  `"false"` jest w PHP wartością prawdziwą.

Sprawdzenie `iss`, `aud` i `exp` po samym zdekodowaniu payloadu nie daje
autentyczności tokenu. Atakujący może sam zbudować payload z poprawnymi polami.

**Naprawa:**

- użyć utrzymywanej biblioteki OIDC/JWT albo oficjalnego klienta dostawcy;
- weryfikować JWS, `kid`, JWKS, algorytm, `iss`, `aud`, `exp`, `iat` i `nonce`;
- poprawnie generować Apple client secret ES256;
- flagi środowiskowe parsować funkcją zwracającą rzeczywisty `bool`;
- testować token poprawny, zmieniony payload, zły `aud`, wygasły token i replay nonce.

### P0-3. Idempotencja psuje dwustronne księgowanie rezerwacji wypłaty

**Dowód:**

- `app/Services/PayoutService.php:51-55` przekazuje klucz
  `payout-request-{id}`;
- `app/Services/LedgerService.php:113-137` wykonuje dwa wpisy:
  zmniejszenie `available` i zwiększenie `reserved`, ale z tym samym kluczem;
- `app/Services/FinancialService.php:31-35` przy drugim użyciu klucza zwraca
  istniejącą transakcję zamiast wykonać drugi wpis;
- analogiczny błąd występuje przy zwolnieniu rezerwacji przez
  `payout-release-{id}` w `PayoutService.php:106` i
  `LedgerService.php:147-163`.

**Skutek:** środki mogą zniknąć z dostępnego salda bez pojawienia się w saldzie
zarezerwowanym. Przy odrzuceniu wypłaty możliwe jest zdjęcie rezerwacji bez oddania
środków do salda dostępnego.

**Naprawa:**

- najlepiej modelować transfer jako jedną atomową operację domenową z dwiema pozycjami;
- minimalnie użyć osobnych kluczy, np.
  `payout-request-{id}:available` i `payout-request-{id}:reserved`;
- dodać invariant:
  `available_before + reserved_before = available_after + reserved_after`;
- testować ponowienie całej operacji po timeoutach i awarii między obiema pozycjami.

### P0-4. Maker–Checker czyta nieistniejący klucz roli z sesji

**Dowód:**

- `app/Core/Session.php:7-16` zapisuje rolę w `$_SESSION['role']`;
- `app/Services/FinancialService.php:182`, `:210` i `:235` czyta
  `$_SESSION['user_role']`.

W rezultacie audyt zapisuje rolę `guest`, zlecenie rolę `unknown`, a wymagane
zatwierdzenie przez parę Administrator–Wydawca nie może się udać.

**Naprawa:**

- usunąć bezpośrednie odwołania do surowego `$_SESSION` z warstwy finansowej;
- przekazywać jawny `ActorContext` albo użyć wspólnej klasy `Session`;
- uprawnienia i komplet ról pobierać z bazy, nie ufać pojedynczej roli z sesji;
- dodać testy par: admin→publisher, publisher→admin, własne zlecenie oraz osoba bez roli.

### P0-5. Księga finansowa nie jest bezpieczna przy współbieżności

**Dowód:**

- `app/Services/FinancialService.php:38` pobiera portfel bez `FOR UPDATE`;
- saldo jest obliczane w PHP i aktualizowane później;
- `FinancialService.php:76` pobiera ostatni globalny hash bez blokady;
- `app/Services/WalletTransferService.php:91` i `:126` pobiera transfer do
  zatwierdzenia/odrzucenia bez blokady.

Dwa równoległe żądania mogą odczytać to samo saldo, nadpisać sobie wynik albo wydać
więcej środków niż dostępne. Równoległe wpisy mogą też otrzymać ten sam
`previous_hash`, rozwidlając łańcuch. Jednoczesne approve/reject transferu może wykonać
dwa różne skutki finansowe.

**Naprawa:**

- blokować wiersz portfela przez `SELECT ... FOR UPDATE`;
- tworzenie portfela chronić unikalnością i obsłużyć wyścig INSERT;
- zmianę stanu transferu wykonać przez blokadę albo warunkowy atomowy UPDATE;
- głowę łańcucha księgi serializować w osobnym, blokowanym wierszu albo utrzymywać
  osobne łańcuchy per portfel;
- uruchomić testy współbieżne dla zakupu, wypłaty, nagrody i transferu.

### P0-6. Brak bezpiecznej ścieżki instalacji i aktualizacji bazy

**Dowód:**

- wszystkie dotychczasowe pliki `database/migrations/*.sql` są obecnie usunięte;
- jedyny pełny schemat `database/zrodlo_slowa.sql` jest nieśledzony;
- `app/Services/InstallService.php:20-23` zawsze uruchamia cały schemat;
- parser `InstallService.php:170-195` dzieli SQL po średnikach;
- schemat zawiera `DELIMITER ;;` i trigger z blokiem `BEGIN ... END`;
- check instalatora z `InstallService.php:41-75` nie obejmuje między innymi
  `financial_approvals`, `financial_audit_log`, tabel banerów i kont OAuth;
- `InstallService.php:198-229` może ponownie ustawić hasło istniejącego administratora;
- przykładowe środowisko zawiera znane, zastępcze hasło administratora.

Parser nie rozumie delimiterów procedur MySQL. Instalacja może wykonać część DDL,
zatrzymać się na triggerze i zostawić częściowo zbudowaną bazę. Ponowne uruchomienie
pełnego schematu na istniejącej bazie zatrzyma się na istniejących tabelach.

**Naprawa:**

- przywrócić wersjonowane, jednokierunkowe migracje;
- osobno utrzymywać schemat świeżej instalacji;
- wykonywać dump klientem MySQL rozumiejącym `DELIMITER`, nie prostym splitterem;
- zapisywać wersję schematu i wykonywać tylko brakujące migracje;
- rozszerzyć health-check o wszystkie tabele, kolumny, indeksy i triggery krytyczne;
- nie zmieniać hasła istniejącego admina podczas normalnego install/update;
- na produkcji odmówić startu z hasłem zastępczym;
- dodać test: czysta instalacja oraz upgrade z kopii poprzedniej wersji.

## 6. Problemy P1

### P1-1. HMAC i audyt księgi wymagają wzmocnienia

- `FinancialService.php:16-22` pozwala na pusty `FINANCE_HMAC_KEY` w trybie debug;
- klucza nie ma w `.env.example` ani `.env.install.example`;
- podpis nie obejmuje wszystkich istotnych pól transakcji, np. statusu, opisu,
  idempotency key, referencji i części metadanych;
- `scripts/verify_ledger.php` i `scripts/backfill_ledger_hashes.php` wpisują na sztywno
  walutę `PLN`, podczas gdy producent pobiera walutę portfela;
- skrypt nie porównuje sald portfela z sumą wpisów;
- zapis statusu `failed` w `FinancialService::approve()` jest wykonywany wewnątrz
  transakcji, po czym wyjątek jest ponownie rzucany — rollback prawdopodobnie cofnie
  również informację o błędzie;
- `PayoutService` może działać bez `FraudGuard`, a błędy pomocniczego logowania statusu
  są wyciszane.

**Naprawa:** wersjonowany, kanoniczny format podpisu, obowiązkowy silny klucz we
wszystkich środowiskach, procedura rotacji, pełny verifier invariantów i trwały zapis
błędów po rollbacku operacji.

### P1-2. Sesje nie mają jawnego hardeningu

- `app/Core/App.php:23` uruchamia sesję bez jawnej konfiguracji cookie;
- `app/Core/Session.php:11` nie regeneruje identyfikatora po logowaniu;
- `Session.php:16` niszczy sesję, ale nie wygasza cookie;
- brak jawnych ustawień `session.use_strict_mode`, `Secure`, `HttpOnly` i `SameSite`.

**Naprawa:** skonfigurować sesję przed `session_start()`, regenerować ID przy logowaniu,
OAuth i zmianie uprawnień, wygaszać cookie przy wylogowaniu. `Secure` ma być wymagane
na HTTPS.

### P1-3. OAuth omija wspólną politykę 2FA i wysokich ról

`app/Services/OAuthAccountService.php:102-120` loguje użytkownika bezpośrednio przez
`Session::login()`. Nie przechodzi przez `AuthController::finishLogin()`, gdzie
sprawdzane są 2FA i wymagania bezpieczeństwa wysokich ról. Dodatkowo zapytania z
`LEFT JOIN user_roles ... LIMIT 1` wybierają nieokreśloną rolę użytkownika mającego
kilka ról.

**Naprawa:** jeden wspólny serwis kończący każde logowanie, deterministyczny model roli
głównej i autoryzacja na podstawie pełnego zestawu ról z bazy.

### P1-4. Brak ochrony przed zgadywaniem haseł i kodów 2FA

`app/Services/AuthService.php:38-42` weryfikuje hasło, lecz nie ma limitu prób,
blokady konta ani backoffu IP+konto. Weryfikacja kodu 2FA ma TTL sesji, ale nie licznik
prób.

**Naprawa:** rate limiting IP+konto, narastające opóźnienie, limit prób 2FA, alerty i
neutralne komunikaty bez ujawniania istnienia konta.

### P1-5. Poczta jest kolejkowana, ale nie jest wysyłana

`app/Services/MailService.php` potrafi wyłącznie dodać rekord do `mail_queue` i wyświetlić
ostatnie rekordy. W repozytorium nie znaleziono workera, transportu SMTP ani kodu
zmieniającego status na `sent`.

Reset hasła i weryfikacja e-mail nie dotrą do użytkownika. Jednocześnie
`app/Controllers/AccountSecurityController.php:25` zawsze pokazuje zalogowanemu
użytkownikowi lokalny link weryfikacyjny, co omija dowód dostępu do skrzynki.

**Naprawa:** worker CLI/cron z retry, blokadą rekordów, statusem i monitoringiem;
SMTP/API mailowe z konfiguracją; link testowy wyłącznie w środowisku local/debug.

### P1-6. Reset hasła nie unieważnia innych tokenów i sesji

`app/Services/UserService.php:354-367` oznacza jako użyty tylko jeden token resetu.
Pozostałe ważne tokeny użytkownika nadal działają, a aktywne sesje nie są unieważniane.
Minimalna polityka hasła to tylko 8 znaków.

**Naprawa:** po zmianie hasła unieważnić wszystkie tokeny i sesje użytkownika, dodać
rate limit żądań resetu oraz politykę opartą przede wszystkim na długości i sprawdzaniu
haseł skompromitowanych.

### P1-7. Autor może bezpośrednio zmienić opublikowany artykuł

`app/Services/ArticleService.php:233-245` pozwala edytować status `published`.
Zmiana treści trafia od razu do publicznego artykułu bez nowej korekty i akceptacji,
może też rozjechać tłumaczenia oraz SEO.

**Naprawa:** edycja publikacji tworzy rewizję/szkic, a publikacja zmiany ponownie
przechodzi workflow redakcyjny. Po publikacji trzeba atomowo unieważnić cache,
tłumaczenia zależne i metadane.

### P1-8. Konfiguracja uruchomienia Laragon jest niespójna z aplikacją

- `.env` wskazuje `http://localhost:8080`;
- bieżący Apache Laragon ma vhosty na porcie 80 i automatyczne hosty dla każdego
  katalogu projektu, między innymi `public.test`, `app.test`, `database.test`;
- katalogi wewnętrzne są obecnie chronione przez `.htaccess`, co jest dobre;
- `public/.htaccess` nie istnieje, a główny `.htaccess` nie przekazuje czystych URL-i
  do `public/index.php`;
- w repozytorium nie ma innej udokumentowanej konfiguracji front-controller.

Przy standardowym Apache trasy takie jak `/articles/...` mogą kończyć się 404.
Na Nginx zabezpieczenia `.htaccess` w ogóle nie działają.

**Naprawa:** jeden vhost z `DocumentRoot` ustawionym na `public`, jawna reguła
front-controller, wyłączenie automatycznych vhostów katalogów projektu, zgodny
`APP_URL`, HTTPS i wersjonowana przykładowa konfiguracja Apache/Nginx.

### P1-9. Zbyt wiele wyjątków jest pokazywanych użytkownikowi

W wielu kontrolerach `catch` przekazuje bezpośrednio `$e->getMessage()` do flasha lub
odpowiedzi HTTP, także w publicznych przepływach. Obsłużony wyjątek omija globalny,
produkcyjny error handler i może ujawnić SQL, konfigurację albo szczegóły dostawcy.

**Naprawa:** logować pełny wyjątek z correlation ID, użytkownikowi zwracać tylko
zdefiniowane komunikaty domenowe. Odpowiedzi webhooków nie powinny ujawniać treści
wyjątku.

### P1-10. Miesięczny budżet AI jest tylko ustawieniem UI

`ai.translation.monthly_budget_minor` jest konfigurowany i wyświetlany, lecz
`app/Services/AiFoundationService.php:344-360` egzekwuje wyłącznie dzienny limit liczby
zadań. Nie znaleziono egzekwowania budżetu miesięcznego.

**Naprawa:** przed zleceniem obliczać wykorzystanie miesiąca, rezerwować szacowany
koszt, rozliczać koszt rzeczywisty i blokować przekroczenie. Dodać alert przy 80% i
100% limitu.

### P1-11. Plik schematu jest jedynym źródłem bazy, ale nie jest śledzony

`database/zrodlo_slowa.sql` zawiera aktualny schemat, lecz Git go nie śledzi. Usunięcie
katalogu roboczego lub nieuważne czyszczenie może pozbawić projekt jedynego pełnego
źródła odtworzenia bazy.

**Naprawa:** po przeglądzie bezpieczeństwa dodać schemat i migracje do kontroli wersji,
automatycznie budować czystą bazę w CI i porównywać schemat wynikowy.

## 7. Problemy P2

### P2-1. Niespójne tłumaczenia i przestarzałe testy

Brakuje pięciu kluczy użytych w `views/wallet/topup.php`:

- `wallet.orders_count_label`;
- `wallet.orders.table.user`;
- `wallet.orders.table.package`;
- `wallet.orders.table.provider`;
- `wallet.orders.default_user`.

Test `zrodlo_sites_json_check.php` nie uwzględnia świadomego prefiksu `pl` i zgłasza
błąd, ale kończy się kodem 0. Test kontekstu językowego szuka dokładnego fragmentu
`<body class="lang-...">`, podczas gdy layout ma też poprawny atrybut
`data-detected-lang`, więc daje wynik fałszywie dodatni. W kontrolerach nadal jest dużo
twardo wpisanych polskich komunikatów i przekierowań tracących prefiks języka.

### P2-2. Cache używa niebezpiecznej i nieatomowej serializacji

`app/Services/CacheService.php:29` i `:79` wywołuje `unserialize()` bez
`allowed_classes => false`. Zapis w `:52` używa bezpośredniego `file_put_contents()`
bez atomowego rename i bez blokady. Uszkodzony lub częściowo zapisany cache może
przerwać żądanie; przy możliwości podmiany pliku istnieje ryzyko object injection.

**Naprawa:** JSON lub bezpieczna serializacja, zapis do pliku tymczasowego i atomowe
rename, walidacja struktury oraz łagodne odrzucenie uszkodzonego wpisu.

### P2-3. Możliwy open redirect

`app/Controllers/ActivityController.php:17-20` ufa polu `back` lub nagłówkowi Referer i
sprawdza tylko, czy tekst zaczyna się od `/`. Adres `//evil.example` przechodzi warunek
i może zostać zinterpretowany jako przekierowanie zewnętrzne.

**Naprawa:** akceptować wyłącznie lokalne ścieżki z dokładnie jednym początkowym `/`,
odrzucać `//`, backslash, znaki sterujące i nietypowe kodowanie.

### P2-4. Brak jawnych nagłówków bezpieczeństwa

Nie znaleziono CSP, HSTS, `X-Content-Type-Options`, `Referrer-Policy` ani ochrony przed
osadzaniem strony w ramce.

**Naprawa:** dodać nagłówki w warstwie serwera lub aplikacji. CSP wdrażać stopniowo,
ponieważ widoki zawierają skrypty i style inline; docelowo użyć nonce.

### P2-5. Niespójne sekrety środowiskowe

Bez ujawniania wartości stwierdzono:

- `FINANCE_HMAC_KEY` jest ustawiony w lokalnym `.env`, ale nie ma go w przykładach;
- `APP_KEY` nie istnieje w `.env` ani w przykładach, więc hashowanie IP używa
  przewidywalnych fallbacków `local`;
- lokalny `PASSWORD_PEPPER` wygląda jak wartość zastępcza;
- lokalna baza ma puste hasło, co może być akceptowalne tylko na odizolowanym
  środowisku developerskim.

**Naprawa:** jedna kompletna lista wymaganych zmiennych, walidator startowy i odmowa
uruchomienia produkcji z brakiem/pustą wartością/placeholderem.

### P2-6. Trigger SQL zawiera uszkodzone komunikaty

W `database/zrodlo_slowa.sql:1496-1499` komunikaty triggera zawierają znaki zastępcze,
mimo że sam plik jest formalnie poprawnym UTF-8. Skrypty finansowe także mają
niejednolite teksty diagnostyczne.

### P2-7. Brak bazowej infrastruktury jakości

- `composer.json` zawiera tylko pustą sekcję `require-dev`;
- `composer.lock` jest niespójny;
- brak PSR-4 w Composerze, test runnera, statycznego analizatora i CI;
- `README.md` opisuje pojedynczą poprawkę językową zamiast instalacji i architektury;
- `AdminController.php` ma ponad tysiąc linii i łączy wiele niezależnych domen;
- `git diff --check` wskazuje dużo białych znaków.

**Naprawa:** PHPUnit/Pest, PHPStan na stopniowo podnoszonym poziomie, CI z MySQL,
Composer PSR-4, standard formatowania i rzeczywisty README operacyjny.

## 8. Mocne strony, które warto zachować

- token CSRF jest losowy i porównywany przez `hash_equals()` dla zwykłych formularzy;
- w przeglądanych przepływach dominują prepared statements;
- tokeny resetu i weryfikacji są przechowywane jako hashe z terminem ważności;
- weryfikator Stripe sprawdza timestamp, HMAC SHA-256 i używa `hash_equals()`;
- upload sprawdza rozszerzenie i MIME, dekoduje obraz, ponownie koduje do WebP,
  używa losowej nazwy, a `public/uploads/.htaccess` wyłącza wykonywanie PHP;
- katalogi `app`, `config`, `database`, `docs`, `scripts`, `storage`, `vendor` i
  `views` są blokowane przez `.htaccess`;
- publiczny Adminer został usunięty;
- zakup artykułu ma unikalność kupujący–artykuł;
- pełny SQL jest schematem bez danych użytkowników;
- nie wykryto brakujących tras, kontrolerów, metod, klas ani widoków.

## 9. Zalecana kolejność napraw

### Etap 0 — zabezpieczenie pracy

1. Utworzyć backup katalogu oraz dump działającej bazy.
2. Zapisać obecne 54 modyfikacje, 77 usunięć i 75 nowych plików na osobnej gałęzi.
3. Ustalić jeden kanoniczny katalog projektu — obecny audyt dotyczy
   `X:\zrodlo-slowa`.
4. Nie uruchamiać jeszcze resetu ani instalatora na jedynej bazie.

### Etap 1 — finanse

1. Naprawić dwupozycyjne księgowanie wypłat i idempotencję.
2. Naprawić `role` kontra `user_role` oraz całą zasadę Maker–Checker.
3. Dodać blokady portfeli, transferów i głowy łańcucha.
4. Wzmocnić HMAC, verifier i audyt błędów.
5. Dopiero potem testować na nowej, jednorazowej bazie testowej.

### Etap 2 — uwierzytelnianie i integracje

1. Wprowadzić middleware per trasa i obsłużyć callbacki zewnętrzne.
2. Zastąpić ręczne JWT poprawną implementacją OIDC/JWKS.
3. Ujednolicić zakończenie logowania zwykłego i OAuth.
4. Utwardzić sesje, rate limiting, reset hasła i 2FA.
5. Uruchomić realny worker pocztowy.

### Etap 3 — baza i wdrożenie

1. Przywrócić migracje i wersję schematu.
2. Naprawić czystą instalację i upgrade.
3. Ustawić jeden vhost na katalog `public` oraz front-controller.
4. Dodać walidację środowiska i nagłówki bezpieczeństwa.

### Etap 4 — workflow i jakość

1. Wprowadzić rewizje opublikowanych artykułów.
2. Naprawić tłumaczenia oraz testy, które dają fałszywe wyniki.
3. Egzekwować miesięczny budżet AI.
4. Dodać PHPUnit/Pest, PHPStan i CI.
5. Uporządkować Composer, README, kodowanie komunikatów i białe znaki.

## 10. Minimalna bramka przed produkcją

Wdrożenie powinno zostać zablokowane, dopóki nie zostaną spełnione wszystkie punkty:

- wszystkie P0 zamknięte i pokryte testami;
- czysta instalacja i upgrade kończą się poprawnie na CI;
- test równoległych operacji nie narusza sald ani łańcucha księgi;
- verifier potwierdza salda, rezerwacje, idempotencję i HMAC;
- prawidłowe callbacki Stripe/Apple działają bez CSRF, a złe podpisy są odrzucane;
- Google/Apple JWT są kryptograficznie weryfikowane;
- sesja po logowaniu otrzymuje nowe ID i bezpieczne cookie;
- e-mail resetu i weryfikacji jest faktycznie dostarczany;
- środowisko produkcyjne odmawia startu z placeholderami sekretów;
- document root wskazuje wyłącznie `public`;
- `composer validate`, lint, testy, statyczna analiza i skan tłumaczeń przechodzą w CI;
- wykonano test odtworzenia bazy z backupu.

## 11. Ocena końcowa

**Stan funkcjonalny:** rozbudowany projekt w trakcie dużej konsolidacji.  
**Stan składni i spójności strukturalnej:** dobry.  
**Stan finansów i integralności transakcji:** krytycznie wymaga poprawek.  
**Stan OAuth i sesji:** wymaga poprawek przed włączeniem.  
**Stan instalacji/upgrade:** obecnie niewiarygodny.  
**Stan gotowości produkcyjnej:** **NIE — do czasu zamknięcia P0 i testów dynamicznych**.

Najlepszy pierwszy pakiet wdrożeniowy to: **ledger wypłat + blokady sald +
Maker–Checker + testy integracyjne finansów**. Dopiero po nim należy przejść do
OAuth/webhooków i instalatora.
