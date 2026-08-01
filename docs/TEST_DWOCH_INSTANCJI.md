# Test dwóch instancji — ETAP 8

ETAP 8 ma jeden deterministyczny test akceptacyjny. Test wykonuje prawdziwe
żądania HTTP i sprawdza cały scenariusz przełączenia `app-1` → `app-2`:

1. tworzy tymczasowe konto czytelnika, pusty portfel, wpis cache i obraz WebP;
2. loguje użytkownika bezpośrednio na `app-1`;
3. wysyła kolejne uwierzytelnione żądanie bezpośrednio do `app-2`;
4. ponawia logowanie na `app-2` i potwierdza idempotencję zadań oraz naliczeń;
5. zatrzymuje tylko `app-1`;
6. przez `http://localhost:8080` potwierdza działanie sesji na `app-2`;
7. sprawdza wspólny cache Valkey i ten sam plik w MinIO;
8. uruchamia ponownie `app-1` i usuwa wszystkie tymczasowe dane.

Test jest zablokowany poza `APP_ENV=local`. Nie usuwa wolumenów, nie resetuje
bazy i nie dotyka Laragona. Porty `80`, `443` i `3306` pozostają wolne dla
Laragona. Avid również pozostaje bez zmian; konsola MinIO działa na `19001`.

## Uruchomienie

W PowerShell, w katalogu `X:\zrodlo-slowa`:

```powershell
docker compose up -d --build
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\docker_smoke.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stage8_acceptance.ps1
```

Skrypt zawsze próbuje przywrócić `app-1` i posprzątać dane testowe, także gdy
któryś warunek testu nie przejdzie. Końcowy kod wyjścia `0` oznacza, że spełniono
wszystkie punkty ETAPU 8.

## Kontrolowane dane finansowe

Test używa wyłącznie nowego, jednoznacznie oznaczonego portfela. Dwa logowania
generują te same dzienne klucze idempotencji `login_bonus` i `day_visit_bonus`.
Test wymaga dokładnie jednego zadania dla każdego klucza oraz dokładnie takiej
liczby logów, transakcji i punktów, jaka wynika z aktywnych reguł. Przed
usunięciem danych ta zgodność jest sprawdzana ponownie już po wyłączeniu `app-1`.
Brak danej reguły jest traktowany jak reguła wyłączona: zadanie nadal musi być
unikalne i zakończone, ale nie może powstać log, transakcja ani zmiana salda.

Test nie modyfikuje reguł nagród ani sald istniejących użytkowników.
