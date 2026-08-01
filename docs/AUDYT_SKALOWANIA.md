# Audyt przygotowania ŹRÓDŁA SŁOWA do skalowania

Data audytu: 2026-07-31

Repozytorium: `X:\zrodlo-slowa`

Gałąź: `main`

Commit bazowy: `e03a353`

Zakres: ETAP 1 — audyt bez migracji, wdrożenia i zmian funkcjonalnych

## 1. Wniosek wykonawczy

Projekt ma dobrą bazę do dalszych prac: własną warstwę `Database`, wersjonowane migracje
MySQL, transakcje, idempotencję części operacji finansowych, testy, analizę statyczną i
działający worker poczty. Nie jest jednak jeszcze gotowy do uruchomienia w wielu
instancjach ani do bezpiecznego wdrożenia na docelowym stosie.

Najważniejsze blokery:

1. wszystkie operacje finansowe są serializowane przez jeden rekord
   `financial_ledger_head`;
2. sesje, cache i uploady są lokalne dla instancji aplikacji;
3. kod i schemat są silnie związane z MySQL;
4. brakuje Docker Compose, PostgreSQL, Valkey, MinIO, health checków i monitoringu;
5. zadania AI są wykonywane synchronicznie w żądaniu HTTP;
6. nie istnieją osobne workery zarobków i AI ani scheduler;
7. część częstych zapytań nie ma właściwych indeksów lub jest wykonywana wielokrotnie
   podczas jednego renderowania;
8. nie ma testów wieloinstancyjnych, współbieżności ani testów obciążeniowych.

Wniosek: kierunek `PostgreSQL + Valkey + S3/MinIO + osobne workery + Docker` jest
właściwy. Należy wdrażać go etapami, zachowując dotychczasową ścieżkę MySQL do czasu,
aż kompletna ścieżka PostgreSQL przejdzie testy zgodności.

## 2. Zasady środowiska lokalnego

Laragon pozostaje całkowicie poza zakresem zmian:

- nie zmieniamy PHP, Apache/Nginx, MySQL ani konfiguracji Laragona;
- Docker nie łączy się z MySQL Laragona;
- Docker nie korzysta z sesji, plików `.env` ani innych ustawień Laragona;
- porty `80`, `443` i `3306` pozostają wolne.

Docelowe mapowania portów hosta:

| Usługa | Host | Kontener / uwagi |
|---|---:|---|
| proxy | `8080` | jedyny publiczny punkt wejścia aplikacji |
| PostgreSQL | `5433` | `5432` |
| Valkey | `6380` | `6379` |
| MinIO Console | `19001` | `9001`; hostowy `9001` pozostaje dla Avid |
| MinIO API | brak portu hosta | `minio:9000` wyłącznie w sieci Compose |
| Mailpit UI | `8025` | `8025` |
| Mailpit SMTP | brak portu hosta | `mailpit:1025` wyłącznie w sieci Compose |
| app-1 / app-2 | brak portów hosta | dostęp tylko z proxy |

Lokalny Docker nie może zawierać tymczasowego MySQL. Oznacza to świadomą korektę
pierwotnej kolejności: fundament Compose może powstać wcześniej, ale pełny test
aplikacji w kontenerach nastąpi dopiero po przygotowaniu działającej ścieżki
PostgreSQL.

## 3. Aktualna architektura

### 3.1. Aplikacja

- PHP 8.3, autorski MVC i renderowanie widoków po stronie serwera;
- front controller: `public/index.php`;
- routing: `app/Core/Router.php`;
- kontrolery: `app/Controllers`;
- logika biznesowa: `app/Services`;
- jedna główna warstwa dostępu do danych: `app/Core/Database.php`;
- konfiguracja przez pliki PHP oraz własny loader `.env`;
- brak kontenera DI; zależności są konstruowane ręcznie.

### 3.2. Baza

- wyłącznie MySQL/MariaDB przez `pdo_mysql`;
- główny schemat: `database/zrodlo_slowa.sql`;
- 74 tabele, 91 kluczy obcych, 27 unikalnych indeksów i około 199 deklaracji
  indeksów;
- 67 użyć `AUTO_INCREMENT`, 23 `TINYINT(1)`, 35 `ENUM`, 214 `UNSIGNED`,
  26 kolumn JSON i 151 kolumn `DATETIME`;
- jeden trigger pilnujący nieujemnych sald portfela;
- sześć wersjonowanych migracji MySQL;
- `MigrationService` zapisuje sumy kontrolne i status zastosowania migracji.

