# Przywrócenie treści administratora i naprawa linków

Data zakończenia: 1 sierpnia 2026 r.

Repozytorium: `X:\zrodlo-slowa`, gałąź `main`

Zakres: lokalne środowisko Docker, bez wdrożenia do chmury

## Wynik

Selektywne przywrócenie zakończyło się powodzeniem. PostgreSQL zawiera jednego
aktywnego użytkownika biznesowego — `Paweł Zastrzeżyński` (`users.id=4`) — jego jeden
portfel oraz wszystkie wymagane treści. Wszystkie 11 artykułów ma `author_id=4`.
Treści, tłumaczenia, rewizje i media mają liczby zgodne ze źródłowym MySQL.

Serwis działa pod `http://localhost:8080` na dwóch instancjach aplikacji. Docker nie
zajmuje portów Laragona `80`, `443` ani `3306` i nie korzysta z jego MySQL, sesji ani
konfiguracji. Używane porty hosta to `8080` (strona), `5433` (PostgreSQL), `6380`
(Valkey), `19001` (konsola MinIO; wybrany port alternatywny) oraz `8025` (Mailpit).

## Przyczyny problemów

Brak treści wynikał z uruchomienia świeżego PostgreSQL: schemat został utworzony, ale
dane źródłowego MySQL Laragona nie były importowane. Istniejący skrypt porównawczy
pełnił wyłącznie funkcję kontrolną.

Port `8080` był tracony podczas przekazywania hosta przez Nginx i generowania
bezwzględnych adresów z `HTTP_HOST`. Adresy prowadziły wtedy do `localhost:80`, czyli
IIS. Proxy przekazuje teraz host wraz z portem (`$http_host`), aplikacja ma centralne
źródło originu, a zwykłe linki wewnętrzne są względne. Nagłówki klienta są
nadpisywane na proxy.

## Administrator

Jednoznacznie zidentyfikowany rekord źródłowy:

| Pole | Źródło | Cel |
|---|---|---|
| `users.id` | 4 | 4 |
| `legacy_id` | 8 | 8 |
| login aplikacyjny | brak osobnego pola | `katon` |
| e-mail | `admin@100pl.pl` | bez zmian |
| nazwa | `Paweł Zastrzeżyński` | bez zmian |
| status | `deleted` | `active` — zatwierdzona transformacja |
| role | `author` | `admin`, `author` |
| hash hasła | istniejący | zachowany podczas migracji, następnie obrócony na jawne polecenie użytkownika |

Nie znaleziono drugiego podobnego konta. Pozostałych dziewięciu użytkowników MySQL
ani dwóch wcześniejszych kont instalacyjnych PostgreSQL nie zachowano. Nie
utworzono kont technicznych dawnych autorów. Szczegółowe uprawnienia administratora
wynikają z katalogu uprawnień roli `admin`; funkcje AI nadal wymagają uprawnień
administracyjnych/redakcyjnych i działają w osobnym workerze.

Logowanie obsługuje teraz dwa równoważne identyfikatory: nazwę logowania `katon` oraz
zachowany e-mail `admin@100pl.pl`. E-mail nadal służy do kontaktu i odzyskiwania
hasła. Zmiana danych uwierzytelniających unieważniła wcześniejsze sesje i utworzyła
ustrukturyzowane zdarzenie audytowe `admin.credentials_rotated` bez zapisywania hasła.

## Manifest i zakres importu

Manifest klasyfikuje wszystkie 75 tabel źródłowych i odmawia pracy po wykryciu
nieopisanej tabeli.

Importowane lub transformowane tabele i filtry:

- `users` i `user_profiles`: wyłącznie `user_id=4`; hash hasła i identyfikatory
  zachowane w czasie migracji, status aktywowany zgodnie z dyspozycją; późniejsza
  rotacja hasła i dodanie loginu zostały wykonane na osobne polecenie użytkownika;
- `user_roles`: odbudowane wyłącznie jako `admin` i `author` dla użytkownika 4;
- `articles`: wszystkie 11 rekordów, zawsze `author_id=4`; oryginalny autor zapisany
  w niezmiennym śladzie `selected_migration_article_authors`;
- `article_translations`, `article_translation_versions`, `article_versions`:
  wszystkie rekordy dotyczące importowanych artykułów, bez filtrowania języków i
  statusów redakcyjnych;
- `categories`, `category_translations`, `article_categories`: cały potrzebny graf
  kategorii i relacji;
- `article_events`: 117 zdarzeń treści z bezpieczną transformacją referencji;
- `media`: pięć używanych rekordów; ścieżki lokalne zamienione na zweryfikowane
  referencje obiektowe;
- `main_banners`, `main_banner_translations`: jeden banner i jego sześć tłumaczeń;
- `surveys`, `survey_questions`, `campaigns`: wyłącznie struktura prezentacyjna
  (1 ankieta, 0 pytań, 0 kampanii), bez odpowiedzi użytkowników;
