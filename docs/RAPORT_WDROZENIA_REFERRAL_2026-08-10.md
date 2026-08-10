# Raport wdrożenia promocji instalacyjnej Talent

Data: 2026-08-10  
Repozytorium: `X:\zrodlo-slowa`

## Zakres

Wdrożenie dodaje promocję poleceń aplikacji jako warstwę nad istniejącym systemem Talent. Nie powstał nowy portfel ani nowy mechanizm nagród. Naliczanie nadal przechodzi przez istniejące TT, ledger, kolejkę zadań oraz worker Talent.

Model PostgreSQL został rozszerzony wyłącznie o:

- `talent_promotions` — konfiguracja promocji kontrolowana przez administrację;
- `app_referral_invitations` — zaproszenia i ich cykl życia, w tym snapshot `reward_points` z chwili wysłania.

Domyślna promocja daje obu stronom po 1000 TT, pozwala na 3 aktywne zaproszenia i 3 skuteczne polecenia. Zmiana kwoty przez administratora dotyczy tylko nowych zaproszeń.

## Przebieg i zabezpieczenia

Pełna ścieżka to: zaproszenie e-mail → otwarcie linku → instalacja → wydanie krótkiego jednorazowego `registration_nonce` przypisanego do zaproszenia i urządzenia → pierwsza rejestracja na zaproszony adres → pierwsza uwierzytelniona sesja w aplikacji → atomowe zakolejkowanie dwóch nagród.

Token wskazuje wyłącznie zaproszenie i nie zawiera kwoty. Długi token instalacyjny nie trafia już do `/register`; formularz dostaje tylko 15-minutowy nonce zapisany w bazie jako HMAC. Rejestracja i konsumpcja nonce są atomowe, a konto musi powstać po potwierdzonej instalacji. Backend blokuje ponowne użycie adresu e-mail, urządzenia, konta i nonce, ponowną instalację na innym zaproszeniu, samopolecenie oraz przekroczenia limitów i limitów antyspamowych. Dead letter wiadomości kończy zaproszenie i zwalnia aktywny slot.

Minimalny wyjątek w `TalentService` odczytuje punkty `app_referral_bonus` wyłącznie z zablokowanego rekordu zaproszenia i wyłącznie dla dwóch zapisanych identyfikatorów zadań nagrody. Wartość pieniężna jest twardo wymuszona jako 0 PLN, a ogólne ustawienia Talent nie mogą zmienić tej reguły. Obie nagrody są tworzone w jednej transakcji bazy; błąd którejkolwiek powoduje rollback.

## Widoczność i kontrola

- Portfel: AJAX „Aktualne w Talent”, limity, zaproszenia i ich statusy. Panel jest ostatnią sekcją portfela, a nieaktywny program, zajęcie wszystkich aktywnych slotów lub wykorzystanie pełnego limitu skutecznych poleceń ukrywa promocję.
- `Ustawienia → Program Talent`: przełącznik „Promuj”, kwota, daty, ważność, limity, statystyki i ostatnie zaproszenia.
- Zmiana promocji w administracji wymaga istniejącej autoryzacji krytycznej 3DORS i jest audytowana.
- `/jak-zarabiac`: aktywna oferta jest prezentowana jako `PROMOWANE` i korzysta z tej samej konfiguracji co backend.
- Android: App Link i schemat awaryjny, Google Play Install Referrer, trwały token instalacyjny, krótki nonce rejestracyjny i potwierdzenie pierwszej sesji na domenie uwierzytelnienia. `MainActivity` używa `singleTask`, więc obsługuje cold i warm App Link przez `onNewIntent()`.

## Wnioski z niezależnego audytu

Uwagi P1 z `AUDYT_REFERRAL_2026-08-10_OPENAI.md` zostały przyjęte i usunięte przed fizycznym E2E:

- rejestracja jest serwerowo związana z instalacją i urządzeniem;
- długi token został zastąpiony w rejestracji krótkim jednorazowym nonce;
- OAuth jest ukryty wyłącznie w aktywnym referral flow;
- odpowiedź dla istniejącego lub wcześniej zaproszonego e-maila jest neutralna i nie ujawnia stanu konta.

