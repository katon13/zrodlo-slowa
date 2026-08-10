# NAPRAWA PO AUDYCIE — PEŁNY RAPORT

## 1. Cel naprawy
Celem etapu naprawczego było wyeliminowanie ryzyk krytycznych i wysokich zidentyfikowanych podczas audytu systemu ŹRÓDŁO SŁOWA, przy jednoczesnym zachowaniu dotychczasowej logiki biznesowej, workflow redakcyjnego oraz zasad ekonomii portfela.

## 2. Problemy z audytu i status naprawy

| ID | Problem | Priorytet | Status | Opis naprawy |
|---|---|---|---|---|
| R1 | Publiczny dostęp do Adminera | KRYTYCZNE | ROZWIĄZANO | Plik `public/adminer.php` został usunięty z repozytorium. |
| R2 | Ślady legacy i martwy kod | WYSOKI | ROZWIĄZANO | Usunięto MigrationController, skrypty importu i martwe trasy. |
| R3 | Podatność Stripe Webhook | WYSOKI | ZWERYFIKOWANO | Webhook posiada weryfikację podpisu i mechanizm idempotencji. |
| R4 | Ryzyko utraty spójności portfela | WYSOKI | ROZWIĄZANO | Zabezpieczono operacje finansowe przez LedgerService z kluczami idempotencji. |
| R5 | Wyciek danych w błędach (Debug) | WYSOKI | ROZWIĄZANO | Wdrożono globalną obsługę błędów w bootstrap.php (ukrywanie stack trace na produkcji). |
| R6 | Powrót języka DE do PL | WYSOKI | ROZWIĄZANO | Naprawiono akcje formularzy w widokach i wzmocniono detekcję języka. |
| R7 | Niebezpieczne uploady | WYSOKI | ROZWIĄZANO | Dodano MIME finfo, losowe nazwy plików i zabezpieczenie .htaccess. |
| R8 | Nieaktualne tłumaczenia po korekcie| ŚREDNI | ROZWIĄZANO | Korekta oznacza teraz tłumaczenia statusem 'outdated'. |

## 3. Zmiany w systemie

### Bezpieczeństwo i Core
- **Global Error Handling**: W `app/Core/bootstrap.php` dodano `set_error_handler` i `set_exception_handler`. Na produkcji błędy nie ujawniają ścieżek serwera.
- **Upload Security**: W `app/Services/UploadService.php` wprowadzono losowe sufiksy nazw plików (8 znaków hex). Dodano plik `public/uploads/.htaccess` blokujący wykonywanie skryptów PHP/Phar.
- **CSRF**: Potwierdzono obecność `verify_csrf()` w `bootstrap.php` dla wszystkich żądań POST.

### Wielojęzyczność
- **Formularze**: Naprawiono akcje w ok. 30 formularzach we wszystkich widokach użytkownika i autora, zastępując twarde ścieżki (np. `/login`) dynamicznymi URLami z zachowaniem języka (`public_language_url`).
- **Detekcja języka**: Dodano ukryte pole `_lang` do formularzy, co zapobiega resetowaniu języka do domyślnego PL przy operacjach POST.

### Finanse i Ledger
- **Idempotencja**: Potwierdzono, że `LedgerService::post` korzysta z unikalnych kluczy transakcji, co zapobiega podwójnemu księgowaniu (np. przy ponowionym webhooku Stripe).
- **Recent Payments**: Przemianowano metody i widoki "legacy" na "recent/direct payments", aby odciąć się od historycznej bazy `bookplmslowo`.

### Workflow Redakcyjny
- **Korekta**: Zmodyfikowano `ArticleService::updateProofreading`. Po zapisaniu korekty przez korektora, wszystkie powiązane tłumaczenia (poza szkicami) otrzymują status `outdated`.
- **Statusy**: Zweryfikowano poprawność przejść statusów: `submitted` -> `review` -> `approved` -> `published`.

## 4. Wyniki testów technicznych

- **php -l**: Przeskanowano 158 plików PHP. Wynik: 0 błędów składniowych.
- **scripts/install.php --check**: Status OK. Wszystkie tabele i kolumny zgodne ze schematem. Admin zweryfikowany.
- **Baza danych**: Katalog `database` zawiera wyłącznie `zrodlo_slowa.sql`. Usunięto wszystkie ślady migracji i legacy.
- **Wyszukiwanie legacy**: Brak wystąpień `LEGACY_DB`, `bookplmslowo` czy `adminer.php` w kodzie źródłowym aplikacji (obecne tylko w dokumentacji audytu).

## 5. Potwierdzenie zasad stałych
- Jedna baza: `zrodlo_slowa` ✓
- Jeden plik SQL: `database/zrodlo_slowa.sql` ✓
- Brak public/adminer.php ✓
- Język z URL ma priorytet nad sesją ✓
- Uploady zabezpieczone ✓
- Reset bazy wymaga jawnego podwójnego potwierdzenia ✓
- Workflow redakcyjny zachowany ✓
- Ekonomia została następnie zaktualizowana do wersjonowanego modelu 40/40/20 z Safety Fund ✓

## 6. DO DECYZJI WŁAŚCICIELA
1. **Domeny w sites.json**: Obecna konfiguracja zakłada różne domeny dla każdego języka (np. `de-wortquelle.de`). Na środowisku lokalnym/localhost może to utrudniać testy międzyjęzykowe, chyba że zostaną dodane wpisy do pliku `hosts`. Zaleca się rozważenie przejścia na model subdomenowy lub wyłącznie prefiksowy na jednej domenie głównej.
2. **Status 'outdated'**: System oznacza teraz tłumaczenia jako nieaktualne po korekcie, ale nie wymusza ich ponownego tłumaczenia. Należy zdecydować, czy 'outdated' powinno blokować publikację danej wersji językowej.

---

## REKOMENDACJA: MODEL BANKOWY ZABEZPIECZENIA PIENIĘDZY

Dla jeszcze wyższego poziomu bezpieczeństwa finansowego (standardy bankowe/fintech), proponuję wdrożenie następującej dyrektywy:

1. **Podpis cyfrowy Ledgera (Chain of Trust)**: Każdy wpis w tabeli `wallet_transactions` powinien zawierać kolumnę `entry_hash`, będącą hashem HMAC z danych transakcji ORAZ hasha poprzedniego wpisu. Uniemożliwi to modyfikację jakiejkolwiek kwoty w bazie danych bez wykrycia naruszenia integralności całego portfela.
2. **Maker-Checker (Zasada czterech oczu)**: Wszystkie operacje finansowe inicjowane przez administratorów (korekty salda, wypłaty ręczne) muszą wymagać stworzenia zlecenia przez jednego pracownika i zatwierdzenia przez drugiego (z inną rolą).
3. **Hard-Limit Protection**: Wprowadzenie na poziomie silnika bazy danych (triggery lub constrainty) bezwzględnego zakazu salda ujemnego dla kont typu 'main' i 'slowo', niezależnie od błędów w kodzie aplikacji.
4. **Oddzielna baza danych finansowych**: Przeniesienie tabel `wallets`, `wallet_transactions` i `payouts` do fizycznie oddzielnej instancji bazy danych z dostępem ograniczonym tylko do Serwisu Finansowego.

---
**Raport przygotowany przez JUNI (Etap Naprawczy)**