- `settings`: tylko `site.name`, `site.tagline`, `premium_access_hours` i
  `publisher_fee_percent`;
- `wallets`, `wallet_transactions`, `financial_ledger_head`: tylko zakres
  administratora, odbudowany według zatwierdzonego otwarcia łańcucha per-portfel.

Tabele pominięte i powód:

- runtime, uwierzytelnianie i dane tymczasowe: `sessions`, `auth_login_events`,
  `email_verification_tokens`, `password_resets`, `mail_queue`,
  `user_oauth_accounts`, `schema_migrations`;
- zachowania i dane innych użytkowników: `activity_bonus_notifications`,
  `activity_reward_logs`, `activity_reward_rules`, `ad_clicks`, `ad_views`,
  `article_access_grants`, `article_purchases`, `article_reads`, `article_supports`,
  `campaign_events`, `sponsored_article_reads`, `survey_reports`,
  `survey_response_items`, `survey_responses`, `user_activity_events`,
  `user_delete_reports`;
- płatności i finanse poza portfelem administratora: `donation_campaigns`,
  `donations`, `finance_balance_reports`, `finance_import_errors`,
  `finance_import_runs`, `finance_legacy_events`,
  `finance_legacy_wallet_snapshots`, `financial_approvals`,
  `financial_audit_log`, `legacy_edd_product_map`, `payment_events`,
  `payment_gateway_events`, `payment_items`, `payment_orders`,
  `payment_webhook_events`, `payments`, `payout_methods`, `payout_status_logs`,
  `payouts`, `platform_revenues`, `ppv_events`, `wallet_price_packages`,
  `wallet_topup_packages`, `wallet_transfers`;
- dane administracyjne, AI i niezwiązane z prezentacją treści: `admin_audit_logs`,
  `ai_budget_periods`, `ai_job_events`, `ai_jobs`, `ai_prompt_templates`,
  `fraud_events`, `live_events`.

Nowe tabele docelowe dla kolejek, uzgadniania, anchorów i audytu migracji pozostają
`target_only`; nie są kopiami danych MySQL.

## Zgodność treści i tłumaczeń

| Zakres | MySQL przed | PostgreSQL po |
|---|---:|---:|
| artykuły | 11 | 11 |
| artykuły opublikowane | 9 | 9 |
| artykuły przypisane administratorowi | 0 | 11 |
| tłumaczenia artykułów | 31 | 31 |
| rewizje artykułów | 37 | 37 |
| rewizje tłumaczeń | 37 | 37 |
| kategorie | 6 | 6 |
| relacje artykuł–kategoria | 20 | 20 |
| zdarzenia artykułów | 117 | 117 |
| media | 5 | 5 |
| bannery / tłumaczenia bannerów | 1 / 6 | 1 / 6 |
| ankiety / kampanie | 1 / 0 | 1 / 0 |

Przywrócono wszystkie istniejące warianty językowe, także szkice AI i rewizje:

- tłumaczenia artykułów: `de=6`, `en=6`, `es=6`, `fr=6`, `it=6`, `pl=1`;
- rewizje tłumaczeń: `de=7`, `en=8`, `es=7`, `fr=7`, `it=7`, `pl=1`;
- tłumaczenia kategorii: `en=2`;
- tłumaczenia bannera: po jednym dla `pl`, `de`, `en`, `es`, `fr`, `it`.

Statusy nie zostały sztucznie podniesione do `published`. Przełącznik języka
artykułu nie tworzy już błędnego odnośnika do brakującego tłumaczenia: prowadzi w
takim przypadku do listy artykułów danego języka.

## Portfel i księga

Źródłowy globalny łańcuch zawierał 41 transakcji administratora przeplecionych z
transakcjami innych portfeli. Nie dało się zachować ich jako samodzielnego,
matematycznie kompletnego łańcucha bez fałszowania hashy. Po zatwierdzeniu przez
użytkownika wykonano kontrolowane otwarcie nowego łańcucha portfela.

| Pole | MySQL przed | PostgreSQL po |
|---|---:|---:|
| portfele administratora | 1 | 1 |
| punkty | 129362 | 129362 |
| pozostałe salda minor | 0 | 0 |
| stare transakcje administratora | 41 | 41 w niezmiennym archiwum audytowym |
| aktywne transakcje per-portfel | nie dotyczy | 1 jawne otwarcie |
| anchory | 0 | 1 |

Archiwum 41 źródłowych rekordów jest niezmienne i zostało zweryfikowane względem
źródła. Aktywny wpis otwarcia ma osobny łańcuch per-portfel, HMAC, głowę i anchor.
`reconcile_finances.php` oraz `verify_ledger.php` zakończyły się wynikiem poprawnym:
jeden portfel, jedna aktywna transakcja, jeden anchor i saldo 129362. Nie zmieniono
`FINANCE_HMAC_KEY`.

## Media i MinIO