### 3.3. Stan współdzielony

- sesje PHP: lokalne pliki w `storage/sessions` albo katalogu tymczasowym;
- cache: lokalne pliki JSON w `storage/cache/site`;
- kursy walut: lokalny plik `storage/cache/currency_rates_nbp.json`;
- JWKS OIDC: lokalny cache plikowy;
- uploady artykułów i avatarów: `public/uploads`;
- logi i raporty skryptów: lokalny `storage/logs`.

### 3.4. Zadania w tle

- istnieje baza-kolejka e-mail oraz działający `scripts/mail_worker.php`;
- są retry, leasing i status końcowy `failed`;
- zadania AI mają rekordy `ai_jobs`, ale wywołania API są wykonywane synchronicznie
  w procesie HTTP;
- zarobki są naliczane w procesie HTTP;
- zadania cykliczne istnieją jako osobne skrypty, ale nie ma wspólnego schedulera.

### 3.5. Infrastruktura

Brak:

- `compose.yaml`;
- `Dockerfile`;
- `.dockerignore`;
- katalogu `docker/`;
- proxy/load balancera dla dwóch instancji;
- PostgreSQL, Valkey, MinIO i Mailpit;
- endpointów liveness/readiness;
- podstawowych metryk i ustrukturyzowanych logów kontenerowych.

## 4. Mocne strony do zachowania

1. Dostęp do bazy jest w większości skupiony w `Database`; bezpośredni PDO poza tą
   klasą występuje głównie w instalatorze i teście instalacji.
2. Krytyczne operacje używają transakcji oraz `FOR UPDATE`.
3. `wallet_transactions.idempotency_key` ma unikalny indeks.
4. Operacja o istniejącym kluczu idempotencji jest porównywana z pierwotnymi
   parametrami.
5. Księga ma podpisy HMAC i narzędzie weryfikujące.
6. Poczta ma trwałą kolejkę, retry z backoffem i odzyskiwanie wygasłych lease'ów.
7. Sesja ma `HttpOnly`, `SameSite`, opcjonalne `Secure`, rotację ID i
   `session_version`.
8. Jest walidator środowiska i ochrona przed przykładowymi sekretami.
9. Jest PHPUnit, PHPStan oraz workflow CI.
10. Kod ma już mechanizmy budżetu AI, audytu, antyfraudu i maker-checker.

Te elementy należy przenieść do nowej architektury, a nie zastępować uproszczeniami.

## 5. Ryzyka i blokery

### P0-1. Globalna blokada całej księgi

`FinancialService::postTransaction()` blokuje:

```sql
SELECT ... FROM financial_ledger_head WHERE id=1 FOR UPDATE
```

Każde naliczenie, wypłata, transfer i korekta czeka na ten sam rekord. Zapewnia to
obecnie kolejność globalnego łańcucha HMAC, ale tworzy pojedyncze wąskie gardło i
łamie wymaganie niezależnego skalowania użytkowników oraz workerów.

Rekomendacja:

- trwała tabela operacji z unikalnym kluczem idempotencji;
- blokowanie wyłącznie portfeli objętych operacją;
- przy transferze blokowanie portfeli zawsze w tej samej kolejności;
- per-portfelowy lub per-kontowy łańcuch audytowy;
- okresowy globalny anchor/Merkle root generowany poza gorącą ścieżką;
- potwierdzenie użytkownikowi dopiero po `COMMIT`.

Nie należy usuwać obecnego globalnego łańcucha, dopóki nowy model nie przejdzie
testu zgodności historii i sald.

### P0-2. Niepełna idempotencja nagród przy współbieżności

`TalentService` najpierw sprawdza `activity_reward_logs`, potem księguje i dopiero
na końcu dodaje log oraz powiadomienie. Unikalny klucz transakcji chroni saldo przed
podwójnym zaksięgowaniem, ale tabela logów nie ma unikalnego ograniczenia dla
`user_id + activity_type + reference_type + reference_id`.

Dwa równoległe żądania mogą:

- oba przejść kontrolę istnienia i limitu dziennego;
- otrzymać ten sam wynik idempotentnej transakcji;
- utworzyć zduplikowane logi i powiadomienia;
- przekroczyć limit dzienny na granicy limitu.

Rekomendacja: osobny trwały rekord `earning_operation`/`reward_claim` z unikalnym
kluczem idempotencji, stanami, lease'em, retry i atomowym księgowaniem. Limity muszą
być egzekwowane transakcyjnie, nie przez sam wcześniejszy `COUNT(*)`.

