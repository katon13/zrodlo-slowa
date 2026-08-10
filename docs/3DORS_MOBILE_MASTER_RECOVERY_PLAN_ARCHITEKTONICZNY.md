# 3DORS Mobile — uproszczony plan integracji i odzyskiwania

Data pierwotnej decyzji: 7 sierpnia 2026  
Data uproszczenia: 7 sierpnia 2026  
Status: **wiążący plan wdrożeniowy**  
Repozytorium: `X:\zrodlo-slowa`  
Cel bieżącego etapu: `BLOCKED_BEFORE_E2E` → **READY_FOR_E2E**, nie `PRODUCTION READY`

## 1. Decyzja architektoniczna

Wycofano koncepcję `MASTER / OPERATIONAL` w całości. Nie powstają:

- `device_purpose`;
- urządzenie MASTER;
- osobna allowlista MASTER;
- osobny enrollment ani rotacja MASTER;
- specjalne operacje MASTER;
- dodatkowy telefon przechowywany w sejfie.

Istnieje jeden model urządzenia administratora:

```text
3DORS Admin
```

Uproszczenie jest celowe. System zachowuje niezależne warstwy ochrony i odzyskiwania: urządzenie 3DORS Admin, WebAuthn/FIDO2, dziesięć jednorazowych kodów recovery, ograniczone recovery WWW oraz ostateczne lokalne recovery CLI. Nie tworzymy dodatkowej klasy credentialu przed pierwszym fizycznym E2E.

## 2. Wiążący model ról i wariantów

### Czytelnik

- korzysta ze zwykłego logowania hasłem;
- nie rejestruje 3DORS;
- nie widzi ekranów ani operacji 3DORS.

### Autor / dziennikarz

- korzysta z `3DORS Author` dla artykułów i publikacji;
- kwalifikacja wynika z roli autora/dziennikarza i `can_write`;
- `payout_enabled` nie jest warunkiem pracy autora ani enrollmentu Author;
- utrata urządzenia Author jest obsługiwana przez administratora, bez kodów recovery administratora.

### Administrator

- korzysta z `3DORS Admin` dla wypłat, ról i bezpieczeństwa;
- może unieważniać urządzenia, credentiale i tokeny w kontrolowanym backendowym flow;
- odzyskuje zabezpieczenia przez ograniczone recovery WWW albo ostateczne lokalne CLI.

Telefon nigdy nie pyta użytkownika, jaki rodzaj operacji chce zatwierdzić. Backend wyznacza typ operacji, rolę, wariant aplikacji, politykę i urządzenie. Aplikacja pokazuje kanoniczne dane operacji oraz przyciski `ZATWIERDŹ` i `ODRZUĆ`, po czym podpisuje świadomą decyzję.

Nazwy maszynowe, np. `article.submit` i `payout.approve`, pozostają stabilne. Wszystkie nowe teksty widoczne dla użytkownika muszą korzystać z Android string resources i wspólnego katalogu tłumaczeń backendu/panelu.

## 3. Warstwy bezpieczeństwa i kolejność odzyskiwania

```text
3DORS Admin
→ codzienna autoryzacja administratora

FIDO2 / WebAuthn
→ niezależny authenticator i step-up

10 recovery codes
→ ograniczona wymiana utraconych zabezpieczeń przez WWW

lokalne CLI
→ ostateczny pełny reset zabezpieczeń administratora
```

Żadna z tych dróg nie omija backendu, audytu ani serwerowych polityk 3DORS. Kod recovery nie jest zwykłym logowaniem i nie daje normalnej sesji administratora.

## 4. Dziesięć kodów recovery

Należy wykorzystać istniejące:

```text
security_recovery_codes
RecoveryCodeService
security_step_up_authorizations
AdminRecoveryService
scripts/security_recover_admin.php
```

Zasady pozostają niezmienne:

- dokładnie 10 kodów;
- jawne kody są pokazywane tylko raz;
- baza przechowuje wyłącznie hashe z pepperem;
- kody są przechowywane przez administratora offline;
- kod jest jednorazowy;
- użycie jednego kodu unieważnia pozostałe aktywne kody;
- nowy zestaw unieważnia poprzedni;
- kody nie trafiają do e-maila, logów, audytu ani raportów;
- generowanie, potwierdzenie, użycie i unieważnienie mają pełny audyt bez sekretów.

