# Testy obciążeniowe — ETAP 9

ETAP 9 dostarcza powtarzalny test akceptacyjny oparty na k6. Nie jest to
marketingowy benchmark ani prognoza pojemności produkcji. Wynik opisuje wyłącznie
konkretną lokalną konfigurację, dane, czas i progi użyte w danym uruchomieniu.

## Zakres

Skrypt `scripts/stage9_load.ps1` wykonuje kolejno:

1. tworzy opublikowany artykuł, sześciu czytelników i izolowane portfele;
2. zatrzymuje `worker-earnings` i scheduler anchorów;
3. symuluje utratę workera po przejęciu zadania i odzyskuje wygasłą dzierżawę
   jako `retry`;
4. uruchamia k6 przez zwykłe proxy z `app-1` i `app-2`;
5. sprawdza, że HTTP działa, a zadania finansowe pozostają trwałe podczas awarii;
6. wznawia worker i wymaga dokładnie jednego naliczenia dla każdego klucza,
   poprawnych sald, jednej transakcji oraz spójnej księgi;
7. uruchamia tymczasowe `app-3` i `proxy-load`, po czym wymaga ruchu przez każdą
   z trzech instancji;
8. usuwa dane testowe i głowy ich łańcuchów, weryfikuje księgę, usuwa tymczasowe
   kontenery i przywraca worker oraz scheduler.

Scenariusze HTTP obejmują listę i odczyt artykułu, logowanie, ustawienia konta,
widok portfela/salda, współbieżne naliczenia jednego użytkownika oraz naliczenia
różnych użytkowników. Zwykłe ścieżki użytkowników nie zależą od działania workera.

## Uruchomienie

W katalogu projektu:

```powershell
docker compose up -d --build
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stage9_load.ps1
```

Test jest zablokowany dla adresu innego niż `http://localhost:8080` i wymaga
`APP_ENV=local`, PostgreSQL oraz uruchomionego workera naliczeń. Nie korzysta z
MySQL, sesji ani konfiguracji Laragona. Dodatkowe usługi profilu `loadtest` nie
publikują żadnego portu hosta.

Obraz `grafana/k6:2.1.0` jest przypięty do digestu. Pliki podsumowań są zapisywane
lokalnie jako `storage/loadtests/stage9-core.json` i
`storage/loadtests/stage9-scale.json`; katalog jest ignorowany przez Git.

## Kryteria

Domyślne progi obu profili:

- co najmniej 99% kontroli k6;
- mniej niż 1% błędnych żądań HTTP;
- p95 czasu żądania poniżej 2500 ms;
- co najmniej jedna odpowiedź z każdej oczekiwanej instancji.

Próg i liczbę VU można zmienić parametrami `-P95Ms`, `-ReadVus`,
`-SameUserVus` i `-ScaleVus` skryptu PowerShell. Niskopoziomowy scenariusz k6
obsługuje również zmienne `STAGE9_READ_DURATION` i `STAGE9_SCALE_DURATION` przy
bezpośrednim uruchomieniu kontenera. Zwiększanie parametrów wymaga osobnej oceny
zasobów; domyślne wartości są krótką próbą akceptacyjną.

Warstwa finansowa ma dodatkowe, twarde kryteria poza k6:

- jeden job, log naliczenia i transakcja dla jednego klucza idempotencji;
- dokładne saldo 3 punktów dla użytkownika scenariusza wspólnego i retry oraz
  1 punkt dla każdego pozostałego użytkownika, powiększone o ewentualne aktywne
  bonusy logowania i wizyty odczytane przed testem;
- dokładnie dwie próby zadania po jednym `lease_expired`;
- poprawna weryfikacja całej księgi przed i po cleanupie.

## Pomiar referencyjny z 1 sierpnia 2026

Pomiar wykonano lokalnie w Docker Desktop, z limitami zasobów zapisanymi w
`compose.yaml`. Nie należy ekstrapolować go na produkcję.

| Profil | Instancje | Żądania | Błędy HTTP | Kontrole | p95 |
|---|---:|---:|---:|---:|---:|
| core | 2 | 884 | 0% | 100% | 114,81 ms |
| scale | 3 | 1664 | 0% | 100% | 44,17 ms |

W profilu `core` zarejestrowano 446 odpowiedzi z `app-1` i 438 z `app-2`.
W profilu `scale` instancje `app-1`, `app-2` i `app-3` obsłużyły odpowiednio
454, 602 i 608 odpowiedzi.
Oba profile spełniły zadane progi, retry zakończył się w drugiej próbie, a
sprawdzenie sald i księgi zakończyło się powodzeniem. To wynik testu tej wersji,
nie obietnica przepustowości.

## Interpretacja i dalsze pomiary

Podsumowanie k6 pozwala porównać błędy, p95, liczbę żądań i dystrybucję między
instancjami. Sam wzrost liczby instancji nie dowodzi liniowego skalowania, bo
PostgreSQL, Valkey, host i limity kontenerów pozostają wspólne. Decyzje o indeksach,
pulach połączeń i zasobach wymagają dłuższego testu ze stałą intensywnością oraz
równoległego pomiaru bazy, długości kolejek, CPU, RAM i hit ratio cache.

Jeżeli test przerwie się przed cleanupem, nie uruchamiaj ręcznych szerokich poleceń
`DELETE`. Blok `finally` podejmie próbę zatrzymania workera, usunięcia wyłącznie
zasobów z walidowanym prefiksem ETAPU 9 i przywrócenia usług. Przed kolejnym
uruchomieniem sprawdź komunikat cleanupu oraz stan usług przez `docker compose ps`.