### P0-3. Lokalny stan uniemożliwia wiele instancji

- sesja użytkownika istnieje tylko na dysku jednej instancji;
- invalidacja cache działa tylko w instancji obsługującej zapis;
- `flushGroup()` skanuje lokalne pliki;
- kursy NBP i JWKS są osobne na każdej instancji;
- upload zapisany przez `app-1` nie istnieje w `app-2`.

Skutki: konieczność sticky sessions, niespójny cache, losowe wylogowania oraz brak
plików po przełączeniu instancji.

Rekomendacja:

- sesje i cache w Valkey;
- brak awaryjnego powrotu sesji do lokalnych plików w trybie wieloinstancyjnym;
- publiczne odczyty mogą ominąć cache i czytać PostgreSQL;
- awaria Valkey nie może zmienić sald ani wyniku operacji finansowej;
- pliki wyłącznie przez abstrakcję Object Storage.

### P0-4. Silne związanie z MySQL

Warstwa połączenia buduje wyłącznie DSN `mysql:` i używa
`PDO::MYSQL_ATTR_INIT_COMMAND`. Instalator tworzy bazę MySQL i odpytuje
`information_schema` w składni MySQL.

W kodzie PHP i skryptach wykryto między innymi:

| Konstrukcja | Liczba użyć |
|---|---:|
| `NOW()` | 305 |
| `CURDATE()` | 5 |
| `DATE_SUB()` | 16 |
| `DATE_ADD()` | 6 |
| `DATE_FORMAT()` | 1 |
| `ON DUPLICATE KEY UPDATE` | 12 |
| `INSERT IGNORE` | 2 |
| backticki identyfikatorów | 28 |
| sprawdzanie SQLSTATE `23000` | 2 |

Dodatkowo SQL często używa podwójnych cudzysłowów dla wartości tekstowych. W
PostgreSQL podwójny cudzysłów oznacza identyfikator, nie literał. `lastInsertId()`
powinno zostać zastąpione bezpiecznym `INSERT ... RETURNING id`.

Rekomendacja:

- jawny `DB_DRIVER=pgsql`;
- przenośna warstwa połączenia i transakcji;
- zapytania PostgreSQL z pojedynczymi cudzysłowami;
- `ON CONFLICT`, `BOOLEAN`, `TIMESTAMPTZ`, `JSONB`, identity i `RETURNING`;
- mapowanie kodu błędu unikalności PostgreSQL `23505`;
- osobny, wersjonowany baseline PostgreSQL;
- zachowanie istniejących plików MySQL do pełnej akceptacji migracji.

### P1-1. Migracje nie są gotowe na wiele instancji

- instrukcje migracji są wykonywane pojedynczo, bez transakcji obejmującej całą
  migrację;
- nie ma blokady migratora chroniącej przed równoległym startem wielu instancji;
- status `running` może pozostać po awarii;
- część migracji MySQL używa dynamicznego SQL, `PREPARE`, `DELIMITER`, `ALTER ...
  AFTER`, `MODIFY COLUMN` i triggera MySQL;
- `scripts/install_fresh.php` wywołuje `install(true)` bez wymaganego tokenu
  `InstallService::FRESH_CONFIRMATION`, więc ścieżka jest obecnie niespójna.

Rekomendacja: osobny jednorazowy proces migratora, PostgreSQL advisory lock,
transakcyjne migracje tam, gdzie to możliwe, oraz brak automatycznego tworzenia bazy
przez proces WWW.

### P1-2. AI blokuje HTTP i może generować powtórne koszty

`TranslationAiService` oraz tłumaczenie banera wykonują żądanie OpenAI bezpośrednio
z kontrolera. Timeout wynosi do 90 sekund. `input_hash` nie jest unikalnym kluczem
idempotencji, więc ponowienie żądania może utworzyć drugie płatne wywołanie.

Rekomendacja:

- AI pozostaje funkcją wyłącznie administracji i redakcji z nadanym uprawnieniem;
  zwykły użytkownik nie widzi akcji AI i nie ma publicznego endpointu pozwalającego
  bezpośrednio utworzyć zadanie;
- autoryzacja roli i szczegółowego uprawnienia jest sprawdzana zarówno przy
  tworzeniu zadania, jak i ponownie przez `worker-ai` przed wykonaniem;
