# ŹRÓDŁA ZAROBKÓW — wdrożenie Z2–Z10

Data zamknięcia prac: 2026-08-01

Repozytorium: `X:\zrodlo-slowa`

Środowisko: lokalne/deweloperskie, Docker obok niezmienionego Laragona

## Wynik

System zarobków został przebudowany z modelu „enqueue i okresowe odpytywanie” na
kontrolowany przepływ SNAJPERA:

```text
potwierdzona aktywność lub obecność
→ krótki i idempotentny INSERT joba w PostgreSQL
→ COMMIT
→ sygnał Valkey
→ osobny worker
→ jawna decyzja biznesowa
→ dokładnie jedno księgowanie
→ powiadomienie pobrane kursorem i ACK po renderze
```

Żadna stawka bonusu nie została aktywowana. Wszystkie 16 kanonicznych reguł nadal
ma `points_amount=0`, `amount_minor=0`, `daily_limit=0`, `is_active=0`.

Saldo administratora `katon` po wdrożeniu i testach:

```text
points_balance = 129362
main_available_minor = 0
slowo_available_minor = 0
available_minor = 0
```

## Zrealizowane etapy

### Z2 — katalog reguł

- utworzono 16 kanonicznych rekordów przez migrację bezpieczną dla istniejących danych;
- wartości ze starego MySQL nie zostały skopiowane ani aktywowane;
- ponowne uruchomienie migracji niczego nie nadpisuje.

### Z3 — jawne decyzje

Worker zwraca rozróżnialne wyniki, między innymi: `missing_rule`, `inactive_rule`,
`zero_value`, `duplicate`, `daily_limit`, `user_inactive`, `talent_disabled`,
`wallet_disabled`, `antifraud_hold`, `not_present` i `awarded`.

### Z4 — jedna wizyta dzienna

- logowanie nie kolejkuje już `login_bonus` ani `day_visit_bonus`;
- `day_visit_bonus` może uruchomić wyłącznie ping widocznej, uwierzytelnionej karty;
- dzień biznesowy jest liczony w `Europe/Warsaw`;
- klucz nowego modelu jest wersjonowany jako `presence-day:YYYY-MM-DD`.

### Z5 — SNAJPER, Valkey i dokładnie jedno księgowanie

- heartbeat przeglądarki: 60 s;
- dzierżawa obecności Valkey: 90 s;
- tylko `visibilityState=visible`;
- worker oczekuje blokująco na Valkey, a PostgreSQL sprawdza awaryjnie co 60 s;
- brak Valkey nie usuwa trwałego joba i nie blokuje odpowiedzi HTTP;
- batch: maksymalnie 25 zadań;
- lease workera: 120 s;
- osobne, unikalne klucze operacji i transakcji punktowej/pieniężnej;
- po błędzie enqueue gorący klucz deduplikacji zostaje zwolniony do kontrolowanego ponowienia.

### Z6 — powiadomienia bez zapytań na każdej stronie

Usunięto globalny SELECT i przedwczesny UPDATE z `BaseController::view()`.
Przeglądarka korzysta tylko z lekkich endpointów:

- `POST /api/earnings/presence`;
- `GET /api/earnings/jobs/status?public_id=...`;
- `GET /api/earnings/notifications?after_id=...&limit=...`;
- `POST /api/earnings/notifications/ack`.

Status joba jest dostępny wyłącznie właścicielowi. Powiadomienie jest oznaczane jako
odczytane dopiero po wyrenderowaniu. Kursor korzysta z indeksu `(user_id, seen_at, id)`.

### Z7 — sprzedaż i wsparcie autora

Zakup tekstu oraz wsparcie zapisują outbox w tej samej transakcji co skutek
finansowy. Osobny `worker-notifications` materializuje komunikat autora. Unikalny
`source_event_key` chroni przed podwójnym komunikatem po awarii między INSERT-em a
potwierdzeniem joba.

Valkey przechowuje jedynie identyfikator najnowszego komunikatu dla użytkownika.
Ping nie wykonuje SELECT-u powiadomień, jeśli lokalny kursor już dogonił ten
identyfikator. Teksty zdarzeń sprzedaży i wsparcia są kompletne dla PL, EN, DE, FR,
IT i ES.

### Z8 — dowód przeczytania i uczciwe komunikaty

Samo otwarcie artykułu nie tworzy joba. SNAJPER wydaje losowy token w Valkey tylko
dla zalogowanego użytkownika mającego dostęp do treści. Akceptacja wymaga jednocześnie:

- co najmniej 30 s od czasu wydania tokenu po stronie serwera;
- co najmniej 30 s naliczonej widoczności karty;
- widocznej karty w chwili potwierdzenia;
- co najmniej 60% postępu w treści;
- zgodności użytkownika, artykułu i tokenu;
- niewygasłego tokenu (TTL 1800 s).

