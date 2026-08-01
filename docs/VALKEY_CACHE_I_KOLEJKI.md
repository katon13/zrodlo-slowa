# Valkey: sesje, cache, limity, blokady i sygnały kolejek

## Zakres ETAPU 4

Valkey jest współdzieloną warstwą przyspieszającą wszystkie instancje aplikacji.
Nie jest źródłem prawdy dla sald, transakcji, wypłat, zarobków, audytu ani
ostatecznych statusów zadań. Te dane pozostają w PostgreSQL.

Aktywne elementy:

- sesje współdzielone przez `app-1` i `app-2`;
- cache-aside z TTL, wersjonowaniem kluczy i invalidacją;
- krótkie blokady przeciw cache stampede;
- atomowe liczniki rate limitingu logowania i resetu hasła;
- krótkotrwałe sygnały budzące przyszłe workery;
- fallback sesji do PostgreSQL i wyłączenie cache przy awarii Valkey.

## Konfiguracja

Sterowniki są wybierane wyłącznie przez zmienne środowiskowe:

```text
SESSION_DRIVER=valkey
CACHE_DRIVER=valkey
RATE_LIMIT_DRIVER=valkey
LOCK_DRIVER=valkey
QUEUE_SIGNAL_DRIVER=valkey
```

Połączenie opisują:

```text
VALKEY_HOST
VALKEY_PORT
VALKEY_PASSWORD
VALKEY_DATABASE
VALKEY_TLS
VALKEY_PREFIX
VALKEY_CONNECT_TIMEOUT
VALKEY_READ_TIMEOUT
SESSION_TTL_SECONDS
```

Obraz Docker zawiera przypięte rozszerzenie `phpredis 6.3.0`. Laragon domyślnie
pozostaje przy `SESSION_DRIVER=file`, `CACHE_DRIVER=file` i limitach bazodanowych;
nie jest przekonfigurowywany ani podłączany do Valkey Dockera.

## Sesje

`SharedSessionHandler` zapisuje sesję pod zahaszowanym identyfikatorem z TTL.
Kontenery aplikacji nie używają lokalnego katalogu sesji. Każdy zapis do Valkey
aktualizuje również trwałą kopię awaryjną w tabeli `sessions` w PostgreSQL, dzięki
czemu także sesja rozpoczęta przed awarią pozostaje dostępna. Gdy Valkey jest
chwilowo niedostępny, odczyt i zapis przechodzą do PostgreSQL. Po odzyskaniu Valkey
sesja jest ponownie ładowana do szybkiej warstwy, a kopia awaryjna pozostaje
aktualna do wylogowania albo wygaśnięcia.

Zmiana hasła, statusu, głównej roli, ról redakcyjnych, istotnych uprawnień albo
administracyjne wyłączenie 2FA zwiększa `users.session_version`. Następne żądanie
unieważnia wtedy wszystkie starsze sesje użytkownika niezależnie od instancji.

## Cache

`ValkeyCacheStore` przechowuje wyłącznie JSON. Klucze zawierają:

- wersję formatu;
- generację globalną;
- generację grupy;
- hash logicznego klucza.

Invalidacja grupy zwiększa jej licznik zamiast wykonywać kosztowne `SCAN` lub
kasować tysiące kluczy. Wpisy starej generacji stają się nieosiągalne i wygasają
zgodnie z TTL. TTL otrzymuje niewielki losowy jitter, a pierwszy proces obliczający
brakującą wartość używa krótkiego locka z tokenem właściciela.

Cache można bezpiecznie wyłączyć. Odczyt przechodzi wtedy do callbacku i
PostgreSQL. Wyjątek cache nie może zmienić wyniku operacji finansowej.

Cache obejmuje publiczne menu, języki, banner, bezpieczne ustawienia, kursy walut
do prezentacji oraz JWKS OIDC. Kursy nie są pobierane z NBP z procesu HTTP;
aktualizuje je polecenie/scheduler. Salda, historia transakcji, statusy wypłat i
decyzje autoryzacyjne nie są cache'owane jako źródło prawdy.

## Rate limiting

Liczniki Valkey używają atomowego `INCRBY` z TTL. Klucze zawierają hashe adresu
e-mail i IP, nie jawne dane osobowe. PostgreSQL nadal przechowuje zdarzenia
logowania do audytu. Jeżeli Valkey jest niedostępny, dotychczasowe zapytania
kontrolne do `auth_login_events` pozostają bezpiecznym fallbackiem.

## Blokady

Lock powstaje przez `SET NX PX` i posiada losowy token. Zwolnienie odbywa się
atomowym skryptem porównującym token, więc proces nie może usunąć cudzej blokady.

Locki Valkey służą wyłącznie do krótkiej koordynacji i ograniczania zbędnej pracy.
Nie zastępują transakcji PostgreSQL, `FOR UPDATE`, unikalnych indeksów ani
idempotencji finansowej.

## Sygnały kolejek

`ValkeyQueueSignal` utrzymuje ograniczoną listę identyfikatorów trwałych zadań.
Dodanie e-maila lub zadania najpierw utrwala rekord w PostgreSQL, a dopiero potem
wysyła sygnał. Utrata sygnału nie traci zadania: worker nadal odpytuje bazę.
ETAP 6 wdraża leasing `FOR UPDATE SKIP LOCKED`, kontrolowany retry, idempotencję
i dead-letter; Valkey nadal nie jest źródłem prawdy.

## Kontrola

```powershell
docker compose up -d --build
docker compose exec app-1 php scripts/migrate.php
docker compose exec app-1 php vendor/bin/phpunit tests/Integration/ValkeyIntegrationTest.php
docker compose exec app-2 php vendor/bin/phpunit tests/Integration/SharedSessionFallbackTest.php
Invoke-RestMethod http://localhost:8080/health/ready
```

Readiness kontroluje `php_redis` i `PING` Valkey. Liveness nie zależy od usług
zewnętrznych.
