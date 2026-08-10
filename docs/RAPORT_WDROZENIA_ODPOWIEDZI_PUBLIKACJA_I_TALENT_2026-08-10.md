# Raport wdrożenia — Odpowiedź publikacją i porządkowanie Programu Talent

Data: 10.08.2026  
Repozytorium: `X:\zrodlo-slowa`  
Środowisko: PostgreSQL, dwa węzły aplikacyjne `app-1` i `app-2`

## Wynik

Wdrożono model „Opinie zamiast komentarzy”. Odpowiedź jest osobną, podpisaną publikacją redakcyjną, a nie komentarzem. Reguła `response_publication_bonus` korzysta z istniejących portfeli TT, kolejki zadań, `TalentService` i ledgera. Nie powstał drugi system nagród ani drugi portfel.

`comment_bonus` został usunięty z działającego modelu i z bazy. Historyczna, wcześniej zastosowana migracja pozostała niezmieniona; usunięcie starej reguły i wprowadzenie nowej wykonuje wyłącznie migracja `20260810_009_response_publications`.

## Zasada naliczenia TT

- szkic, wysłanie do redakcji i odrzucenie: 0 TT;
- pierwsza faktyczna publikacja przez redakcję: jedyny moment kwalifikacji;
- przy publikacji w tej samej transakcji zapisywane są: kwalifikacja aktywnej reguły, `points_amount`, identyfikator joba i czas zakolejkowania;
- job ma kontekst ze snapshotem, a worker weryfikuje go względem rekordu artykułu;
- późniejsze wyłączenie reguły albo zmiana liczby TT nie zmienia już należnej nagrody;
- edycja i rewizja publikacji nie tworzą kolejnej nagrody;
- idempotencja jest oparta na `article_id`, publicznym identyfikatorze joba i kluczu księgi;
- nagroda jest wyłącznie w TT: `amount_minor=0`, bez PLN;
- błąd zapisu joba powoduje rollback publikacji i snapshotu.

## Kaucja TT za wysłanie opinii lub polemiki

- wysokość kaucji administrator ustawia w `Ustawienia → Program Talent` na karcie `Opublikowana opinia lub polemika`;
- `0 TT` wyłącza kaucję, ale nie zmienia ustawień samej nagrody za publikację;
- kwota jest snapshotowana przy pierwszym faktycznym wysłaniu do redakcji;
- pobranie kaucji i zmiana statusu na `submitted` są atomowe;
- jedna polemika ma jedną kaucję: retry, ponowne wysłanie po korekcie i rewizja nie pobierają jej drugi raz;
- brak wystarczającej liczby TT pozostawia tekst jako szkic i nie zmienia salda;
- publikacja zwraca użytkownikowi dokładną kwotę snapshotu;
- odrzucenie albo archiwizacja bez publikacji przekazuje kaucję na konto rozliczeniowe serwisu;
- jeżeli odrzucona polemika zostanie poprawiona i później opublikowana, ledger najpierw odwraca przepadek, a następnie zwraca kaucję użytkownikowi;
- pobranie, przepadek, odwrócenie i zwrot mają oddzielne typy transakcji, klucze idempotencji oraz identyfikatory zapisane przy artykule;
- wszystkie księgowania korzystają z istniejących `FinancialService`, `LedgerService` i portfela TT; nie powstał nowy portfel ani nowy system nagród.

Płatny tekst źródłowy musi mieć aktywny dostęp użytkownika przed utworzeniem polemiki. Bezpłatny tekst pozostaje dostępny bez dodatkowego zakupu. Sama opublikowana polemika pozostaje publiczna i bezpłatna.

## Role

Dodano rolę główną `commentator`:

- może tworzyć i wysyłać polemiki przez dedykowany przepływ `/opinie`;
- ma portfel i włączony Talent;
- `can_write=false` w zwykłym module autora;
- `payout_enabled=false` i nie może zostać włączone w panelu operacyjnym;
- zatwierdzony autor może korzystać z tego samego przepływu polemik;
- jeżeli ochrona `article_submit_approval` jest włączona, polemika autora korzysta z istniejącej operacji `article.submit` w 3DORS Author;
- komentator pozostaje w ograniczonej ścieżce bez 3DORS Author, wyłącznie dla własnego tekstu z `response_to_article_id`;
- konto posiadające jednocześnie role autora i komentatora jest traktowane jak autor i nie omija 3DORS;
- nagroda zależy od publikacji, nie od nazwy roli.