Kampanie i ankiety informują teraz o przyjęciu do przetworzenia, a nie o rzekomym
zaksięgowaniu. `campaign_events.is_rewarded=1` pojawia się dopiero po rzeczywistym
skutku workera. Zdarzenie odrzucone przez antyfraud nie obciąża budżetu kampanii.

### Z9 — diagnostyka administratora

Panel główny administratora pokazuje:

- heartbeat i tryb obu workerów;
- liczbę wake-upów Valkey oraz safety sweepów;
- stany `queued`, `retry`, `dead_letter` obu kolejek;
- średnie, P95 i maksymalne opóźnienie z ostatnich 24 h;
- rozkład decyzji workera;
- stan reguł, w tym aktywne reguły zerowe.

Widok jest tylko diagnostyczny i nie ma działania aktywującego wartości.

### Z10 — odporność i testy

Pokryte przypadki obejmują:

- idempotencję enqueue oraz kolizję klucza z innym typem/payloadem;
- pierwszą trwałą treść joba przy zmiennych danych requestu;
- rollback callbacku i sygnał dopiero po commit;
- utrzymanie joba przy awarii sygnału Valkey;
- odzyskanie wygasłej dzierżawy workera;
- dokładnie jedno księgowanie punktów i pieniędzy po ponowieniu;
- odmowy dla statusu oraz flag `talent_enabled` i `wallet_enabled`;
- obecność widocznej karty i odrzucenie błędnego dowodu;
- token czytania, minimalny czas, widoczność, postęp i replay;
- kursor, ACK oraz izolację właściciela joba;
- transactional outbox, rollback i dokładnie jeden komunikat;
- niezależne heartbeat obu workerów oraz snapshot diagnostyczny;
- komplet sześciu języków dla nowych komunikatów.

## Pomiary końcowe

Rzeczywisty test HTTP na `http://localhost:8080`:

| Kontrola | Wynik |
|---|---:|
| logowanie `katon` | HTTP 200, `/pl/admin` |
| panel diagnostyczny | HTTP 200 |
| endpoint powiadomień | HTTP 200, pusty kursor |
| token artykułu | wydany, próg 30 s / 60% |
| natychmiastowe potwierdzenie czytania | HTTP 422 |
| job za samo otwarcie artykułu | 0 |
| sygnał `worker-earnings` | odebrany |
| sygnał `worker-notifications` | odebrany |
| aktywne reguły | 0 |
| saldo administratora | 129362 TT, pieniądze 0 |

Stan bezczynny po wdrożeniu:

| Kontener | CPU | RAM | PIDs |
|---|---:|---:|---:|
| app-1 | 0.00% | 40.76 MiB / 512 MiB | 5 |
| app-2 | 0.04% | 40.32 MiB / 512 MiB | 5 |
| worker-earnings | 0.00% | 11.76 MiB / 384 MiB | 2 |
| worker-notifications | 0.00% | 10.86 MiB / 128 MiB | 2 |
| PostgreSQL | 0.07% | 75.59 MiB / 1 GiB | 11 |
| Valkey | 0.17% | 11.37 MiB / 256 MiB | 6 |

Zmiana z pustego pollingu co 2 s na safety sweep co 60 s redukuje puste cykle
PostgreSQL z około 43 200 do maksymalnie około 1 440 na dobę, czyli o około 96,7%.
Zdarzenia w normalnej pracy budzą worker natychmiast przez Valkey.

## Migracje

- `20260801_005_canonical_earnings_rules.sql`;
- `20260801_006_earnings_idempotency.sql`;
- `20260801_007_earnings_notification_cursor.sql`;
- `20260801_008_notification_outbox.sql`.

Wszystkie są zapisane w `schema_migrations`; ponowne `php scripts/migrate.php` zwraca
`already_applied`.

## Operacje lokalne

```powershell
docker compose exec -T app-1 php scripts/migrate.php
docker compose up -d app-1 app-2 worker-earnings worker-notifications
docker compose logs --tail=100 worker-earnings worker-notifications
docker compose ps
```

Nie wykonywać `docker compose down -v`. Worker AI, worker powiadomień i worker
naliczeń można zatrzymywać niezależnie od aplikacji HTTP.

## Laragon i porty

Laragon nie został zmieniony ani użyty przez Docker. Porty pozostają rozdzielone:

- strona: `127.0.0.1:8080`;
- PostgreSQL: `127.0.0.1:5433`;
- Valkey: `127.0.0.1:6380`;
- MinIO console: `127.0.0.1:19001` (port 9001 kontenera);
- Mailpit: `127.0.0.1:8025`.

Docker nie używa portów Laragona 80, 443 ani 3306 oraz nie korzysta z jego MySQL,
sesji ani konfiguracji.

## Nadal wymagana decyzja biznesowa

Kod jest gotowy do bezpiecznego włączenia reguł, ale wartości pozostają celowo
nieaktywne. Przed pierwszą aktywacją trzeba osobno zatwierdzić stawkę TT, ewentualną
kwotę pieniężną, limit, budżet, interwał oraz listę reguł. Stare liczby MySQL są
wyłącznie materiałem porównawczym.