Pięć wymaganych obrazów zostało przeniesionych do MinIO. Manifest zawiera źródło,
rozmiar, SHA-256, klucz obiektu, typ zasobu, ID rekordu i wynik odczytu. Każdy obiekt
został ponownie odczytany przez adapter aplikacji oraz sprawdzony pod względem
rozmiaru, typu MIME i sumy SHA-256.

Wynik: `scanned=5`, `migrated=5`, `verified=5`, `missing=0`, `invalid=0`, `failed=0`,
`deleted_sources=0`. Nie użyto `--delete-source`; źródłowe pliki i backupy pozostały.

## Linki i zachowanie starej strony

Crawler odwiedził 119 stron, sprawdził 2748 odnośników i akcji oraz 14
przekierowań. Wynik: zero błędów. Potwierdzono odpowiedzi z `app-1` i `app-2`, bez
IIS, portu 80 i adresów pozbawionych `:8080`.

Sprawdzone zostały strony `/`, `/pl`, `/pl/articles`, `/pl/surveys`,
`/pl/campaigns`, `/pl/jak-zarabiac`, `/pl/login`, `/pl/author`, `/admin` oraz strony
główne i listy artykułów dla `de`, `en`, `es`, `fr`, `it`. Diagnoza tras korzysta z
rzeczywistego historycznego formatu `/LANG/artykul/SLUG`.

## Testy odbiorowe

- PHPUnit: 84 testy, 549 asercji, wynik `OK`;
- PHPStan poziom 5 dla śledzonego kodu aplikacji, skryptów i zmian migracyjnych:
  brak błędów;
- `docker_smoke.ps1`: dwa backendy oraz PostgreSQL, Valkey, MinIO i Mailpit dostępne;
- `stage8_acceptance.ps1`: sesja, cache i obiekty współdzielone między instancjami;
  zatrzymanie `app-1` nie przerwało pracy; brak podwójnych naliczeń;
- `stage9_load.ps1`, profil dwóch instancji: 880 żądań, 0% błędów, p95 102,69 ms,
  1314/1314 kontroli poprawnych;
- profil skalowania z tymczasowym `app-3`: 1682 żądania, 0% błędów, p95 39,58 ms,
  2541/2541 kontroli poprawnych; każda instancja obsłużyła ruch;
- po testach dane tymczasowe usunięto, a scheduler i worker zarobków przywrócono.

## Backup i możliwość powtórzenia

Backup sprzed zmian znajduje się w:

```text
X:\zrodlo-slowa\backups\restore-admin-20260801-093517
```

Zawiera logiczny dump MySQL, fizyczny snapshot pristine, dump PostgreSQL i manifest
uploadów z SHA-256. Importer obsługuje `--plan`, `--dry-run`, `--apply`, `--resume` i
`--report`. Dry-run działał w izolowanym schemacie i nie pozostawił danych.

## Znane ryzyka i decyzje

1. Źródło zawiera historyczny wariant PL tłumaczenia artykułu 41 o tej samej ścieżce
   co bazowy artykuł: `/pl/artykul/praca-sens-i-godnosc-czlowieka`. Rekord zachowano
   jako dane redakcyjne; migracja nie utworzyła dodatkowego duplikatu. Wymaga decyzji
   redakcyjnej przed ewentualną publikacją wariantu.
2. Aktywna historia portfela ma celowo jeden wpis otwarcia. Oryginalne 41 rekordów
   jest w oddzielnym, niezmiennym archiwum, ponieważ selektywny wycinek globalnego
   łańcucha nie jest samodzielnie weryfikowalny. Jest to zatwierdzona decyzja, nie
   automatyczne wyrównanie.
3. Interaktywne logowanie HTTP sprawdzono osobno przez `katon` na `app-1` i przez
   `admin@100pl.pl` na `app-2`; oba warianty zakończyły się wejściem do `/pl/admin`.
   Użytkownik jawnie wybrał krótkie hasło deweloperskie. Skrypt dopuszcza taki wyjątek
   tylko przy `APP_ENV=local` i `ADMIN_ALLOW_INSECURE_PASSWORD=true`; środowisko
   produkcyjne nadal wymaga minimum 12 znaków.
4. Pełne wywołanie PHPStan widzi lokalne, nieśledzone katalogi użytkownika
   `app/Services/Http` i `app/Services/Payments` ze starą przestrzenią nazw `Book100`.
   Nie należą do tej zmiany i nie zostały zmodyfikowane ani dodane do commita. Analiza
   całego śledzonego kodu oraz nowych plików przechodzi bez błędów.
5. Środowisko pozostaje deweloperskie. Przygotowane adaptery sekretów, szyfrowania,
   S3, poczty, płatności, AI i logowania umożliwiają późniejsze dołączenie KMS, SIEM,
   WAF lub usług chmurowych bez wiązania domeny z dostawcą.

Laragon i jego porty pozostały nietknięte. Nie wykonano resetu PostgreSQL,
`docker compose down -v`, force push ani wdrożenia do chmury.
