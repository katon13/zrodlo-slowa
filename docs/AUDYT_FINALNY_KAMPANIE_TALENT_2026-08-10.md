# Audyt finalny: Kampanie, Talent, polemiki i powiadomienia

Data odbioru: 10 sierpnia 2026 r.

Repozytorium: `X:\zrodlo-slowa`

Środowisko: lokalne, developerskie, PostgreSQL, dwie instancje aplikacji

## Wynik

Wdrożenie jest spójne z istniejącą księgą, kolejkami i `TalentService`. Nie powstał drugi portfel ani równoległy mechanizm nagród. Kampania ustala koszt reklamodawcy, a nagroda TT nadal przechodzi przez istniejący Talent, kolejkę i ledger.

Status końcowy przed testem polecenia na dwóch fizycznych telefonach:

`READY_FOR_PHYSICAL_REFERRAL_E2E`

## Co zostało domknięte

- Panel „Kampanie i zaangażowanie” obsługuje cztery rzeczywiste formaty: baner, film, artykuł sponsorowany oraz ankietę/sondaż.
- Kampania przechowuje materiał, miejsce emisji, budżet, stawkę, okres i potwierdzenie zlecenia. Reklamodawca jest rozliczany wyłącznie za potwierdzony efekt.
- Baner rozlicza potwierdzone przejście, film wymaga minimalnego czasu, artykuł sponsorowany korzysta z istniejącego dowodu przeczytania, a ankieta z istniejącej kompletnej odpowiedzi.
- Artykuł sponsorowany nie tworzy drugiej nagrody obok `article_read_bonus`. Ankieta nie tworzy drugiej nagrody obok `survey_reward`.
- Snapshot TT i kwalifikacji trafia do istniejącego zadania. Późniejsza zmiana reguły nie zmienia już należnego wyniku.
- Program Talent pokazuje osiem działających reguł: rejestracja, aktywna wizyta, przeczytanie tekstu, ankieta, kliknięcie reklamy, obejrzenie filmu, potwierdzony błąd oraz opublikowana opinia/polemika.
- Zgłoszenie błędu ma publiczny formularz, ochronę antyspamową, załącznik z podglądem, kolejkę redakcyjną i jednorazową nagrodę dopiero po akceptacji.
- Polemika zachowuje kaucję, snapshot, idempotencję i ledger. Kaucję może pobrać wyłącznie świadome wysłanie przez właściciela.
- Informacja o opiniach i polemikach pozostaje wyłącznie pod artykułem. Usunięto ją z menu i ze strony „Jak zarabiać”.
- Referral zachowuje snapshot `reward_points` z chwili wysłania, limity 3/3 i ukrywa całą promocję po wyczerpaniu puli. Wyłączenie promocji przez administratora działa bez blokowania zmiany wartości.
- Etykieta „PROMOWANE” jest biała na czerwonym tle i ma tłumaczenia PL/EN/DE/FR/IT/ES. Landing polecenia również korzysta z pełnych tłumaczeń.
- Panel polecenia jest ostatnią sekcją portfela, więc nie rozdziela podstawowych informacji finansowych.
- Awatar w nagłówku ma wymuszony kwadratowy wymiar, kadrowanie i okrąg `50%`.
- Badge powiadomień korzysta z backendowego stanu nieprzeczytanych zdarzeń; testy obu aplikacji mobilnych pozostały zielone.
- Runtime i instalator są PostgreSQL-only. Usunięto dwa nieużywane, nieautoloadowane pliki starego namespace `Book100`, które uniemożliwiały pełną analizę statyczną.

## Widoczność dla operatora

Administrator ma w jednym systemie:

- `Ustawienia → Program Talent`: osiem reguł, punkty, limity, aktywacja, promocja instalacji, daty, limity 3/3 i kontrola 3DORS;
- `Kampanie i zaangażowanie`: formularze w krokach, upload/dropzone i podgląd, miejsce emisji, koszt efektu, budżet, daty, stan i raport reklamodawcy;
- `Zgłoszenia błędów`: lista zgłoszeń, opis, sposób odtworzenia, załącznik, decyzja i stan nagrody;
- raport kampanii: potwierdzone efekty, unikalni użytkownicy, koszt, wykorzystanie i pozostały budżet;
- normalny Program Talent, ledger i kolejki — bez technicznego drugiego systemu.

## Reset developerski

Wykonano pełny reset lokalnej bazy `zrodlo_slowa`, odtworzono strukturę i wszystkie 21 migracji, a następnie przywrócono jedyne konto administratora. Stan po resecie:

- 1 użytkownik (administrator),
- 0 kampanii i zdarzeń kampanii,
- 0 zgłoszeń błędów,
- 0 transakcji portfelowych,
- poprawny łańcuch i saldo ledgeru.

Logowanie wykorzystuje dotychczasowe lokalne hasło z `.env`; sekret nie jest częścią repozytorium ani archiwum audytowego.

## Weryfikacja

- PHPUnit Unit: 108 testów, 3832 asercje — OK.
- PHPUnit Integration: 129 testów, 861 asercji — OK; 9 testów starego workflow redakcyjnego pominięto z powodu braku wymaganych artykułów/tłumaczeń-fixtures w czystej bazie testowej.
- Dedykowane testy kampanii/Talentu: film i minimalny czas, snapshot po wyłączeniu reguły, artykuł bez podwójnej nagrody, ankieta bez podwójnej nagrody, błąd odrzucony/zaakceptowany i idempotencja — OK.
- PHPStan poziom 5, pełne `app`, `public/index.php` i `scripts`: 0 błędów.
- Lint wszystkich plików PHP: OK.
- JSON katalogów tłumaczeń: OK.
- Audyt tłumaczeń publicznych w sześciu językach: OK.
- Android `zrodlo-slowa-android`: `gradlew test` — OK.
- Android `3dors-android`: `gradlew test` — OK.
- Audyt proxy: 300 stron, 5974 linki i formularze, 14 przekierowań; odpowiedzi z `app-1` i `app-2`, 0 błędów.
- Kontrola wizualna po prawdziwym logowaniu administratora: OK.
- Kontrola instalacji i migracji: OK.
- Weryfikacja ledgeru: OK.

## Materiał wizualny

- `docs/screenshots/kampanie-talent-final/01-program-talent.png`
- `docs/screenshots/kampanie-talent-final/02-kampanie-i-zaangazowanie.png`
- `docs/screenshots/kampanie-talent-final/03-zgloszenia-bledow.png`
- `docs/screenshots/kampanie-talent-final/04-zglos-blad.png`

## Jedyny pozostały odbiór ręczny

Test polecenia aplikacji na dwóch fizycznych telefonach: zaproszenie e-mail → instalacja → pierwsza rejestracja → pierwsza prawidłowa sesja → dwie atomowe nagrody TT. Kod, migracje, serwer i aplikacja mobilna są przygotowane do tego testu.