## Co widzi użytkownik

- pod każdym publicznym artykułem znajduje się stały blok „Opinie zamiast komentarzy — Odpowiedz publikacją”;
- blok wyjaśnia obieg redakcyjny i prostą zasadę: Talent przyznaje TT po publikacji przez Redakcję;
- opublikowane polemiki są listowane pod tekstem;
- polemika wskazuje publikację źródłową;
- `/opinie` zawiera szkice, stan redakcyjny i działania użytkownika; pusty panel pokazuje komunikat zamiast 404;
- dashboard, formularz, statusy oraz komunikaty zapisu i wysłania korzystają z `PublicTranslationService` w językach PL/EN/DE/FR/IT/ES;
- formularz, panel `/opinie`, blok pod artykułem i historia portfela wyjaśniają kaucję prostym językiem we wszystkich sześciu językach;
- `/jak-zarabiac` zawiera widoczną zasadę „Odpowiedź jest publikacją”;
- menu prowadzi do sekcji opinii i polemik;
- polemiki są bezpłatne i nie można ich wyceniać, kupować ani wspierać w PLN.
- publiczny interfejs nie eksponuje technicznych komunikatów „0 TT” ani snapshotów; szczegóły kontrolne pozostają w panelu administracyjnym, kodzie i dokumentacji;

Dowody wizualne:

- `docs/screenshots/odpowiedz-publikacja-pod-artykulem.png`;
- `docs/screenshots/odpowiedz-publikacja-jak-zarabiac.png`.

## Co widzi administrator

- `Ustawienia → Program Talent`: karta „Opublikowana opinia lub polemika” z liczbą TT, przełącznikiem, statusem gotowości i dokładnym wyzwalaczem;
- ta sama karta zawiera oddzielne pole `Kaucja przy wysłaniu` w TT, informację o snapshotowaniu i jasne `0 wyłącza kaucję`;
- karta tej reguły nie pokazuje edytowalnego PLN ani limitu dziennego;
- opis wprost informuje o snapshotowaniu i niezmienności już należnej nagrody;
- lista i edycja redakcyjna pokazują tekst źródłowy, kwalifikację, liczbę TT i identyfikator joba;
- lista i edycja redakcyjna pokazują również kwotę i stan kaucji oraz identyfikatory obciążenia, zwrotu lub przepadku;
- zarządzanie użytkownikami pokazuje rolę `komentator` oraz zablokowane pisanie zwykłych artykułów i wypłaty;
- każda ogólna reguła Talent ma widoczny stan gotowości i opis faktycznego wyzwalacza;
- karta „Udział w ankiecie” jest gotowa do sterowania TT: administrator ustawia tu wyłącznie TT, natomiast PLN, budżet i limit pozostają w konkretnej ankiecie;
- reguł bez wiarygodnego dowodu nie można włączyć przez ogólny formularz.

Automatyczny screenshot panelu nie został wykonany, ponieważ lokalne logowanie zatrzymała aktywna ochrona 3DORS. Zabezpieczenie nie było obchodzone. Widoczność elementów jest objęta testem kontraktu widoku i została wdrożona na obu węzłach.

## Inwentaryzacja Talent po korekcie

### Gotowe i możliwe do aktywacji

- `registration_bonus` — pierwsze konto i sesja, idempotencja użytkownika;
- `day_visit_bonus` — potwierdzona aktywna obecność, a nie samo otwarcie;
- `article_read_bonus` — czas, postęp, widoczna karta i jednorazowy proof;
- `response_publication_bonus` — snapshot przy pierwszej publikacji.
- `survey_reward` — TT przez `TalentService`, z referencją `survey_response`; PLN pozostaje oddzielnym snapshotem odpowiedzi ankietowej;

### Kontrolowane w osobnym module

- `app_referral_bonus` — obsługiwany przez prostą promocję referral, poza ogólną kartą aktywności.

### Korekty integralności po audycie

- usunięto twarde `50 TT` z obsługi ankiet;
- kwota PLN ankiety jest księgowana osobno z `points=0`, a TT wyłącznie przez istniejący `TalentService`;
- nieaktywna reguła `survey_reward` oznacza 0 TT, ale nie blokuje należnego snapshotu PLN;
- nawet historyczna wartość `amount_minor` w regule ankiet nie może zostać zaksięgowana przez Talent;
- bonus rejestracyjny otrzymał trwałą referencję `user_registration:{user_id}` i job jest zapisywany w tej samej transakcji co konto;
- przy aktywnej regule błąd trwałego zapisu joba rejestracyjnego cofa utworzenie konta zamiast gubić nagrodę;
- `ON DELETE RESTRICT` dla źródła polemiki pozostaje świadomą decyzją: chroni ciągłość opublikowanej relacji i audytu zamiast odłączać odpowiedź od źródła.