Z małych uwag dalszych wdrożono również twarde `amountMinor=0` oraz stabilną obsługę warm App Link. Nie przebudowano wypłaty dwóch niezależnych jobów w nowy system sparowanych płatności, ponieważ zaakceptowany wymóg dotyczy atomowego zakolejkowania i rollbacku. Nie snapshotowano limitu skutecznych poleceń, ponieważ przyjęty model jawnie snapshotuje tylko `reward_points`. Uniwersalne promowanie innych aktywności, pełne tłumaczenie maila/landingu i osobny wariant rejestracji czytelnika pozostają poza zakresem tej prostej promocji.

## Wyniki kontroli

- Migracja PostgreSQL `20260810_007_app_referral_promotion`: `applied`, 11 instrukcji.
- Migracja PostgreSQL `20260810_008_app_referral_registration_nonce`: `applied`, 2 instrukcje; nie dodaje trzeciej tabeli.
- PHPUnit: 198 testów, 2504 asercje, 0 błędów, 9 testów opcjonalnych pominiętych.
- PHPStan dla wszystkich klas zmienionych przez referral: 0 błędów.
- Pełny PHPStan repo wskazuje 35 wcześniejszych, niezwiązanych problemów: stary adapter `Przelewy24Gateway.php` oraz dwa skrypty etapowe.
- PHP lint: 413 plików PHP, 0 błędów; JSON tłumaczeń prawidłowy.
- ŹRÓDŁO SŁOWA Android: 68 testów, 0 błędów; `assembleDebug` i `lintDebug` zakończone sukcesem.
- 3DORS Admin Android: 57 testów, 0 błędów; build i lint zakończone sukcesem.
- 3DORS Author Android: 57 testów, 0 błędów; build i lint zakończone sukcesem.
- `git diff --check`: bez błędów treści diffu.

## Weryfikacja wizualna na dwóch serwerach

Aktualny obraz developerski został wdrożony równolegle na `app-1` i `app-2`. Obie instancje mają stan `healthy`, korzystają z tej samej migracji PostgreSQL i zwracają zgodny interfejs:

- `/admin/settings`: sekcja „Bonus za instalację i polecenie”, status `PROMOWANE`, 1000 TT, limity 3/3, daty, ważność, statystyki, kontrola 3DORS oraz zawsze widoczna historia zaproszeń ze stanem pustym;
- `/wallet`: widget AJAX jako ostatnia sekcja, formularz e-mail, lista statusów, biały napis `PROMOWANE` na czerwonej etykiecie oraz endpoint `/api/talent/referrals` zwracający aktywną promocję 1000 TT;
- `/jak-zarabiac`: aktywna karta `PROMOWANE`;
- proxy `http://localhost:8080`: stan `healthy`, poprawne przekierowanie niezalogowanego użytkownika i poprawne renderowanie panelu w istniejącej sesji administratora.

Przełącznik sprawdzono również w pełnym cyklu na żywej bazie: zapis `WYŁĄCZONE` ustawił `currentPromotion()` na `null`, usunął promocję z API i z `/jak-zarabiac` na obu instancjach, a ponowne włączenie przywróciło na obu serwerach 1000 TT oraz kartę publiczną. Stan końcowy pozostawiono jako aktywny: 1000 TT, limity 3/3.

Po odtworzeniu backendów proxy zostało zrestartowane w celu odświeżenia adresów DNS kontenerów. Wszystkie workery — e-mail, earnings, notifications, AI i scheduler — działają na tym samym aktualnym obrazie aplikacji.

## Następny etap

Test fizyczny wymaga dwóch nowych adresów/kont, dwóch urządzeń oraz działających workerów e-mail i earnings. Dla automatycznej obsługi HTTPS App Links należy ustawić `ANDROID_APP_LINK_SHA256_CERT_FINGERPRINTS` zgodnie z certyfikatem testowanego APK. Debug APK zbudowany 2026-08-10 ma SHA-256 certyfikatu:

`03:11:E4:2C:D4:7A:A8:25:7C:A6:D7:56:8D:D5:21:22:FA:1B:48:1F:E7:0A:7C:AD:48:08:B1:E8:49:D0:E8:D0`

Do czasu testu na dwóch fizycznych telefonach status wdrożenia:

`READY_FOR_PHYSICAL_REFERRAL_E2E`
