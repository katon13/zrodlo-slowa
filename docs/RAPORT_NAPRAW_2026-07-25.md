# Raport końcowy napraw projektu ŹRÓDŁO SŁOWA

Data: 25 lipca 2026  
Katalog roboczy: `X:\zrodlo-slowa`  
Gałąź robocza: `codex/full-repair-2026-07-25`

## 1. Wynik

Naprawy możliwe do wykonania w kodzie, bazie danych i lokalnym środowisku zostały
wdrożone. Aplikacja przechodzi pełny zestaw testów, analizę statyczną, instalację na
czystej bazie, kontrolę istniejącej bazy, kontrolę integralności finansowej oraz testy
HTTP i wielojęzyczności.

Nie wykonano destrukcyjnego resetu istniejącej bazy ani nie zmieniono hasła istniejącego
administratora. Przed rozpoczęciem powstała kopia:

`X:\zrodlo-slowa-backups\zrodlo-slowa-pre-full-repair-2026-07-25.zip`

Rozmiar kopii: 4 879 053 bajty.

## 2. Najważniejsze wdrożone naprawy

### Finanse i portfele

- wprowadzono kanoniczny, podpisywany HMAC łańcuch księgi finansowej;
- dodano serializowaną głowę księgi i blokady rekordów portfeli;
- uszczelniono idempotencję: ponowne użycie klucza z innymi danymi jest odrzucane;
- rozdzielono salda dostępne i zarezerwowane;
- poprawiono rezerwację, zwolnienie i rozliczenie wypłat;
- przelewy blokują oba portfele w stabilnej kolejności;
- wprowadzono kontrolę ról aktora operacji;
- operacje administracyjne i wydawcy podlegają zasadzie Maker–Checker;
- nieudane lub odrzucone akceptacje pozostają w trwałym audycie;
- dodano trigger chroniący salda oraz niezależny skrypt weryfikacyjny.

Aktualna baza:

- 82 transakcje;
- 10 portfeli;
- poprawny łańcuch `previous_hash`;
- poprawne podpisy HMAC;
- głowa księgi zgodna z ostatnią transakcją;
- salda portfeli zgodne z księgą.

### Logowanie, sesje i OAuth

- naprawiono krytyczny przypadek CSRF, w którym pusty token mógł zostać uznany za
  poprawny;
- wszystkie mutujące trasy wymagają CSRF, poza świadomie wyłączonymi callbackami
  Apple i webhookiem Stripe;
- włączono ścisły tryb sesji, regenerację identyfikatora i wersjonowanie sesji;
- utwardzono ustawienia ciasteczek;
- ujednolicono logowanie hasłem, OAuth i 2FA;
- reset hasła unieważnia wcześniejsze sesje;
- dodano limity prób logowania i operacji uwierzytelniających;
- polityka hasła wymaga co najmniej 12 znaków, liter i znaków niebędących literami;
- role wysokiego zaufania wymagają zweryfikowanego e-maila i 2FA;
- sekrety 2FA są szyfrowane przez Sodium;
- naprawiono generowanie URI QR 2FA — używa odszyfrowanego sekretu, nie ciphertextu;
- istniejący jeden jawny sekret 2FA został zaszyfrowany; liczba jawnych sekretów
  legacy wynosi obecnie 0;
- uszkodzony ciphertext kończy się kontrolowanym błędem, bez ujawniania danych;
- tokeny OIDC są sprawdzane kryptograficznie z użyciem JWKS/JWS oraz pól
  `issuer`, `audience`, `azp`, `nonce` i czasu ważności;
- Google OAuth i Apple Sign In używają wspólnego, bezpiecznego przepływu kont.

### Artykuły, korekta i publikacja

- edycja opublikowanego artykułu nie zmienia już treści publicznej bezpośrednio;
- autor i administrator tworzą izolowaną rewizję roboczą;
- dopiero zaakceptowana rewizja może zastąpić publikację;
- media dodawane podczas edycji administratora są przypisywane do rewizji;
- korektor widzi i może edytować wyłącznie teksty w statusie `submitted` lub
  `review`;