- kontroler zapisuje trwałe zadanie i zwraca identyfikator;
- `worker-ai` pobiera je atomowo;
- unikalny klucz idempotencji obejmuje typ zadania, zasób, wersję wejścia i jawny
  identyfikator żądania;
- każde utworzenie, odrzucenie, wykonanie i rozliczenie zadania trafia do audytu
  wraz z aktorem, rolą, zasobem i wynikiem kontroli;
- timeout, ograniczone retry, klasyfikacja błędów i DLQ;
- budżet rezerwowany przed wywołaniem, rozliczany po wyniku, zwalniany po błędzie;
- brak automatycznego retry błędów, które mogą podwójnie naliczyć koszt bez
  sprawdzenia stanu dostawcy.

### P1-3. Worker poczty wykonuje zbyt wiele zapytań

Obecny `claimBatch()` wykonuje:

1. odzyskanie lease'ów;
2. wybór kandydatów;
3. osobny `UPDATE` dla każdego kandydata;
4. ponowny `SELECT` całej paczki.

Przy wielu workerach powoduje to dodatkowe round-trip'y i konkurencję o te same
rekordy. Da się to zastąpić jednym atomowym `FOR UPDATE SKIP LOCKED` +
`UPDATE ... RETURNING`.

Proces odpytuje pustą kolejkę co kilka sekund. Docelowo powinien stosować narastający
backoff albo sygnał z Valkey, zachowując PostgreSQL jako trwałe źródło zadania.

Wysyłka e-mail jest z natury at-least-once: awaria po przyjęciu wiadomości przez SMTP,
ale przed `markSent`, może dać duplikat. Potrzebny jest stabilny `Message-ID`,
idempotency key/outbox i jawnie opisana semantyka.

### P1-4. Brak workerów zarobków, AI i schedulera

Naliczanie nagród jest częścią żądania HTTP. Skrypty antyfraudu, kursów, sitemap i
porządkowania nie mają jednego mechanizmu planowania, lease'ów, retry i raportowania.

Rekomendacja: wspólny model trwałych zadań w PostgreSQL oraz osobne procesy:

- `worker-earnings`;
- `worker-email`;
- `worker-ai`;
- `scheduler`.

Valkey może budzić procesy i ograniczać koszt pollingu, ale nie może być jedynym
źródłem trwałych zadań finansowych.

### P1-5. Kosztowne zapytania w gorącej ścieżce

Najważniejsze przypadki:

- `App::boot()` odpytuje `users.session_version` przy każdym uwierzytelnionym
  żądaniu;
- `BaseController::view()` osobno pobiera walutę użytkownika oraz avatar/nazwę;
- każdy render pobiera ustawienia płatności z tabeli `settings`;
- każdy render sprawdza nieprzeczytane powiadomienia i czasem wykonuje dodatkowy
  `UPDATE`;
- listy artykułów wykonują po dwa skorelowane podzapytania do `media` na każdy
  artykuł;
- tabela `media` nie ma indeksu `(article_id, id)`;
- rate limiting logowania filtruje po `email` lub `ip_hash`, ale indeksy obejmują
  tylko `(result, created_at)` oraz `(user_id, created_at)`;
- zapytania AI używają `DATE(created_at)=...`, co utrudnia użycie indeksu;
- skan antyfraudowy łączy kilka relacji jeden-do-wielu i liczy `COUNT(DISTINCT)`,
  co może tworzyć duże zbiory pośrednie.

Rekomendacje:

- request-scoped `CurrentUserContext`, jedno pobranie danych użytkownika;
- krótki cache ustawień z invalidacją po zapisie;
- współdzielony cache `session_version` z bezpiecznym fallbackiem do PostgreSQL;
- pobranie i oznaczenie powiadomień jednym CTE/`UPDATE ... RETURNING`;
- indeks `media(article_id, id DESC)` i jedno złączenie/lateral zamiast dwóch
  podzapytań;
- indeksy `auth_login_events(email, result, created_at)` oraz
  `(ip_hash, result, created_at)`;
- przedziały czasu zamiast funkcji na indeksowanej kolumnie;
- preagregacja ciężkich statystyk przez scheduler;
- potwierdzanie indeksów przez `EXPLAIN (ANALYZE, BUFFERS)` na danych testowych.

### P1-6. Cache zewnętrznych danych może wywołać API z procesu WWW

