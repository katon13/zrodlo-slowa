# AUDYT 11 — Bezpieczeństwo

## 1. Ochrona przed atakami typu Injection
- **SQL Injection**: Wszystkie zapytania do bazy danych przechodzą przez wrapper `Database.php`, który wymusza stosowanie preparowanych zapytań (prepared statements) z bindowaniem parametrów.
- **XSS (Cross-Site Scripting)**: Dane w widokach są konsekwentnie escapowane za pomocą funkcji `e()`. System nie używa domyślnie surowego wyjścia, co minimalizuje ryzyko wstrzyknięcia skryptów.

## 2. Zabezpieczenia sesji i dostępów
- **CSRF (Cross-Site Request Forgery)**: Router automatycznie weryfikuje token CSRF dla każdego żądania typu `POST`. Formularze generują tokeny przez `csrf_field()`.
- **Autoryzacja**: System stosuje rygorystyczne sprawdzanie uprawnień w kontrolerach (`requireAuth`, `requireAdmin`, `requireAdminOrRoles`).
- **Wysokie Role i 2FA**: Użytkownicy z rolami redakcyjnymi/administracyjnymi mają wymuszoną weryfikację e-mail oraz (opcjonalnie/wymuszane w konfiguracji) uwierzytelnianie dwuskładnikowe (2FA).

## 3. Bezpieczeństwo finansowe
- **Idempotencja**: Transakcje portfela w `LedgerService` obsługują klucze idempotencji, co zapobiega podwójnemu księgowaniu tych samych operacji.
- **Webhooki**: Webhooki Stripe są weryfikowane kryptograficznie (podpis HMAC-SHA256) przed przetworzeniem, co uniemożliwia sfałszowanie wpłat.
- **Podział wpływu**: Wersjonowany podział 40/40/20 dla artykułów premium jest realizowany w ramach jednej transakcji bazodanowej w `ArticleEconomyService`.

## 4. Zarządzanie plikami (Upload)
- **Walidacja**: `UploadService` sprawdza typ MIME pliku za pomocą `finfo`, a nie tylko rozszerzenie.
- **Optymalizacja**: Wszystkie wgrywane obrazy są konwertowane do formatu WebP i (opcjonalnie) skalowane, co usuwa potencjalnie niebezpieczne metadane (EXIF) z plików źródłowych.

## 5. Ryzyka produkcyjne
- **Adminer**: Obecność pliku `public/adminer.php` w repozytorium stanowi krytyczne ryzyko, jeśli nie zostanie usunięty lub silnie zabezpieczony (np. przez `.htaccess` lub IP allowlist) na produkcji.
- **Skrypty CLI**: Skrypty w katalogu `scripts/` powinny być zablokowane przed dostępem przez serwer WWW (zazwyczaj katalog ten znajduje się poza `public/`, co jest dobrą praktyką).
- **Zmienne środowiskowe**: Plik `.env` zawiera klucze API AI i Stripe. Należy upewnić się, że nie jest on serwowany publicznie.

## 6. Lista Ryzyk (Skrócona)
| ID | Moduł | Typ ryzyka | Opis | Priorytet |
| --- | --- | --- | --- | --- |
| R1 | Baza danych | SQLi/Auth | public/adminer.php widoczny w repo | KRYTYCZNE |
| R2 | Finanse | Nadpłata | Ryzyko dublowania manualnych wpłat bez klucza idempotencji | ŚREDNIE |
| R3 | AI | Koszty | Brak limitów wydatków na poziomie API (tylko limity systemowe) | ŚREDNIE |
| R4 | Upload | Wyciek ścieżki | Ujawnianie pełnych ścieżek fizycznych w błędach (APP_DEBUG=true) | NISKIE |
