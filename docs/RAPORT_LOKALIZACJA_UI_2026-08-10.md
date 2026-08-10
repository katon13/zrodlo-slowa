# Raport końcowy — katalogi tekstów interfejsu WWW

Data weryfikacji: 2026-08-10  
Repozytorium: `X:\zrodlo-slowa`  
Zakres języków: PL / EN / DE / FR / IT / ES

## Wynik

Stałe teksty interfejsu publicznego, kont użytkowników, autora, komentatora, redakcji i administracji zostały przeniesione do katalogów JSON. Logika biznesowa, Talent, ledger, kolejki, kampanie i rozliczenia nie zostały zastąpione nowymi mechanizmami.

Podział katalogów:

- `resources/lang/public.json` — publiczne WWW, konto, autor, komentator, portfel i formularze użytkownika;
- `resources/lang/admin.json` — administracja, redakcja, kampanie, ankiety i komunikaty operatora;
- `resources/lang/safety_fund.json` — ekonomia, Talent, ledger i Safety Fund;
- `resources/lang/dors3.json` — interfejs bezpieczeństwa 3DORS.

Łącznie płaskie katalogi zawierają 2477 kluczy: publiczny 940, administracyjny 1330 i Safety Fund 207. Każdy wpis ma komplet sześciu wersji językowych. Drzewo 3DORS ma identyczną strukturę dla wszystkich języków.

## Zmiany widoczne

- wszystkie nagłówki, opisy, przyciski, etykiety, statusy i komunikaty formularzy korzystają z katalogów JSON;
- ujednolicono nazwy ról, stanów artykułów, ankiet, kampanii, poleceń aplikacji, wypłat, maili i antyfraudu;
- usunięto z paneli identyfikatory zadań i transakcji, których operator nie potrzebuje do obsługi;
- komunikaty AJAX korzystają z tych samych tłumaczeń co widoki serwerowe;
- dodano faviconę, dzięki czemu przeglądarka nie generuje błędu zasobu;
- zachowano dotychczasowy styl publicznego WWW i panelu administracyjnego.

## Ochrona przed powrotem tekstów wpisanych w kodzie

Test `tests/Unit/UiLocalizationArchitectureTest.php` kontroluje:

- komplet PL / EN / DE / FR / IT / ES;
- zgodność placeholderów między językami;
- istnienie wszystkich używanych kluczy;
- brak surowych tekstów w HTML i atrybutach interfejsu;
- brak tekstów UI wpisanych w kontrolerach;
- brak literalnych komunikatów przypisywanych przez JavaScript;
- brak literalnych etykiet i podpowiedzi w metadanych widoków.

Dodatkowo skrypty `scripts/localize_static_views.py` i `scripts/localize_controller_ui.py` działają jako powtarzalny skaner. Końcowy przebieg obu narzędzi zwrócił po 0 zmian oczekujących.

## Testy końcowe

| Kontrola | Wynik |
| --- | --- |
| Pełne testy jednostkowe | 114/114, 51 227 asercji |
| Pełne testy integracyjne | 130/130, 863 asercje, 9 scenariuszy środowiskowych pominiętych |
| Testy architektury tłumaczeń | 10/10, 48 685 asercji |
| PHPStan, tryb szeregowy | brak błędów |
| PHP lint: `app`, `views`, `tests`, `scripts` | brak błędów składni |
| `git diff --check` | czysto |
| Klucze bez tłumaczeń | 0 |
| Klucze wyświetlone użytkownikowi zamiast treści | 0 |
| Oczekujące zamiany tekstów widoków/kontrolerów | 0 / 0 |

## Dwa serwery i przeglądarka

- `app-1`: healthy, `/pl` = HTTP 200;
- `app-2`: healthy, `/pl` = HTTP 200;
- proxy `http://localhost:8080`: healthy;
- strony `/pl`, `/en`, `/de`, `/fr`, `/it`, `/es`: HTTP 200;
- logowanie `katon` i wejście do `/admin/settings`: HTTP 200;
- test przeglądarkowy: brak błędów konsoli, brak błędnych odpowiedzi zasobów i brak ujawnionych kluczy tłumaczeń.

## Zrzuty ekranu

- [Publiczne WWW — Jak zarabiać](screenshots/lokalizacja-ui/publiczne-www-jak-zarabiac.png)
- [Panel administracyjny — Ustawienia i Talent](screenshots/lokalizacja-ui/panel-admina-ustawienia-talent.png)

## Status

Warstwa WWW i administracyjna jest gotowa do dalszego strojenia tekstów bez zmian w PHP: treść można zmieniać bezpośrednio w odpowiednim wpisie JSON. Dla wcześniej wdrożonego polecania aplikacji pozostaje etap testu na dwóch fizycznych telefonach.

`READY_FOR_PHYSICAL_REFERRAL_E2E`