## 5. Ograniczone recovery WWW

Flow:

```text
identyfikacja administratora
→ hasło
→ jeden potwierdzony recovery code
→ krótka, serwerowa capability recovery
```

Recovery nie może ustawić zwykłej sesji Admin ani otworzyć istniejących kontrolerów administracyjnych. Używa osobnej capability powiązanej z `security_step_up_authorizations`, krótkim TTL, nonce, CSRF, limitami prób i jawną allowlistą tras.

W recovery WWW wolno wyłącznie:

- unieważnić stare urządzenia 3DORS Admin;
- unieważnić ich credentiale i tokeny API;
- anulować pending requests, niewykonane operacje odroczone oraz rozpoczęte enrollmenty Admin;
- rozpocząć i potwierdzić enrollment nowego urządzenia 3DORS Admin;
- przygotować odtworzenie FIDO2, gdy pełny mechanizm jest dostępny;
- wygenerować i jednorazowo wyświetlić nowy zestaw 10 kodów;
- zakończyć recovery.

Nie wolno:

- wejść do zwykłego panelu Admin;
- wykonywać wypłat lub innych operacji finansowych;
- zmieniać sald, ról, uprawnień lub ustawień biznesowych;
- użyć capability jako normalnej autoryzacji 3DORS;
- zamienić capability na trwałą sesję administratora.

Użycie kodu pozostaje atomowe i unieważnia pozostałe kody. Po zakończeniu recovery capability jest unieważniana, stare sesje są kończone, `session_version` wzrasta i wymagane jest świeże normalne logowanie. Zdarzenie powoduje audyt i powiadomienie bezpieczeństwa bez kodu lub innych sekretów. Próby są throttlowane, a odpowiedzi nie ujawniają istnienia loginu.

## 6. Pełne recovery CLI

`scripts/security_recover_admin.php` i `AdminRecoveryService` są ostatnią drogą, gdy nie działa urządzenie 3DORS Admin, FIDO2 ani recovery WWW.

Serwis został rozszerzony: unieważnia WebAuthn, sesje i cały bieżący stan 3DORS Mobile Admin, zachowując rekordy historyczne.

Pełne recovery powinno atomowo:

- zużyć wskazany kod i unieważnić pozostałe;
- unieważnić WebAuthn/FIDO2 administratora oraz wszystkie rozpoczęte challenge’e;
- unieważnić wszystkie urządzenia wariantu `admin`;
- unieważnić ich credentiale i tokeny API;
- anulować pending requests i niewykonane operacje odroczone Admin;
- anulować rozpoczęte enrollmenty Admin;
- zakończyć sesje administratora i podnieść `session_version`;
- zachować użytkownika, urządzenia jako rekordy historyczne, podpisy, decyzje, księgę i audyt;
- wydać lokalny raport z licznikami każdego typu unieważnionych obiektów, bez sekretów.

Pełne recovery Admin nie unieważnia automatycznie urządzenia wariantu `author` tej samej osoby. Author ma osobną procedurę.

## 7. Odzyskanie telefonu autora

```text
Admin unieważnia stare urządzenie Author
→ backend anuluje pending autora
→ sprawdza rolę autora/dziennikarza i can_write
→ tworzy enrollment 3DORS Author
→ autor skanuje QR i porównuje kod
→ administrator zatwierdza nowe urządzenie
```

Czytelnik nie przechodzi tego flow. Kody administratora nie są używane. `payout_enabled = false` nie blokuje autora posiadającego rolę i `can_write`.

## 8. Potwierdzone luki bieżącego etapu

Przed READY_FOR_E2E trzeba domknąć:

1. kanoniczny endpoint stanu sesji dla aplikacji Źródło Słowa Mobile zamiast sondowania stron HTML;
2. jeden centralny stan sesji w Androidzie;
3. brak resetowania WebView podczas rekompozycji;
4. lifecycle, single-flight, timeouty i bezpieczne przechowywanie ustawień powiadomień;
5. jednoznaczne hosty API/App Links, pinning i konfigurację release obu wariantów 3DORS;
6. backendowe routowanie publikacji do Author oraz wypłat/ról/bezpieczeństwa do Admin;
7. pełne recovery WWW i CLI opisane wyżej;
8. fixture PostgreSQL, integracyjne scenariusze E2E i dokumentację uruchomienia;
9. pełny build, lint i testy dla PHP oraz obu wariantów Android;
10. oczyszczone repo do niezależnego audytu.

