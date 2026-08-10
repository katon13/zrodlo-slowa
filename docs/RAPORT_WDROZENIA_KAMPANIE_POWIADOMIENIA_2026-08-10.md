# Raport wdrożenia — Kampanie, Talent i powiadomienia

Data odbioru: 2026-08-10

Repozytorium: `X:\zrodlo-slowa`

Baza: PostgreSQL-only

Stan: wdrożone lokalnie na dwóch instancjach WWW i wspólnych workerach

## Wynik

Istniejący moduł kampanii został naprawiony i połączony z obecnym Programem Talent. Nie powstał drugi portfel, drugi ledger, nowa kolejka ani alternatywny system nagród. Reklamodawca płaci wyłącznie za potwierdzony efekt, a należne TT przechodzą przez istniejący `TalentService`, kolejkę i ledger.

Równocześnie istniejący system powiadomień udostępnia jedno backendowe `unread_count`, używane przez WWW i Androida. Nie dodano tabeli ani workera powiadomień.

## Kampanie — działający model

- Kampania ma zleceniodawcę, dane kontaktowe, numer zlecenia, budżet, daty, limit efektów i jeden rozliczany typ działania.
- Aktywacja wymaga potwierdzonego budżetu, dodatniej marży, działającego dowodu oraz aktywnej reguły Talent.
- Obecnie produkcyjnie gotowe są kliknięcie reklamy i obejrzenie materiału/reklamy.
- Ankieta, artykuł sponsorowany, PPV i transmisja pozostają szkicem do czasu podłączenia wiarygodnego dowodu. System nie udaje, że potrafi je rozliczyć.
- Kliknięcie przechodzi przez kontrolowane przekierowanie serwera.
- Obejrzenie używa jednorazowego dowodu serwera, minimalnego czasu i kontroli widoczności strony.
- Duplikat konta/kampanii/efektu/dnia nie zużywa budżetu i nie nalicza TT.
- Tylko zweryfikowane zdarzenie zwiększa koszt kampanii.
- Panel pokazuje potwierdzone, odrzucone i zduplikowane zdarzenia, koszt, TT i marżę.
- Publiczny front tłumaczy całość na PL, EN, DE, FR, IT i ES.

## Talent i finanse

- Kampania nie wypłaca użytkownikowi PLN.
- Dla zaakceptowanego efektu zapisywane są kwalifikacja reguły, `talent_points_snapshot`, typ aktywności i identyfikator joba.
- Worker odczytuje wartość TT zapisaną przy zdarzeniu. Późniejsza zmiana albo wyłączenie reguły przez administratora nie zmienia już należnej nagrody.
- Kolejka oraz zapis zdarzenia są wykonywane atomowo; błąd kolejki wycofuje transakcję.
- Idempotencja zdarzenia i istniejąca idempotencja Talentu zabezpieczają przed podwójnym kosztem oraz podwójną nagrodą.
- Raport finansowy pokazuje naliczony przychód kampanii i TT użytkowników bez przedstawiania TT jako wypłaty PLN.

## Powiadomienia WWW i Android

- Endpoint listy zwraca wspólne `unread_count`.
- WWW pokazuje badge przy avatarze: brak dla 0, liczba dla 1–99, `99+` od 100.
- Android pokazuje ten sam badge przy pozycji Powiadomienia w dolnym menu.
- Odczyt pojedynczego elementu natychmiast pobiera nową wartość z serwera.
- „Oznacz wszystkie jako przeczytane” używa istniejącego endpointu i zeruje wspólny licznik.
- Android odświeża licznik z tego samego API podczas aktywnej sesji; nie utrzymuje osobnego źródła prawdy.
- Wszystkie nowe komunikaty aplikacji mobilnej mają warianty PL, EN, DE, FR, IT i ES.

## Panel i widoki dostępne do sprawdzenia