- operacje przekazania do korekty i publikacji korzystają z blokad transakcyjnych;
- zmiana treści głównej unieważnia akceptację zależnych tłumaczeń.

### Tłumaczenia i SEO

- szkice, wersje AI i odrzucone tłumaczenia nie są już publiczne;
- publiczny widok, slugi, mapy języków, `hreflang` i sitemap używają wyłącznie
  tłumaczeń o statusie `published`;
- edycja opublikowanego tłumaczenia zapisuje poprzednią wersję i przywraca status
  `draft`;
- dodano tabelę `article_translation_versions`;
- dla istniejących 31 tłumaczeń utworzono początkowe migawki historii;
- zatwierdzanie i publikacja tłumaczeń są transakcyjne i wersjonowane;
- bezpośrednia publikacja z panelu przechodzi wewnętrznie przez szkic, akceptację
  i publikację;
- poprawiono zgodność języka angielskiego z walutą GBP.

### Instalator, migracje i baza

- instalator rozpoznaje istniejącą bazę i nie nadpisuje danych;
- ponowne uruchomienie instalatora zachowuje administratora, platformę, ustawienia
  i hasła;
- destrukcyjny reset jest osobnym skryptem z dokładnym potwierdzeniem;
- parser SQL obsługuje `DELIMITER`, procedury i triggery;
- migracje mają sumy kontrolne, stan wykonania i raport błędów;
- pełny schemat instaluje się na pustej bazie i przechodzi ponowną, niedestrukcyjną
  instalację;
- walidator środowiska kontroluje rozszerzenia PHP, siłę sekretów, URL, HTTPS,
  debug, ciasteczka, SMTP oraz dane wymagane przez włączone integracje;
- zachowano zgodność ze starszą flagą `OPENAI_ENABLED`.

Zastosowane migracje:

1. `20260725_001_financial_integrity`;
2. `20260725_002_auth_hardening`;
3. `20260725_003_mail_queue_worker`;
4. `20260725_004_article_revision_workflow`;
5. `20260725_005_ai_budget_enforcement`;
6. `20260725_006_translation_publication_safety`.

Wszystkie mają status `applied`; nie ma migracji uszkodzonych ani oczekujących.

### Poczta, AI, cache i warstwa HTTP

- wysyłka poczty korzysta z Symfony Mailer;
- kolejka ma trwałe stany, atomowe przejmowanie wiadomości, blokady, retry,
  rosnące opóźnienie i stan błędu końcowego;
- dodano worker jednorazowy i tryb ciągły;
- budżet AI jest atomowo rezerwowany i rozliczany;
- cache JSON używa blokad, plików tymczasowych i atomowego zastąpienia;
- uszkodzony wpis cache jest bezpiecznie odtwarzany;
- sesje używają katalogu `storage/sessions` zamiast zależeć od globalnego
  `C:\laragon\tmp`, z kontrolowanym fallbackiem do katalogu tymczasowego użytkownika;
- cache sprawdza realną możliwość zapisu, używa bezpiecznego katalogu zapasowego,
  a niedostępność cache nie powoduje już awarii strony;
- usunięto `X-Powered-By`;
- dodano CSP, `X-Content-Type-Options`, `X-Frame-Options`, politykę referrera,
  Permissions Policy oraz HSTS dla produkcji;
- zabezpieczono przekierowania przed open redirect;
- katalogi techniczne mają reguły odmowy dostępu;
- przykładowe konfiguracje Apache i Nginx wskazują `public` jako document root;
- błędy produkcyjne są raportowane bez ujawniania stack trace użytkownikowi.

## 3. Wyniki walidacji

