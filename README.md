# ŹRÓDŁO SŁOWA

Platforma wydawnicza w PHP 8.3 z autorskim MVC, wielojęzycznością, workflow redakcyjnym,
portfelami, płatnościami, wypłatami, ankietami i kontrolowanymi funkcjami AI.

## Lokalne środowisko Docker (ETAP 10)

Odseparowane środowisko uruchamia reverse proxy, dwie instancje PHP, PostgreSQL,
Valkey, MinIO, Mailpit oraz osobne procesy `worker-earnings`, `worker-email`,
`worker-ai` i `scheduler`. Używa wyłącznie portów `8080`, `5433`,
`6380`, `19001` i `8025`; nie zajmuje portów Laragona `80`, `443` ani `3306`.

```powershell
docker compose up -d --build
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\docker_smoke.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stage8_acceptance.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stage9_load.ps1
```

Instrukcja, diagnostyka i zasady izolacji są opisane w
[`docs/URUCHOMIENIE_LOKALNE.md`](docs/URUCHOMIENIE_LOKALNE.md).

Architektura przenosi naliczenia, e-mail i administracyjne AI poza procesy HTTP.
PostgreSQL pozostaje źródłem prawdy dla kolejek; Valkey jest tylko sygnałem.
Zadania mają leasing, retry, idempotencję, historię przejść i dead-letter.
Wyłączenie `worker-ai` nie zatrzymuje strony ani pozostałych workerów.
Szczegóły:

- [`docs/ARCHITEKTURA.md`](docs/ARCHITEKTURA.md);
- [`docs/MIGRACJA_POSTGRESQL.md`](docs/MIGRACJA_POSTGRESQL.md);
- [`docs/VALKEY_CACHE_I_KOLEJKI.md`](docs/VALKEY_CACHE_I_KOLEJKI.md);
- [`docs/S3_MINIO.md`](docs/S3_MINIO.md);
- [`docs/FINANSE_I_IDEMPOTENCJA.md`](docs/FINANSE_I_IDEMPOTENCJA.md);
- [`docs/SKALOWANIE.md`](docs/SKALOWANIE.md);
- [`docs/TESTY_OBCIAZENIOWE.md`](docs/TESTY_OBCIAZENIOWE.md);
- [`docs/WDROZENIE_CHMURA.md`](docs/WDROZENIE_CHMURA.md);
- [`docs/ARCHITEKTURA_BEZPIECZENSTWA.md`](docs/ARCHITEKTURA_BEZPIECZENSTWA.md);
- [`docs/ARCHITEKTURA_WORKEROW.md`](docs/ARCHITEKTURA_WORKEROW.md);
- [`docs/FINANSE_ETAP_7.md`](docs/FINANSE_ETAP_7.md);
- [`docs/TEST_DWOCH_INSTANCJI.md`](docs/TEST_DWOCH_INSTANCJI.md).

## Wymagania środowiska Laragon (tryb dotychczasowy)

- PHP 8.3 z rozszerzeniami: `curl`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `sodium`;
- MySQL/MariaDB;
- Composer;
- serwer WWW z document root ustawionym na katalog `public`.

## Instalacja lokalna

1. Skopiuj `.env.install.example` do `.env` i ustaw połączenie z bazą oraz e-mail
   administratora. Nie wpisuj przykładowych sekretów na produkcji.
2. Wygeneruj brakujące sekrety bez wyświetlania ich wartości:

   ```powershell
   php scripts/provision_secrets.php --apply
   ```

3. Zainstaluj zależności i zainicjalizuj albo zaktualizuj bazę:

   ```powershell
   composer install
   php scripts/install.php
   php scripts/install.php --check
   ```

`install.php` rozpoznaje istniejącą bazę i wykonuje wyłącznie brakujące migracje.
Nie resetuje danych i nie zmienia hasła istniejącego administratora.

Destrukcyjny reset jest odrębnym skryptem, zablokowanym na produkcji i wymaga dwóch
jawnych parametrów potwierdzających. Nie używaj go do zwykłej aktualizacji.

## Uruchomienie

Przykładowe konfiguracje:

- `docs/apache-vhost.example.conf`;
- `docs/nginx-site.example.conf`.

Dla Laragona ustaw `DocumentRoot` na `X:/zrodlo-slowa/public`, włącz `mod_rewrite`
i dopasuj `APP_URL`. Katalog `public/.htaccess` obsługuje front controller.

## Operacje

```powershell
php scripts/migrate.php
php scripts/migrate_ledger_to_wallet_chains.php
php scripts/create_ledger_anchor.php
php scripts/mail_worker.php --once
php scripts/worker_earnings.php --once
php scripts/worker_ai.php --once
php scripts/scheduler.php --once
php scripts/verify_ledger.php
composer test
composer analyse
```

Worker poczty powinien być uruchamiany cyklicznie przez Harmonogram zadań/cron albo
w trybie `--daemon`. SMTP konfiguruje `MAILER_DSN` lub zestaw `MAIL_SMTP_*`.

Operacje finansowe wymagają mocnego i niezmiennego `FINANCE_HMAC_KEY`. Transakcje
są serializowane tylko w obrębie danego portfela, podpisywane HMAC, a scheduler
tworzy godzinne anchory/Merkle root poza ścieżką HTTP. Archiwalny globalny łańcuch
pozostaje zachowany do audytu. Pełną historię, anchory i salda kontroluje
`scripts/verify_ledger.php`.

## Bezpieczeństwo wdrożenia

- Na produkcji ustaw `APP_ENV=production`, `APP_DEBUG=false`, HTTPS i
  `SESSION_SECURE=true`.
- Ustaw niezależne, losowe `APP_KEY`, `PASSWORD_PEPPER` i `FINANCE_HMAC_KEY`.
- Nie udostępniaj katalogu głównego repozytorium jako document root.
- Buduj obraz wydania wyłącznie z czystego, oznaczonego commita; lokalne pliki
  nieśledzone nie mogą trafić do kontekstu obrazu.
- Przed wdrożeniem uruchom kontrolę instalacji, testy, analizę statyczną i verifier
  księgi.

Historia napraw i stan walidacji znajdują się w `docs/`.