## 9. Financial Checkpoint — etap późniejszy

Financial Checkpoint jest podpisanym dowodem salda dla konkretnego, niezmiennego punktu księgi. Nie jest backupem, księgą, portfelem ani źródłem prawdy.

Checkpoint powinien później zawierać co najmniej:

```text
schema_version
environment
account/author pseudonymous id
currency
amount_minor
ledger_sequence lub immutable entry id
as_of
nonce
signer credential id
canonical payload hash
server signature
```

Porównanie ma dotyczyć tego samego punktu księgi:

```text
saldo odtworzone przez serwer na ledger_sequence == amount_minor checkpointu
```

Legalna transakcja wykonana po checkpointcie nie może powodować fałszywego alarmu. Dopiero zweryfikowana niezgodność dla tego samego punktu powoduje `STOP`, alarm 3DORS i audyt.

Telefon autora przechowuje wyłącznie własny checkpoint. Dwie docelowe zaszyfrowane księgi Admin — salda oraz mapowanie tożsamości — mają osobne domeny, klucze i reguły dostępu. Checkpoint jest odrębnym artefaktem kryptograficznym, a nie rekordem decyzji `approve/reject`.

## 10. Safety Fund — wdrożony model redakcyjny

Safety Fund jest trzecim wydzielonym saldem w istniejącym silniku finansowym i tej samej księdze. Nie jest systemem płatniczym, rachunkiem bankowym, osobnym backendem ani drugim ledgerem.

Punkt kwalifikowanego wpływu jest dokładnie tym samym punktem zakupu płatnego tekstu, który wcześniej dzielił wpływ między autora i serwis. Obecna zasada startowa wykonuje jedną transakcję:

```text
40% Autor + 40% Serwis + 20% Safety Fund = 10000 punktów bazowych
```

Każdy zakup zapisuje identyfikator i wersję aktywnej polityki, trzy udziały w minor units oraz cztery idempotentne wpisy portfeli: obciążenie kupującego i uznania Autora, Serwisu oraz Safety Fund. Historia nie jest przeliczana po zmianie proporcji.

Panel `/admin/safety-fund` udostępnia saldo, wpływy, wydatki, historię polityk i audyt. Zmiana proporcji używa `financial_settings.change`, a wydanie środków `safety_fund.disbursement`. Obie operacje są kierowane przez backend wyłącznie do 3DORS Admin; telefon pokazuje podpisywane dane, lecz nie ustala polityki ani uprawnień.

Nie dodano maker-checker drugiej osoby, nowego modelu transferów ani zewnętrznego rozliczania. Kontrolę stanowią rola administratora, dokładny fingerprint danych, świadoma decyzja 3DORS, ponowna kontrola salda, transakcyjność, idempotencja i audyt.

## 11. Scalony plan wykonawczy

### Etap 0 — stan i zakres

- pracować wyłącznie w `X:\zrodlo-slowa` i na bieżącej gałęzi;
- zachować istniejące niezatwierdzone zmiany;
- nie wykonywać pobocznego refaktoryzowania;
- nie wykonywać realnych wypłat ani operacji finansowych.

### Etap 1 — Źródło Słowa Mobile

- wdrożyć kanoniczny kontrakt sesji;
- scentralizować stan sesji Android;
- domknąć WebView i powiadomienia;
- zachować zwykłe logowanie hasłem dla czytelnika;
- zachować scentralizowane teksty UI.

### Etap 2 — 3DORS Admin/Author

- domknąć konfigurację, hosty, App Links, pinning i release signing;
- zweryfikować automatyczne routowanie operacji;
- zachować urządzenia powiązane z użytkownikiem, wariantem i credentialem;
- nie dodawać `device_purpose` ani żadnego modelu MASTER.

### Etap 3 — Recovery

- wdrożyć ograniczoną capability WWW;
- rozszerzyć CLI na pełny reset 3DORS Mobile Admin;
- domknąć osobny flow odzyskania Author;
- dodać powiadomienia i audyt bez sekretów.