| Kontrola | Wynik |
|---|---|
| PHPUnit | 28/28 testów, 124 asercje |
| PHPStan | brak błędów |
| PHP syntax lint | 206 plików projektu poprawnych |
| JSON | 32 pliki poprawne |
| Composer validate | `composer.json` poprawny |
| Composer audit | brak znanych advisory bezpieczeństwa |
| Instalacja na pustej bazie | poprawna, ponad 300 instrukcji schematu |
| Ponowna instalacja | niedestrukcyjna, dane administratora zachowane |
| Kontrola bieżącej instalacji | `ok: true`, brak brakujących elementów |
| Migracje | 6/6 zastosowanych |
| Integralność księgi | poprawna: 82 transakcje, 10 portfeli |
| Kontekst językowy | poprawny |
| Brakujące klucze tłumaczeń | brak |
| UI użytkownika | komplet kluczy i poprawny UTF-8 |
| Konfiguracja stron/języków | poprawna |
| Waluty | poprawne mapowanie PLN/GBP/EUR |
| HTTP `/` | 200 z nagłówkami bezpieczeństwa |
| HTTP bez praw zapisu do pierwotnego cache i `C:\laragon\tmp` | 200, sesja i cache korzystają z bezpiecznego fallbacku |
| POST logowania bez CSRF | 419 |
| Publiczny URL szkicu tłumaczenia | 404 |
| Próba pobrania `/.env` | 404 |

## 4. Konfiguracja wymagająca danych zewnętrznych

Poniższe elementy nie są błędami kodu i nie można ich bezpiecznie uzupełnić
fikcyjnymi danymi:

1. **SMTP** — transport nie jest skonfigurowany. W kolejce zachowano 21 wiadomości
   ze statusem `queued`. Po ustawieniu `MAILER_DSN` albo `MAIL_SMTP_*` należy
   uruchomić:

   `php scripts/mail_worker.php --once`

2. **Google i Apple OAuth** — implementacja jest gotowa, ale brak danych klienta.
   Obie integracje zostały wyłączone w lokalnym `.env`, aby nie prezentować
   niedziałających przycisków.

3. **Stripe** — integracja pozostaje wyłączona do czasu ustawienia prawdziwych
   kluczy, sekretu webhooka i URL callbacków.

4. **OpenAI** — konfiguracja lokalna jest rozpoznawana również przez starszą flagę
   `OPENAI_ENABLED`. Testy budżetu i zapisu przeszły; nie wykonano płatnego
   wywołania produkcyjnego modelu.

5. **Produkcja** — przed publicznym wdrożeniem trzeba ustawić `APP_ENV=production`,
   `APP_DEBUG=false`, publiczny adres HTTPS, `SESSION_SECURE=true` oraz uruchomić
   stały worker poczty.

Apache Laragona nie był pozostawiony uruchomiony. Porty testowe 80 i 8080 są wolne.
Aplikację zweryfikowano przez PHP 8.3 Laragona na `127.0.0.1:8080`; właściwy vhost
powinien wskazywać `X:/zrodlo-slowa/public`.

## 5. Główne punkty wejścia do dalszej obsługi

- `README.md` — instalacja, uruchomienie i operacje;
- `scripts/install.php --check` — kontrola środowiska i bazy;
- `scripts/migrate.php` — bezpieczne wykonanie brakujących migracji;
- `scripts/verify_ledger.php` — niezależna kontrola księgi;
- `scripts/mail_worker.php` — obsługa kolejki pocztowej;
- `docs/apache-vhost.example.conf` — przykład Apache/Laragon;
- `docs/nginx-site.example.conf` — przykład Nginx;
- `phpunit.xml` i `phpstan.neon` — automatyczne bramki jakości;
- `.github/workflows/quality.yml` — CI.

## 6. Ocena końcowa

Kod, schemat i aktualna baza są w spójnym, przetestowanym stanie. Pozostałe prace
to wyłącznie podłączenie prawdziwych usług zewnętrznych oraz decyzja operatora o
docelowym adresie i sposobie uruchamiania serwera.
