# Raport wdrożenia — 3DORS Wartownik: skalowanie i osobne okno

Data: 2026-08-11

Repozytorium: `X:\zrodlo-slowa`

Zakres: dostrojenie istniejącego Wartownika bez zmiany architektury 3DORS, sesji, recovery ani autoryzacji.

## Wynik

Wartownik zachowuje jeden istniejący model bezpieczeństwa, ale jego główny widok jest teraz ograniczony do informacji potrzebnych operatorowi:

- `Aktywne` pokazuje wyłącznie otwarte alerty wymagające reakcji;
- `Przyjmij` przenosi alert do `Przyjętych`, bez przedwczesnej archiwizacji;
- `Rozwiąż` przenosi alert do `Rozwiązanych`, a wpisy starsze niż 30 dni są widoczne w `Archiwum`;
- główny ekran pokazuje najwyżej 10 otwartych alertów, 20 aktywnych sesji i 20 grup prób logowania;
- pełne listy alertów, sesji i prób logowania mają serwerowe filtrowanie oraz strony po 25, 50 albo 100 rekordów;
- pojedyncze próby logowania są dostępne dopiero po rozwinięciu pięciominutowej grupy;
- źródłowe logi audytowe, bezpieczna archiwizacja i udział 3DORS pozostały bez zmian.

## Osobne okno i lekki sygnał

Przycisk `Otwórz w osobnym oknie` uruchamia ten sam Wartownik, na tej samej sesji administratora i z tymi samymi uprawnieniami. Widok nie zawiera menu ani stopki panelu i jest przeznaczony na drugi monitor.

Widoczna karta sprawdza raz na 60 sekund wyłącznie ograniczony odcisk otwartych alertów. Ukryta karta nie odpytuje serwera. Zmiana wyświetla prośbę o ręczne odświeżenie; nie przerywa pracy operatora i nie przeładowuje automatycznie formularzy.

## Wydajność i PostgreSQL

Dodano indeksy dla statusu i poziomu alertu, czasu prób logowania, rodzaju zdarzenia oraz wyszukiwania użytkownika. Zapytania list nie pobierają całych tabel do PHP, a liczniki operacyjne mają bezpieczne górne granice.

Test skali obejmuje jednocześnie:

- 10 050 aktywnych sesji;
- 300 prób logowania;
- 120 otwartych alertów.

Test potwierdza podgląd maksymalnie 20 sesji, podgląd maksymalnie 10 alertów, stronicowanie po 25 rekordów i ograniczenie licznika pełnej listy do 10 000 pozycji.

## Tłumaczenia

Nowe komunikaty pochodzą z `resources/lang/dors3.json`. Każdy katalog `PL / EN / DE / FR / IT / ES` ma ten sam komplet 229 kluczy sekcji Wartownika. Testy lokalizacji i skaner stałych tekstów UI przechodzą bez błędów.

## Walidacja

- PHPUnit: **258 testów, 55 708 asercji, 0 błędów, 9 testów środowiskowych pominiętych**;
- PHPStan poziom 5: **0 błędów**;
- test Wartownika: **12 testów, 209 asercji**;
- migracja `20260811_014_sentinel_panel_scaling`: zastosowana;
- app-1: readiness `ok`;
- app-2: readiness `ok`;
- obie instancje: ten sam obraz `sha256:b85079d47928b6bfc0e022297ed7c65fad6401341fb54c1f4421f0e8bb397dfc`;
- load balancer: 30/30 odpowiedzi HTTP 200 po przeładowaniu resolvera;
- przeglądarka: sześć języków, archiwum, osobne okno i endpoint sygnału — **PASS**.

## Materiał wizualny

- `docs/screenshots/3dors-wartownik-pl.png`
- `docs/screenshots/3dors-wartownik-en.png`
- `docs/screenshots/3dors-wartownik-archiwum-pl.png`
- `docs/screenshots/3dors-wartownik-osobne-okno-pl.png`

## Status

`READY_FOR_WARTOWNIK_OPERATIONAL_E2E`