Przy braku pliku kursów `CurrencyRateService::loadCachedRates()` próbuje pobrać NBP
w trakcie renderowania strony. Każda nowa instancja lub restart może wykonać osobne
żądanie. JWKS OIDC również jest cache'owany osobno na każdej instancji.

Rekomendacja:

- kursy odświeża tylko scheduler;
- proces WWW czyta ostatnią poprawną wartość z PostgreSQL/Valkey;
- wspólny cache JWKS z TTL i ochroną przed stampede;
- zewnętrzne API nigdy nie może być niekontrolowanym skutkiem zwykłego renderowania.

### P1-7. Konfiguracja nie jest jeszcze kontenerowa

Loader `.env` zapisuje wartości do `$_ENV` i nadaje plikowi `.env` pierwszeństwo
przed zmiennymi procesu. W chmurze i Dockerze powinno być odwrotnie: zmienna
wstrzyknięta przez runtime ma pierwszeństwo, a lokalny plik jest tylko wygodnym
fallbackiem.

Brakuje:

- `.env.local.example`;
- `.env.test.example`;
- `.env.production.example`;
- zmiennych PostgreSQL, Valkey i S3;
- identyfikatora instancji;
- limitów połączeń, timeoutów i parametrów workerów.

`.gitignore` nie obejmuje `.env.local`, `.env.test`, `.env.production`,
`storage/logs` ani `public/uploads`.

### P1-8. Repozytorium zawiera wygenerowane dane

Śledzone są:

- pliki w `public/uploads/articles` i `public/uploads/avatars`;
- raporty JSON i debug HTML w `storage/logs`.

Mogą one zawierać dane użytkowników, dane finansowe lub artefakty lokalnej pracy.
Nie zostały usunięte podczas audytu. Najpierw należy ustalić, które pliki są
fixtures/assets, a które danymi runtime, następnie przenieść potrzebne dane do
MinIO i usunąć zbędne artefakty w osobnym, kontrolowanym etapie.

Skan nazwanych zmiennych nie wykazał aktywnego sekretu w śledzonych plikach; wpis
`STRIPE_WEBHOOK_SECRET=whsec_...` w widoku administracyjnym jest przykładem.

### P1-9. Brak health checków i obserwowalności

Nie ma endpointów liveness/readiness, metryk ani request/correlation ID.
`ErrorReporter` korzysta z tradycyjnego logowania, ale nie ma jednolitego JSON do
stdout/stderr.

Minimum:

- `/health/live` bez zależności;
- `/health/ready` z krótkimi timeoutami do PostgreSQL i opcjonalnie usług
  wymaganych przez daną rolę kontenera;
- identyfikator żądania i instancji w logach;
- licznik błędów, czasu odpowiedzi, długości kolejek i retry;
- limity retencji logów;
- bez kosztownych zapytań diagnostycznych przy każdym health checku.

### P1-10. Brak testów wymaganych do akceptacji

Obecne testy obejmują logikę jednostkową oraz integrację z MySQL, ale nie ma:

- testu dwóch instancji bez sticky sessions;
- testu wspólnej sesji Valkey;
- testu MinIO/S3;
- testów wielu workerów;
- testu podwójnego kliknięcia i równoległego naliczenia;
- testu awarii przed i po `COMMIT`;
- testu wyłączenia jednej instancji;
- testów PostgreSQL od czystej bazy;
- testów k6 i udokumentowanych pomiarów.

## 6. Jawna polityka cache

Proponowany format kluczy:

```text
zs:{environment}:v1:{namespace}:{resource}:{identifier}:{variant}
```

Zasady:

| Dane | Cache | TTL / invalidacja |
|---|---|---|
| menu, języki, publiczne ustawienia | tak | 5–60 min, wersja namespace po zapisie |
| listy i metadane artykułów | tak | 1–10 min, invalidacja po publikacji |
| banner publiczny | tak | 5–60 min, invalidacja po edycji |
| JWKS OIDC | tak | zgodnie z nagłówkami/krótki TTL, single-flight |
| kursy walut | tak | scheduler, ostatnia poprawna wartość |
| profil do nawigacji | krótko | 30–120 s, invalidacja po zmianie |
| role/uprawnienia | ostrożnie | krótki TTL + natychmiastowa invalidacja |
| saldo, historia, wypłata | nie do decyzji biznesowej | odczyt PostgreSQL |
| idempotencja finansowa | nie | unikalne ograniczenie PostgreSQL |

Mechanizmy:

