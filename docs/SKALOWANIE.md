# Skalowanie

## Punkt wyjścia

Środowisko referencyjne ma dwie wymienne instancje HTTP za Nginx, współdzielone
sesje Valkey, PostgreSQL i magazyn S3/MinIO oraz osobne procesy naliczeń, poczty, AI
i schedulera. Profil `loadtest` dodaje trzecią instancję i osobny wewnętrzny proxy,
nie publikując nowego portu hosta.

Skalowanie nie może zmieniać reguł finansowych. Liczba instancji wpływa na
przepustowość, nie na liczbę skutków biznesowych.

## Co można skalować niezależnie

| Warstwa | Sygnał do skalowania | Sposób |
|---|---|---|
| HTTP | trwałe p95, błędy, CPU i liczba aktywnych żądań | kolejne identyczne instancje za load balancerem |
| naliczenia | wiek i długość `earnings.critical`, retry | dodatkowe workery z tą samą kolejką |
| e-mail | wiek kolejki i limit dostawcy | dodatkowy worker z ograniczoną paczką |
| AI | zaakceptowany budżet, wiek kolejki administracyjnej | osobny host/worker o niższym priorytecie |
| scheduler | tylko HA z pojedynczym wykonaniem | lock/lease; nie zwiększać bez potrzeby |
| PostgreSQL | czas zapytań, blokady, I/O, wykorzystanie puli | indeksy i tuning przed większą instancją |
| Valkey | opóźnienia, pamięć, evictions | limit pamięci/TTL, potem usługa zarządzana |
| S3 | opóźnienia i błędy dostawcy | adapter, retry i bezpośrednie strumieniowanie |

## Warunki stateless HTTP

- brak trwałego zapisu na lokalnym dysku kontenera;
- sesja współdzielona, bez sticky sessions;
- cache zawiera wyłącznie dane możliwe do odtworzenia;
- upload przez `ObjectStorageInterface`;
- zadanie asynchroniczne najpierw trafia do trwałego zapisu;
- request ID i identyfikator instancji są obecne w logach;
- readiness usuwa niezdatną instancję z ruchu.

Load balancer powinien rozdzielać ruch tylko między zdrowe instancje i kończyć
żądania w sposób kontrolowany podczas rolling update. Liveness nie może zależeć od
zewnętrznego API, a readiness ma krótkie timeouty.

## Budżet połączeń i zasobów

Przed dodaniem instancji należy policzyć maksymalną liczbę połączeń wszystkich
procesów:

```text
HTTP + earnings + email + AI + scheduler + migracje + rezerwa operacyjna
<= bezpieczny limit PostgreSQL
```

Każda rola ma osobny `DB_APPLICATION_NAME`, limit CPU/RAM/PID i ograniczoną
współbieżność. AI ma najniższy priorytet i nie może zużyć rezerwy potrzebnej dla
HTTP i finansów. Zwiększenie liczby workerów bez sprawdzenia blokad bazy może
pogorszyć wynik.

Repliki odczytowe są opcją przyszłą. Dopóki routing read/write nie gwarantuje
read-your-writes i nie rozdziela odczytów finansowych, aplikacja korzysta z jednego
autorytatywnego endpointu PostgreSQL. Nie wdrażamy repliki wyłącznie „na zapas”.

## Metoda podejmowania decyzji

1. Ustal reprezentatywny scenariusz i niezmienne progi poprawności.
2. Zapisz wersję kodu, konfigurację, liczbę instancji i limity hosta.
3. Zmierz błędy, p95, rozdział ruchu, CPU, RAM, bazę, kolejki i retry.
4. Usuń wykazane wąskie gardło: zapytanie, indeks, N+1, lock albo zbyt szeroką pracę.
5. Dopiero wtedy zwiększ jedną warstwę i powtórz identyczny test.
6. Porównaj poprawność finansową, nie tylko liczbę żądań.

Lokalne wyniki k6 są bramką regresji, a nie obietnicą wydajności produkcyjnej.
Dokument [Testy obciążeniowe](TESTY_OBCIAZENIOWE.md) podaje scenariusze, progi i
ostatni pomiar.

## Odporność na awarie

- wyłączenie jednej instancji HTTP nie przerywa sesji ani dostępu do plików;
- zatrzymanie Valkey powoduje kontrolowaną degradację, nie utratę finansów;
- utrata sygnału kolejki nie usuwa trwałego zadania PostgreSQL;
- worker po restarcie odzyskuje wygasły lease;
- zatrzymanie `worker-ai` nie blokuje żadnej ścieżki użytkownika;
- przełączenie bazy wymaga sprawdzenia migracji, spójności i księgi przed ruchem;
- magazyn obiektów musi zachować klucze i politykę dostępu po zmianie dostawcy.

## Koszt i prostota

Docelowy minimalny zestaw to load balancer, co najmniej dwie instancje aplikacji,
zarządzany PostgreSQL, zarządzany Valkey, S3 i niezależne workery. Dodatkowy broker,
Kubernetes, repliki, WAF, SIEM i wieloregionowość są decyzjami opartymi na ryzyku i
pomiarach, nie warunkiem startu. Plan wdrożenia opisuje
[WDROZENIE_CHMURA.md](WDROZENIE_CHMURA.md).
