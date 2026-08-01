# PostgreSQL — ETAP 3

## Stan

Aplikacja i instalator obsługują PostgreSQL przez `pdo_pgsql`. Docker używa
wyłącznie PostgreSQL w swoim wolumenie; nie łączy się z MySQL Laragona i nie
korzysta z jego pliku `.env`, sesji ani konfiguracji.

Baseline `database/postgresql/schema.sql` jest generowany deterministycznie z
zachowanego schematu MySQL przez:

```powershell
php scripts/dev/convert_mysql_schema_to_postgresql.php
```

Schemat zawiera 74 tabele domenowe, 91 kluczy obcych, indeksy, ograniczenia
`CHECK`, kolumny `jsonb`, identity oraz ochronę przed ujemnym saldem. Instalator
dodaje tabelę historii migracji. Stary schemat MySQL pozostaje w repozytorium jako
źródło migracji i nie wolno go usuwać przed udanym, podpisanym odbiorem danych.

## Czysta instalacja Docker

Po uruchomieniu Compose:

```bash
docker compose exec app-1 php scripts/install.php
docker compose exec app-1 php scripts/install.php --check
```

Sekrety i dane konta administratora muszą pochodzić ze zmiennych środowiskowych.
Instalator nie tworzy automatycznie bazy, nie tworzy schematu bez jawnej zgody,
używa blokady migracyjnej oraz pozwala ponowić instalację bez resetu danych.

Test `FreshInstallTest` tworzy losowy, izolowany schemat, wykonuje pełną
instalację, kontrolę i ponowienie, a następnie usuwa wyłącznie ten schemat.

## Kontrola MySQL → PostgreSQL

Rzeczywiste dane Laragona nie zostały przeniesione automatycznie. Jest to celowe:
Laragon ma pozostać nietknięty, a migracja danych wymaga osobnego okna,
kopii zapasowej i odbioru biznesowego.

Po kontrolowanym załadowaniu danych do PostgreSQL należy uruchomić read-only gate:

```powershell
$env:MYSQL_SOURCE_DB_HOST = '127.0.0.1'
$env:MYSQL_SOURCE_DB_PORT = '3306'
$env:MYSQL_SOURCE_DB_NAME = 'zrodlo_slowa'
$env:MYSQL_SOURCE_DB_USER = '...'
$env:MYSQL_SOURCE_DB_PASS = '...'
php scripts/compare_mysql_postgresql.php
```

Skrypt nie ma operacji zapisu. Porównuje liczbę rekordów we wszystkich tabelach
oraz deterministyczne hashe krytycznej historii finansowej: portfeli, transakcji,
wypłat, zatwierdzeń, audytu, płatności, przychodów, darowizn i zakupów artykułów.
Wynik różny od zera blokuje cutover.

Dodatkowo `php scripts/reconcile_finances.php` sprawdza łańcuch HMAC, głowę
księgi, ostatnie salda portfeli i tworzy provider-neutralny snapshot do
późniejszego automatycznego uzgadniania.

## Księga finansowa

ETAP 7 zachowuje archiwalny globalny łańcuch i `financial_ledger_head`, ale usuwa
go z gorącej ścieżki po kontrolowanym przełączeniu na łańcuchy per-portfel.
Przełączenie wykonuje `scripts/migrate_ledger_to_wallet_chains.php`. Komenda w
jednej transakcji i pod blokadą migracyjną wymusza:

1. poprawność całego starego łańcucha HMAC;
2. zgodność wszystkich końcowych sald i historii zmian;
3. backfill podpisów per-portfel;
4. ponowną kontrolę wszystkich podpisów, głów i sald;
5. zapis raportu zgodności i jego SHA-256;
6. atomową zmianę trybu na `per_wallet` wyłącznie przy zerowej liczbie błędów.

Szczegóły, procedura wdrożenia i rollback są w `FINANSE_ETAP_7.md`.

## Backup, eksport i restore

W WSL, z katalogu repozytorium:

```bash
bash scripts/ops/postgres_backup.sh
bash scripts/ops/postgres_export.sh
bash scripts/ops/postgres_restore.sh backups/PLIK.dump RESTORE_POSTGRESQL
```

Backup jest pełnym archiwum `pg_dump --format=custom`. Eksport jest czytelnym,
data-only SQL przeznaczonym do kontroli lub przenoszenia danych. Restore jest
destrukcyjny dla wskazanej bazy PostgreSQL, wymaga dokładnego potwierdzenia i po
zakończeniu musi być sprawdzony przez instalator oraz reconciler.

Nie jest to backup nieusuwalny. WORM/object-lock, retencja i kopia poza głównym
kontem zostaną podłączone później bez zmiany formatu komend operacyjnych.

## Walidacja ETAPU 7

```bash
php vendor/bin/phpunit --testsuite unit
php vendor/bin/phpunit --testsuite integration
php vendor/bin/phpstan analyse --no-progress
curl http://localhost:8080/health/ready
curl http://localhost:8080/
```

Test równoległości potwierdza również, że dwa jednoczesne kliknięcia operacji
finansowej tworzą jedną transakcję, zwracają ten sam identyfikator i zmieniają
saldo dokładnie raz.