- cache-aside;
- jawny TTL;
- wersjonowanie namespace zamiast skanowania wszystkich kluczy;
- krótkie locki Valkey i randomized TTL przeciw stampede;
- lokalny request cache, aby nie pobierać tego samego rekordu kilka razy;
- awaria cache powoduje wolniejszy odczyt, nie zmianę poprawności;
- żadnych sald i ostatecznych statusów finansowych wyłącznie w Valkey.

## 7. Docelowy model kolejek

Najbezpieczniejszy i nadal prosty kosztowo model:

1. PostgreSQL przechowuje trwałe zadanie, idempotency key, payload, status,
   `available_at`, lease, liczbę prób i błąd.
2. Zapis zadania i zmiana domenowa używają jednej transakcji/outbox.
3. Valkey wysyła tani sygnał, że pojawiła się praca, albo utrzymuje kolejkę
   przyspieszającą.
4. Worker zawsze potwierdza stan zadania w PostgreSQL.
5. `FOR UPDATE SKIP LOCKED` pozwala wielu workerom pobierać różne zadania.
6. Po wyczerpaniu prób zadanie otrzymuje status DLQ/`failed_terminal`.
7. Retry nie może ponownie wykonać skutku chronionego idempotency key.

To eliminuje utratę zadań finansowych przy restarcie Valkey bez dokładania osobnego
brokera wiadomości na początkowym etapie.

## 8. Docelowy model plików

Należy wprowadzić interfejs magazynu obiektów, niezależny od dostawcy:

- `put`, `read/stream`, `delete`, `exists`, URL publiczny/podpisany;
- MinIO w development, zgodne S3 w produkcji;
- konfiguracja wyłącznie przez `S3_ENDPOINT`, `S3_REGION`, `S3_BUCKET`,
  `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_PATH_STYLE`;
- klucze obiektów generowane losowo, bez kolizji między instancjami;
- MIME wykrywane z treści, limit rozmiaru i bezpieczne rozszerzenie;
- prywatność bucketu domyślna; jawna decyzja dla zasobów publicznych;
- zapis rekordu DB dopiero po udanym uploadzie albo mechanizm kompensacji;
- usuwanie odporne na retry;
- okresowy reconciliation job dla sierot;
- migracja istniejących `public/uploads` przez manifest i sumy kontrolne.

Avatar w `AccountController` obecnie omija `UploadService`; oba przepływy muszą
korzystać z tej samej abstrakcji.

## 9. Lista plików do zmiany

### Infrastruktura — nowe pliki

- `compose.yaml`;
- `Dockerfile`;
- `.dockerignore`;
- `.env.local.example`;
- `.env.test.example`;
- `.env.production.example`;
- `docker/proxy/*`;
- `docker/php/*`;
- `docker/minio/*` lub skrypt inicjalizacji bucketu;
- `scripts/docker-*`, skrypt migratora i skrypty health/smoke;
- `tests/Load/*`.

### Core i konfiguracja

- `app/Core/Database.php`;
- `app/Core/App.php`;
- `app/Core/Session.php`;
- `app/Core/bootstrap.php`;
- `config/database.php`;
- `config/app.php`;
- `config/uploads.php`;
- `config/ai.php`;
- `composer.json`, `composer.lock`;
- `.env.example`, `.env.install.example`, `.gitignore`;
- `public/index.php`.

### Migracje i instalacja

- nowy katalog/baseline PostgreSQL;
- `app/Services/MigrationService.php`;
- `app/Services/SqlScriptRunner.php`;
- `app/Services/InstallService.php`;
- `app/Services/EnvironmentValidator.php`;
- `scripts/install.php`;
- `scripts/install_fresh.php`;
- `scripts/migrate.php`;
- `scripts/reset_database.php`;
- `tests/Integration/FreshInstallTest.php`;
- `.github/workflows/quality.yml`.

Istniejące `database/zrodlo_slowa.sql` i `database/migrations/*.sql` pozostają do
czasu pełnego przejścia testów PostgreSQL.

### SQL aplikacji

Do przeglądu i konwersji są wszystkie pliki wykonujące SQL, w szczególności:

