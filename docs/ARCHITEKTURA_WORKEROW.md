# Izolacja kolejek i workerów

## Reguła nadrzędna

Procesy HTTP obsługują uwierzytelnienie, odczyt artykułów, portfele i krótkie
operacje transakcyjne. Nie wykonują wywołań AI, tłumaczeń ani ciężkich prac
redakcyjnych. Kontroler po autoryzacji zapisuje trwałe zadanie i zwraca jego
identyfikator; właściwą pracę wykonuje osobny worker.

Zatrzymanie, restart, skalowanie do zera lub przeniesienie `worker-ai` na inny
serwer nie może wpływać na dostępność strony, logowania, artykułów, sald ani
naliczania zarobków.

## Podział kolejek

| Kolejka | Przeznaczenie | Priorytet | Domyślna równoległość |
|---|---|---:|---:|
| `earnings.critical` | zatwierdzone naliczenia finansowe | najwyższy | 1 na replikę |
| `email.transactional` | wiadomości transakcyjne | normalny | 1 na replikę, batch 2 |
| `admin.editorial` | ciężkie operacje redakcyjne i importy | niski | 1 |
| `admin.ai` | AI i tłumaczenia administracyjne | najniższy | 1 |

Jedna instancja workera obsługuje tylko wskazaną kolejkę. Worker administracyjny
nie może pobierać zadań finansowych, a worker finansowy zadań AI. Trwały rekord w
PostgreSQL jest źródłem prawdy; Valkey może służyć jako sygnał gotowości, ale jego
utrata nie może usunąć zadania.

## Autoryzacja i AI

- zadanie AI może utworzyć wyłącznie aktywny administrator lub członek redakcji
  z konkretnym uprawnieniem do operacji i zasobu;
- zwykły użytkownik nie widzi akcji AI i nie ma publicznego endpointu do
  bezpośredniego wywołania modelu;
- `worker-ai` ponownie sprawdza aktywność aktora, rolę, uprawnienie i zakres
  zasobu tuż przed rezerwacją kosztu;
- brak któregokolwiek warunku kończy zadanie jako odrzucone, bez wywołania
  dostawcy;
- każde przejście stanu jest audytowane z identyfikatorem aktora, zadania,
  zasobu, kluczem idempotencji, budżetem i wynikiem;
- limit kosztów jest atomowo rezerwowany przed wywołaniem, rozliczany po wyniku
  i zwalniany po bezpiecznie rozpoznanym błędzie;
- klucz idempotencji jest unikalny dla typu zadania, zasobu, wersji wejścia i
  żądania. Retry nie może tworzyć drugiego płatnego wywołania bez sprawdzenia
  poprzedniej próby.

## Limity wdrożeniowe

Początkowy budżet jednej repliki:

| Jednostka | CPU | RAM | połączenia DB | równoległość |
|---|---:|---:|---:|---:|
| `app-web` | 1 CPU | 512 MiB | maks. 10 | do 10 żądań Apache |
| `worker-earnings` | 0,75 CPU | 384 MiB | 1 na proces | 1 |
| `worker-email` | 0,50 CPU | 256 MiB | 1 na proces | 1, batch 2 |
| `worker-editorial` (opcjonalny, przyszły) | 0,50 CPU | 384 MiB | maks. 2 | 1 |
| `worker-ai` | 0,50 CPU | 512 MiB | 1 na proces | 1 |
| `scheduler` | 0,25 CPU | 192 MiB | 1 na proces | 1 |

`worker-ai` i `worker-editorial` otrzymują niższy udział CPU niż procesy WWW i
finansowe. Suma limitów pul połączeń wszystkich replik musi pozostawić co najmniej
20% `max_connections` PostgreSQL dla migratora, diagnostyki i rezerwy awaryjnej.
W lokalnym Compose PostgreSQL ma początkowo `max_connections=100`, a każda replika
WWW maksymalnie 10 procesów Apache, co ogranicza ją do najwyżej 10 równoległych
połączeń z bazą.

## Pobieranie, retry i zatrzymanie

- pobieranie zadań: krótka transakcja z `FOR UPDATE SKIP LOCKED`;
- lease z terminem ważności, kontrolowany backoff i ograniczona liczba prób;
- nieznany wynik płatnego wywołania AI nie jest automatycznie ponawiany;
- wyczerpane próby trafiają do stanu `dead_letter`/DLQ;
- `SIGTERM` zatrzymuje pobieranie nowych zadań, a bieżące zadanie kończy się w
  granicach `stop_grace_period` albo zwalnia lease;
- wyłączenie kolejki `admin.ai` nie blokuje pozostałych kolejek;
- osobne metryki obejmują opóźnienie kolejki, czas zadania, retry, DLQ, zajętość
  puli DB, koszt AI i odrzucenia autoryzacji/budżetu.

Reguły kolejek wdrożono w ETAPIE 6. Ciężkie prace redakcyjne wykorzystujące AI są
obecnie obsługiwane przez osobną kolejkę `admin.ai` i `worker-ai`; niezależny
`worker-editorial` jest przewidzianą jednostką wdrożeniową, ale nie istnieje jeszcze
w Compose. ETAP 7 przebudował księgę na łańcuchy per-portfel i okresowy Merkle root.
Archiwalny łańcuch globalny pozostaje do audytu, a zgodność sald i pełnej historii
kontroluje `scripts/verify_ledger.php`.