### Etap 4 — testy i odbiór

- uruchomić migracje i integrację na PostgreSQL;
- uruchomić testy jednostkowe i integracyjne PHP;
- uruchomić Android unit tests, lint oraz build Admin/Author;
- wykonać scenariusze negatywne i awaryjne;
- przygotować oczyszczone repo do niezależnego audytu;
- po spełnieniu bram oznaczyć wynik wyłącznie jako READY_FOR_E2E.

W tym etapie wdrożono również docelowy na E2E model Safety Fund 40/40/20 w istniejącej księdze, panel administracyjny oraz dwie operacje 3DORS Admin.

### Etap 5 — później: Financial Checkpoint

Rozpocząć po stabilnym fizycznym E2E 3DORS i zatwierdzeniu niezmiennego punktu księgi, kanonicznego payloadu oraz szyfrowania dwóch zbiorów Admin.

## 12. Minimalna macierz odbioru

1. publikacja trafia wyłącznie do `3DORS Author`;
2. wypłata, role i bezpieczeństwo trafiają wyłącznie do `3DORS Admin`;
3. telefon nie pozwala użytkownikowi wybrać wariantu operacji;
4. czytelnik nie może rozpocząć enrollmentu 3DORS;
5. autor z rolą i `can_write` może pracować bez `payout_enabled`;
6. ograniczone recovery nie otwiera zwykłych tras Admin ani finansów;
7. recovery unieważnia urządzenia Admin, credentiale, tokeny, pending i enrollmenty;
8. CLI obejmuje cały 3DORS Mobile Admin i WebAuthn;
9. recovery Admin nie unieważnia automatycznie Author;
10. urządzenie, credential i token po revoke nie mogą pobierać ani podpisywać żądań;
11. historyczne podpisy, decyzje i audyt pozostają czytelne;
12. `session_version` unieważnia stare sesje;
13. użycie kodu unieważnia pozostałe kody i nigdy nie ujawnia sekretu w logu;
14. utracony Author jest odtwarzany przez kontrolowany flow Admin;
15. nowe komunikaty PL/EN pochodzą ze scentralizowanych zasobów;
16. pełne testy i buildy przechodzą na konfiguracji E2E;
17. zakup płatnego tekstu księguje atomowo 40% Autor / 40% Serwis / 20% Safety Fund;
18. każda transakcja zachowuje wersję polityki, a późniejsza zmiana nie przelicza historii;
19. zmiana polityki i wydatek Safety Fund wymagają podpisanej decyzji 3DORS Admin;
20. wydatek ponad aktualne saldo jest blokowany bez zapisu obciążenia.

## 13. Warunki późniejszego Financial Checkpoint

Financial Checkpoint może wejść do implementacji dopiero, gdy ledger udostępnia jednoznaczny immutable sequence, istnieje wersjonowana specyfikacja payloadu oraz ustalono retencję, częstotliwość, szyfrowanie i procedurę alarmu.

## 14. Źródła bezpieczeństwa

- NIST SP 800-63B-4, Account Recovery: recovery jest osobnym procesem wiązania nowych authenticatorów; saved recovery code ma być losowy, hashowany, przechowywany offline, throttlowany, unieważniany po użyciu i ma powodować powiadomienie bezpieczeństwa: <https://pages.nist.gov/800-63-4/sp800-63b.html#account-recovery>
- Android Keystore: klucz może wymagać uwierzytelnienia użytkownika dla konkretnej operacji: <https://developer.android.com/privacy-and-security/keystore>
- OWASP Transaction Authorization: metoda, dane i wynik autoryzacji muszą być wymuszane po stronie serwera: <https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html>

## 15. Podsumowanie

Aktualny model jest celowo prosty: jeden 3DORS Admin, jeden 3DORS Author i niezależne drogi odzyskiwania. Nie istnieje MASTER ani OPERATIONAL. Bieżące wdrożenie obejmuje integrację Mobile, kompletne recovery oraz Safety Fund jako wersjonowany podział w jednej istniejącej księdze. Financial Checkpoint pozostaje późniejszym modułem z zachowanymi granicami architektonicznymi.