- `app/Services/FinancialService.php`;
- `app/Services/LedgerService.php`;
- `app/Services/TalentService.php`;
- `app/Services/ActivityService.php`;
- `app/Services/ArticleService.php`;
- `app/Services/ArticleTranslationService.php`;
- `app/Services/ArticleEconomyService.php`;
- `app/Services/AuthService.php`;
- `app/Services/AuthSecurityService.php`;
- `app/Services/AiBudgetService.php`;
- `app/Services/AiFoundationService.php`;
- `app/Services/MainBannerService.php`;
- `app/Services/MailService.php`;
- `app/Services/PaymentService.php`;
- `app/Services/PaymentOrderService.php`;
- `app/Services/PaymentGatewayEventService.php`;
- `app/Services/PayoutService.php`;
- `app/Services/WalletTransferService.php`;
- `app/Services/WalletTopupService.php`;
- `app/Services/CampaignService.php`;
- `app/Services/SurveyService.php`;
- `app/Services/FraudGuardService.php`;
- `app/Services/UserService.php`;
- `app/Services/UserDeletionService.php`;
- `app/Services/OAuthAccountService.php`;
- `app/Services/CategoryService.php`;
- `app/Services/RoleService.php`;
- `app/Controllers/AdminController.php`;
- `app/Controllers/FinanceController.php`;
- `app/Controllers/HomeController.php`;
- `app/Controllers/AdminArticleTranslationController.php`;
- skrypty CLI używające `Database`.

### Sesje, cache i optymalizacja

- `app/Services/CacheService.php`;
- `app/Services/CurrencyRateService.php`;
- `app/Services/OidcTokenVerifier.php`;
- `app/Services/PaymentRuntimeConfigService.php`;
- `app/Controllers/BaseController.php`;
- kontrolery invalidujące cache po zapisie;
- nowe adaptery Valkey, locków, rate limitingu i request cache.

### Pliki

- `app/Services/UploadService.php`;
- `app/Controllers/AccountController.php`;
- `app/Controllers/AuthorController.php`;
- `app/Controllers/AdminController.php`;
- `scripts/normalize_webp_upload_names.php`;
- zapytania i schemat tabeli `media`;
- widoki/helpery budujące URL uploadu.

### Workery

- `app/Services/MailService.php`;
- `app/Services/MailQueueWorker.php`;
- `scripts/mail_worker.php`;
- `app/Services/TranslationAiService.php`;
- `app/Services/OpenAiClient.php`;
- `app/Services/AiBudgetService.php`;
- `app/Services/ActivityService.php`;
- `app/Services/TalentService.php`;
- nowe klasy trwałych zadań, outbox, lease, retry i DLQ;
- nowe entrypointy `worker-earnings`, `worker-ai`, `scheduler`.

### Testy i dokumentacja

- testy integracyjne wymagające MySQL;
- nowe testy PostgreSQL, Valkey, MinIO, workerów i dwóch instancji;
- `README.md`;
- dokumenty wymagane w dyspozycji skalowania.

## 10. Zalecana kolejność wdrożenia

### Etap 1 — audyt

Ten dokument. Bez zmian funkcjonalnych.

### Etap 2 — izolowany fundament Docker bez MySQL

- przypięte wersje obrazów;
- PostgreSQL, Valkey, MinIO, Mailpit i sieci;
- obraz aplikacji możliwy do użycia przez WWW i workery;
- proxy oraz health checki;
- żadnego połączenia z Laragonem;
- `docker compose config` i test kolizji portów.

Na tym etapie nie wolno deklarować pełnej funkcjonalności aplikacji, dopóki ścieżka
PostgreSQL nie jest gotowa.

### Etap 3 — PostgreSQL

- przenośna warstwa DB;
- baseline i migracje PostgreSQL;
- konwersja zapytań;
- test od pustej bazy;
- test migracji danych w kontrolowanym środowisku;
- porównanie liczności, sum finansowych i relacji;
- PostgreSQL staje się bazą Docker, MySQL Laragona pozostaje nietknięty.

### Etap 4 — model finansowy i trwałe zadania

Przed uruchomieniem workerów zarobków trzeba usunąć globalne wąskie gardło,
wprowadzić pełną idempotencję i testy współbieżności.

### Etap 5 — Valkey

- sesje;
- cache;
- rate limiting;
- krótkie locki i sygnały kolejek;
- test bez sticky sessions;
- poprawne zachowanie przy niedostępnym Valkey.

### Etap 6 — MinIO/S3

- wspólna abstrakcja;
- upload, odczyt, usuwanie;
- migracja istniejących plików;
- brak zapisu runtime w `public/uploads`.

### Etap 7 — workery i scheduler

- earnings;
- e-mail;
- AI wyłącznie dla zadań administracyjnych i redakcyjnych (np. tłumaczenia),
  z ponowną kontrolą uprawnień w workerze, audytem, limitem kosztów i idempotencją;
