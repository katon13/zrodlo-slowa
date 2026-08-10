# 3DORS Wartownik — architektura i obsługa

Data wdrożenia: 2026-08-08  
Zakres: warstwa obserwacji i alarmowania istniejącego systemu Źródło Słowa / 3DORS

## Granica odpowiedzialności

3DORS Wartownik nie jest silnikiem bezpieczeństwa. Nie podpisuje operacji, nie zmienia decyzji backendu, nie wykonuje blokad i nie zastępuje 3DORS Admin ani 3DORS Author.

```text
polityki backendu + fail-closed + 3DORS
    → wykonują kontrolę i decyzję

security_events + heartbeat instancji
    → Wartownik obserwuje, agreguje, klasyfikuje i alarmuje
```

Panel `BEZPIECZEŃSTWO I 3DORS` pozostaje miejscem konfiguracji: kont, ról, urządzeń, enrollmentu, recovery, FIDO2 i mechanizmów potwierdzania. Panel `3DORS WARTOWNIK` zawiera stan systemu, próby logowania, aktywne sesje, gotowość wykonawców, alerty i historię ochrony.

## Źródła danych

- `security_events` — istniejąca, historyczna ewidencja zdarzeń ochrony;
- `auth_login_events` — wynik prób logowania;
- `sessions` — współdzielone sesje, prezentowane wyłącznie przez skrócony identyfikator;
- `security_mobile_devices` i polityki operacji — gotowość 3DORS Admin/Author;
- status WebAuthn/FIDO2 — jawnie rozróżnia fundament od pełnej autoryzacji;
- `security_instance_heartbeats` — niezależny heartbeat `app-1` i `app-2` z `/health/ready`.

Brak zdarzeń nie jest uznawany za dowód zdrowia instancji. Aktualność heartbeat jest oceniana względem `SENTINEL_HEARTBEAT_STALE_SECONDS` (domyślnie 45 sekund).

## Alerty

Każde nowe zdarzenie `high` lub `critical`, powstałe po aktywacji Wartownika, otrzymuje osobny rekord w `security_alerts`. Historyczne zdarzenie źródłowe nie jest zmieniane.

Pierwsza migracja zapisuje `security_sentinel_state.activated_at`. Synchronizacja nie tworzy alertów sprzed tego punktu, dzięki czemu wdrożenie nie wysyła historycznej lawiny powiadomień. Jednocześnie wcześniejsze zdarzenia nadal są dostępne w historii i filtrach SQL.

Cykl alertu:

```text
open → acknowledged → resolved
  └────────────────────→ resolved
```

Każda zmiana wymaga administratora i uzasadnienia. Przejście zapisuje aktora, czas, `request_id`, `correlation_id` i `instance_id` w `security_alert_transitions`. Nie można ponownie otworzyć ani ponownie rozwiązać alertu zakończonego.

## Powiadomienia

Dla `high` i `critical` powstaje po jednym zadaniu e-mail dla każdego aktywnego administratora. Ochronę przed duplikacją zapewnia unikalność `(alert, administrator, kanał)` oraz klucz idempotencji kolejki pocztowej. Scheduler synchronizuje alerty i przekazuje oczekujące powiadomienia do istniejącej `mail_queue`; awaria tej projekcji nigdy nie zmienia wyniku chronionej operacji.

## Prywatność widoku operatora

- adresy e-mail i IP są maskowane;
- identyfikator sesji jest hashowany i skracany;
- dowolne opisy zdarzeń są zastępowane etykietą „szczegóły ukryto”;
- `before_state`, `after_state`, `metadata`, tokeny i pełne payloady nie są renderowane;
- widoczne pozostają bezpiecznie zwalidowane `request_id`, `correlation_id` i `instance_id`, potrzebne do korelacji diagnostycznej.

Wyszukiwanie, filtry obszaru, zakres dat i paginacja są wykonywane w PostgreSQL. Panel ma katalogi PL/EN i nie zmienia języka ani kontraktu nazw maszynowych zdarzeń.

## Status zbiorczy

- `KRYTYCZNE` — aktywny alert krytyczny albo instancja jawnie raportuje brak gotowości;
- `WYSOKIE RYZYKO` — aktywny alert high;
- `UWAGA` — nieaktualny/brakujący heartbeat lub alert przyjęty, ale nierozwiązany;
- `BRAK DANYCH` — brak aktualnego heartbeat wszystkich oczekiwanych instancji;
- `OK` — brak aktywnych alertów i aktualna gotowość obu instancji.

Pełny WebAuthn/FIDO2 nadal pozostaje późniejszą bramką produkcyjną. Wartownik nie przedstawia samego fundamentu biblioteki jako działającej pełnej autoryzacji.

## Pliki kluczowe

- `database/postgresql/migrations/20260808_006_3dors_sentinel.sql`
- `app/Services/Dors3SentinelAlertService.php`
- `app/Services/Dors3SentinelService.php`
- `app/Controllers/Dors3SentinelController.php`
- `views/admin/dors3_sentinel.php`
- `resources/lang/dors3.json`
- `tests/Integration/Dors3SentinelIntegrationTest.php`
- `tests/Unit/Dors3SentinelContractTest.php`

## Odbiór operacyjny

Po wdrożeniu należy potwierdzić:

1. `/health/ready` osobno na `app-1` i `app-2` zapisuje świeży heartbeat;
2. nowe zdarzenie wysokiego lub krytycznego ryzyka tworzy dokładnie jeden alert;
3. ponowiona synchronizacja i wysyłka nie duplikują alertu ani e-maila;
4. przyjęcie i rozwiązanie alertu zapisują osobne przejścia, bez zmiany zdarzenia źródłowego;
5. PL/EN, filtrowanie, zakres dat, wyszukiwanie i paginacja działają na panelu;
6. widok nie ujawnia surowych payloadów ani pełnych danych osobowych.