### Zablokowane do czasu wiarygodnego dowodu

- `login_bonus` — historyczne, bez nowego wyzwalacza;
- `share_bonus`, `link_click_bonus`, `like_bonus` — brak wiarygodnego i unikalnego potwierdzenia;
- `bug_report_bonus` — brak deduplikacji i akceptacji prawdziwego błędu;
- `sponsored_article_read_bonus`, `ad_view_reward`, `ad_click_reward` — wymagają budżetu kampanii i antyfraudu;
- `newsletter_open_reward` — wymaga wiarygodnego webhooka dostawcy poczty;
- `ppv_reward`, `live_event_reward` — wymagają potwierdzonego czasu udziału.

Usunięto publiczny, samodzielnie raportowany endpoint ogólnych aktywności. Użytkownik nie może już sam przesłać żądania typu „share/click/like” i utworzyć joba Talent bez wiarygodnego źródła.

## Zachowany referral

Promocja instalacji aplikacji pozostała warstwą nad istniejącym Talentem:

- 3 aktywne zaproszenia i 3 skuteczne polecenia;
- wartość TT jest zapisywana w zaproszeniu w chwili jego utworzenia;
- wyłączenie promocji działa przez `is_promoted=false`;
- panel w portfelu znajduje się na końcu treści portfela;
- panel jest ukrywany, gdy promocja jest wyłączona albo pula użytkownika jest wyczerpana;
- napis `PROMOWANE` ma biały tekst;
- bieżąca lokalna promocja pozostaje aktywna: 1000 TT, limity 3/3.

## Baza i wdrożenie

- wykonano kopię przed migracją: `backups/pre_response_publication_20260810_1227.dump` (celowo wyłączona z paczki audytowej);
- zastosowano migracje `20260810_009_response_publications` i `20260810_010_response_submission_deposit`;
- bieżąca reguła `response_publication_bonus` jest bezpiecznie wyłączona i ma 0 TT, dopóki administrator nie ustawi wartości i jej nie włączy;
- oba serwery oraz wszystkie workery działają na tym samym, zweryfikowanym obrazie aplikacji;
- `/health/ready` zwraca `status=ok` osobno dla `app-1` i `app-2`, w tym PostgreSQL, schemat, Valkey i storage.

## Testy

- PHPUnit: **219 testów, 4107 asercji, 9 kontrolowanych pominięć — PASS**;
- testy kaucji obejmują snapshot, brak środków, retry, odrzucenie, przepadek, poprawkę bez drugiego pobrania, publikację, odwrócenie przepadku i zwrot — PASS;
- test dostępu do płatnego tekstu przed utworzeniem polemiki — PASS;
- obrona wypłat komentatora na poziomie metod wypłaty i zlecenia wypłaty, również przy uszkodzonej fladze `payout_enabled` — PASS;
- testy integracyjne snapshotu, retry, rewizji, odrzucenia i roli komentatora — PASS;
- testy autora polemiki przez istniejące `article.submit`, bezpośredniej ścieżki komentatora, mobilnej sesji komentatora, ankiet PLN/TT i trwałej referencji rejestracji — PASS;
- PHPStan dla całego zmienionego zakresu — **0 błędów**;
- pełny PHPStan repozytorium nadal raportuje 35 odziedziczonych błędów w niepodłączonym fragmencie `Book100` i dwóch skryptach-fixture; nie dotyczą wdrożonego zakresu i nie dodano baseline ukrywającego problem;
- Composer audit — brak znanych podatności w `composer.lock`;
- Źródło Słowa Android: unit test, lint i `assembleDebug` — PASS;
- 3DORS Admin i Author: unit testy, lint oraz oba buildy debug — PASS;
- publiczny frontend został sprawdzony na działającym proxy i bezpośrednio na obu węzłach.

Testy `connected*` oraz pełny referral na dwóch fizycznych telefonach nie były wykonywane bez podłączonych urządzeń. Stan do następnego etapu:

`READY_FOR_PHYSICAL_REFERRAL_E2E`