- zadania cykliczne;
- retry, lease, DLQ, backoff i metryki.
- osobne kolejki i jednostki wdrożeniowe zgodne z
  `docs/ARCHITEKTURA_WORKEROW.md`;
- limity CPU, RAM, połączeń DB i równoległości oraz najniższy priorytet dla AI;
- test, że zatrzymanie albo przeniesienie `worker-ai` nie wpływa na HTTP,
  logowanie, artykuły, salda i zarobki.

### Etap 8 — dwie instancje i awarie

- sesja między `app-1` i `app-2`;
- pliki i cache wspólne;
- wyłączenie jednej instancji;
- dwa workery tej samej roli;
- awaria przed i po `COMMIT`.

### Etap 9 — pomiar wydajności

- wdrożony k6 2.1.0 przypięty do digestu obrazu;
- izolowane dane dla artykułu i sześciu użytkowników z automatycznym cleanupem;
- scenariusze: artykuły, logowanie, konto, saldo, naliczenia, ten sam użytkownik,
  różni użytkownicy, retry i awaria workera;
- osobny profil Compose sprawdza wzrost z dwóch do trzech instancji bez publikacji
  dodatkowych portów hosta;
- progi oceniają błędy, kontrole i p95, a rozdział ruchu jest wymagany na każdą
  oczekiwaną instancję;
- po naliczeniach sprawdzane są dokładne salda, pojedyncze transakcje,
  idempotencja, historia retry i integralność księgi per-portfel;
- wyniki są lokalnym pomiarem akceptacyjnym, nie deklaracją wydajności produkcyjnej;
  szczegóły i ostatni pomiar: `docs/TESTY_OBCIAZENIOWE.md`.

### Etap 10 — dokumentacja i sprzątanie

- dodane instrukcje architektury, finansów/idempotencji, skalowania oraz neutralny
  wobec dostawcy plan chmurowy;
- ujednolicone instrukcje lokalne, testowe i operacyjne;
- zachowany archiwalny łańcuch księgi; usunięcie jest możliwe wyłącznie po ponownym
  teście zgodności wszystkich sald i historii oraz zatwierdzeniu retencji;
- przygotowane interfejsy pod późniejsze KMS/Secret Manager, SIEM, WAF, MFA/passkeys
  i automatyczne uzgodnienia, bez wdrażania tych usług;
- brak zmian Laragona i brak wdrożenia do chmury w ramach tych prac.

## 11. Bramka akceptacyjna każdego etapu

Każdy etap powinien kończyć się:

1. małym, logicznym zakresem zmian;
2. testami jednostkowymi i analizą statyczną;
3. właściwymi testami integracyjnymi w izolowanym Dockerze;
4. kontrolą `git diff` i braku sekretów;
5. aktualizacją dokumentacji;
6. osobnym logicznym commitem;
7. brakiem zmian w Laragonie i brakiem wdrożenia do chmury.

## 12. Walidacja wykonana w ETAPIE 1

Wykonano:

- `git status`, identyfikację gałęzi i ostatnich commitów;
- inwentaryzację kodu, schematu, migracji, sesji, cache, uploadów, workerów,
  finansów, konfiguracji i testów;
- PHPUnit — wyłącznie suite jednostkowy: **22 testy, 70 asercji, wynik OK**;
- PHPStan: **brak błędów**;
- kontrolę ignorowania `.env` i artefaktów runtime;
- bezpieczny skan nazwanych przypisań sekretów w plikach śledzonych.

Nie wykonano:

- testów integracyjnych, ponieważ obecnie używają MySQL Laragona;
- migracji;
- resetu bazy;
- uruchomienia Docker Compose;
- testów wydajności;
- żadnego wdrożenia.

## 13. Decyzja po audycie

Można rozpocząć ETAP 2, pod warunkiem zachowania izolacji Laragona i przyjęcia, że:

- Docker od początku używa wyłącznie PostgreSQL;
- PostgreSQL przechowuje dane trwałe i krytyczne;
- Valkey przyspiesza, ale nie zastępuje trwałości finansowej;
- MinIO/S3 zastępuje lokalne uploady;
- globalny rekord księgi nie pozostaje docelowym mechanizmem synchronizacji;
- nie dodajemy usług ani zapytań bez mierzalnej potrzeby;
- bezpieczeństwo i poprawność finansowa mają pierwszeństwo przed pozorną
  optymalizacją.