1. `Admin → Kampanie i Zaangażowanie` — kreator zlecenia w czterech sekcjach.
2. W formularzu widoczne jest tylko jedno pole ceny, właściwe dla wybranego efektu.
3. Kontrola przed aktywacją pokazuje gotowość dowodu i reguły Talent.
4. Lista kampanii pokazuje pozostały budżet, potwierdzenia, odrzucenia i duplikaty.
5. Raport kampanii pokazuje dowód, koszt, TT i marżę.
6. `Ustawienia → Program Talent` steruje stawkami `ad_click_reward` i `ad_view_reward`.
7. `Admin → Raport finansowy` pokazuje przychód kampanii oraz naliczone TT.
8. `/campaigns` wyjaśnia użytkownikowi i reklamodawcy zasady rozliczenia za efekt.
9. Badge WWW jest widoczny przy avatarze tylko przy nieprzeczytanych powiadomieniach.
10. Badge Android jest widoczny przy ikonie Powiadomienia; długa etykieta nie jest obcinana.

## Migracja i dwie instancje

Migracja `20260810_011_verified_campaign_engagement` rozszerza istniejące `campaigns` i `campaign_events`. Nie dodaje systemu nagród. Została zastosowana z checksumą:

`b6bec116e55d66e254d6b5648a157d0bdbef066393191f0734a5a00592585766`

Naprawiono również przenośność migratora: treść SQL ma tę samą checksumę dla checkoutu LF i CRLF. Zwykłe `scripts/migrate.php` na istniejącej bazie nie próbuje już ponownie zakładać administratora ani wymagać jego hasła.

Ostateczny obraz uruchomiony podczas odbioru:

`sha256:49593104bfaff6b1c0da1a7eff5b0f728e8f24a9b117af0dc3a25326eb69b439`

Ten sam obraz działał na:

- `app-1` — healthy, PostgreSQL ready;
- `app-2` — healthy, PostgreSQL ready;
- `worker-earnings`;
- `worker-notifications`;
- `worker-email`;
- `worker-ai`;
- `scheduler`.

Sześć kolejnych żądań do proxy przełączało odpowiedzi naprzemiennie między `app-1` i `app-2`. Widoki `/pl/campaigns`, `/en/campaigns`, `/de/campaigns`, `/fr/campaigns`, `/it/campaigns` i `/es/campaigns` zwróciły HTTP 200.

## Testy

- PHPUnit: **227 testów, 4229 asercji, 0 błędów, 9 testów pominiętych warunkowo**.
- PHPStan dla wszystkich zmodyfikowanych klas produkcyjnych: **0 błędów**.
- Android `testDebugUnitTest`: **BUILD SUCCESSFUL**.
- Android test wizualny na emulatorze Pixel 9 / Android 17: **1 test, OK**; sprawdzono `99+` na rzeczywistym komponencie dolnego menu.
- Testy obejmują snapshot TT po zmianie reguły, duplikat bez kosztu, brak aktywacji nieweryfikowalnych typów, wartości licznika 0/1/kilka/99+, odczyt jednego i wszystkich oraz zgodność kontraktu WWW/Mobile.

Pełny repozytoryjny PHPStan nadal zgłasza 35 wcześniejszych problemów w pomocniczym module `Book100` i starych skryptach fixture/stage. Zmiana ich nie dotyka; wynik dla zmodyfikowanej warstwy produkcyjnej jest czysty.

## Materiał wizualny

- `docs/screenshots/kampanie-admin-powiadomienia-www.png`
- `docs/screenshots/kampanie-publiczne-www.png`
- `docs/screenshots/powiadomienia-badge-mobile.png`

Sesja i powiadomienie demonstracyjne użyte wyłącznie do wykonania zrzutów zostały usunięte. Kontrola końcowa wykazała 0 pozostałych sesji i 0 demonstracyjnych powiadomień.

## Ograniczenia odbioru

- Nie utworzono sztucznej aktywnej kampanii w danych użytkownika; panel i publiczny stan pusty są rzeczywistym stanem developerskim.
- Publikacja aplikacji w sklepie nie była częścią tego wdrożenia. Kod Androida został zbudowany i sprawdzony lokalnie oraz na emulatorze.
- Fizyczny test polecenia aplikacji na dwóch telefonach pozostaje osobnym krokiem E2E. Stan przygotowania istniejącego referral pozostaje: `READY_FOR_PHYSICAL_REFERRAL_E2E`.
