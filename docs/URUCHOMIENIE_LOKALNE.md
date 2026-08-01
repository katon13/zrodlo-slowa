# Lokalne środowisko Docker

## Zakres ETAPÓW 5–10

Środowisko uruchamia dwie identyczne instancje PHP za reverse proxy oraz osobne
usługi PostgreSQL, Valkey, MinIO i Mailpit. Laragon pozostaje niezależny: Compose
nie zawiera MySQL, nie montuje katalogów Laragona i nie korzysta z jego sesji ani
konfiguracji.

Środowisko zawiera niezależne kontenery `worker-earnings`, `worker-email`, `worker-ai`
i `scheduler`. Nie są zależnością `app-1`, `app-2` ani proxy, więc można je
zatrzymać lub przenieść bez wyłączenia HTTP. Każdy worker ma jedną pętlę
wykonawczą, osobną nazwę połączenia DB oraz limity CPU, RAM i procesów.

Warstwa infrastruktury, schemat i logika aplikacji działają na PostgreSQL.
Sesje, cache, limity oraz krótkie blokady są współdzielone w Valkey.
`/health/ready` kontroluje PostgreSQL, schemat, rozszerzenie `php_redis`, Valkey i MinIO,
a strona główna działa przez obie instancje za proxy.

Uploady artykułów i avatarów są zapisywane w prywatnym buckecie MinIO przez
adapter S3. Żadna z instancji aplikacji nie przechowuje trwałych uploadów na
własnym dysku. Publiczne obrazy są odczytywane pod nieprzewidywalną referencją
`/objects/...`, a obiekty prywatne nie są dostępne tą trasą.

## Porty

| Usługa | Adres hosta | Port w kontenerze |
|---|---:|---:|
| strona / reverse proxy | `http://localhost:8080` | 8080 |
| PostgreSQL | `127.0.0.1:5433` | 5432 |
| Valkey | `127.0.0.1:6380` | 6379 |
| MinIO Console | `http://localhost:19001` | 9001 |
| Mailpit | `http://localhost:8025` | 8025 |

Wszystkie publikowane porty są przypięte wyłącznie do `127.0.0.1`. Porty
Laragona `80`, `443` i `3306` nie są zajmowane przez ten projekt. MinIO używa
hostowego portu `19001`, ponieważ `9001` jest zajęty przez potrzebną usługę
`AvidEditorDbEngine`; Avid nie jest zatrzymywany ani przekonfigurowywany.

## Uruchomienie

W PowerShell, w katalogu repozytorium:

```powershell
docker compose up -d --build
docker compose ps
Invoke-RestMethod http://localhost:8080/health/live
Invoke-RestMethod http://localhost:8080/health/ready
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\docker_smoke.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stage8_acceptance.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stage9_load.ps1
```

Drugi skrypt wykonuje pełny test ETAPU 8: logowanie na `app-1`, kontynuację
sesji na `app-2`, wspólny cache i MinIO, brak podwójnych naliczeń oraz działanie
po kontrolowanym zatrzymaniu `app-1`. Szczegóły: [`TEST_DWOCH_INSTANCJI.md`](TEST_DWOCH_INSTANCJI.md).

Trzeci skrypt wykonuje ETAP 9: ruch k6 przez dwie instancje, kontrolowaną awarię
workera naliczeń, retry, kontrolę sald i księgi oraz krótką próbę przez tymczasową
trzecią instancję. `app-3` i `proxy-load` nie publikują portów hosta i są usuwane
po teście. Szczegóły: [`TESTY_OBCIAZENIOWE.md`](TESTY_OBCIAZENIOWE.md).

ETAP 10 nie wdraża chmury. Domyka dokumentację architektury, finansów, skalowania
i neutralny wobec dostawcy plan produkcyjny. Obraz wydania należy budować z
czystego, oznaczonego commita, aby lokalne pliki nieśledzone nie znalazły się w
kontekście builda.

Jeżeli Docker jest dostępny tylko wewnątrz WSL:

```bash
cd /mnt/x/zrodlo-slowa
docker compose up -d --build
docker compose ps
```

Compose ma bezpieczne wartości domyślne przeznaczone wyłącznie do pracy lokalnej.
Opcjonalną konfigurację można utrzymywać poza Gitem:

```powershell
Copy-Item .env.local.example .env.local
docker compose --env-file .env.local up -d --build
```

Nazwy wejściowe Compose mają prefiks `DOCKER_`, dlatego istniejący plik `.env`
Laragona i jego zmienne `DB_*` nie są używane przez kontenery. Nie kopiuj
`.env.local.example` do `.env`.

## Diagnostyka

```powershell
docker compose ps
docker compose logs --tail 100 proxy app-1 app-2
docker compose logs --tail 100 worker-earnings worker-email worker-ai scheduler
docker compose config
```

- `GET /health/live` sprawdza proces PHP bez zależności zewnętrznych.
- `GET /health/ready` sprawdza wymagane rozszerzenia, autoload, PostgreSQL, Valkey i bucket MinIO.
- Odpowiedź zawiera `instance`, dzięki czemu można potwierdzić rozdział ruchu
  między `app-1` i `app-2`.
- Dane PostgreSQL, Valkey, MinIO i Mailpit są przechowywane w nazwanych wolumenach
  Dockera, a nie w Laragonie.
- Kontenery aplikacji nie mają lokalnego wolumenu sesji. Aktualizowana przy każdym
  zapisie, współdzielona kopia awaryjna sesji znajduje się w PostgreSQL.

## Zatrzymanie

```powershell
docker compose down
```

Polecenie zachowuje dane w wolumenach. `docker compose down -v` usuwa lokalne dane
PostgreSQL, Valkey, MinIO i Mailpit tego projektu; jest destrukcyjne dla środowiska
Docker, ale nie dotyka Laragona.

## Zasady produkcyjne

`compose.yaml` służy do rozwoju lokalnego. Produkcja powinna używać obrazu
`production`, zarządzanych PostgreSQL/Valkey/S3, TLS, zewnętrznego menedżera
sekretów i osobnych workerów. Wartości z `.env.local.example` nie mogą trafić na
produkcję; punktem wyjścia jest `.env.production.example`.

Worker AI obsługuje wyłącznie zadania administracyjne i redakcyjne. Utworzenie
oraz wykonanie zadania wymagają kontroli aktywnego konta i konkretnego
uprawnienia, audytu, rezerwacji limitu kosztów i trwałego klucza idempotencji.
Nieznany wynik płatnego wywołania trafia do dead-letter bez automatycznego retry.
Zwykli użytkownicy nie otrzymują widoku, akcji ani bezpośredniego endpointu AI.

Zatrzymanie AI bez wpływu na serwis:

```powershell
docker compose stop worker-ai
Invoke-RestMethod http://localhost:8080/health/ready
docker compose start worker-ai
```

Podział kolejek, limity zasobów i zasady niezależnego zatrzymywania workerów
opisuje [`ARCHITEKTURA_WORKEROW.md`](ARCHITEKTURA_WORKEROW.md).

Zasady cache, invalidacji, rate limitingu, locków i nietrwałych sygnałów kolejek
opisuje [`VALKEY_CACHE_I_KOLEJKI.md`](VALKEY_CACHE_I_KOLEJKI.md).

Konfigurację S3/MinIO, migrację lokalnych uploadów i test zgodności opisuje
[`S3_MINIO.md`](S3_MINIO.md).
