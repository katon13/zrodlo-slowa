# Raport z wdrożenia logowania Google i Apple (OAuth 2.0)

## 1. Wykonane prace
- Utworzono tabelę `user_oauth_accounts` do przechowywania powiązań kont zewnętrznych z lokalnymi kontami użytkowników.
- Zaimplementowano mechanizm OAuth 2.0 / OpenID Connect dla Google i Apple.
- Dodano konfigurację w `.env.example`, `.env.install.example` oraz nowy plik `config/oauth.php`.
- Utworzono dedykowane serwisy `GoogleOAuthService` i `AppleOAuthService` do komunikacji z dostawcami.
- Utworzono `OAuthAccountService` do zarządzania logiką kont (łączenie, tworzenie nowych użytkowników).
- Dodano `OAuthController` obsługujący przekierowania i callbacki.
- Zarejestrowano trasy w `public/index.php`.
- Zaktualizowano interfejs logowania o przyciski Google i Apple.

## 2. Nowe pliki
- `app/Controllers/OAuthController.php`
- `app/Services/OAuthAccountService.php`
- `app/Services/GoogleOAuthService.php`
- `app/Services/AppleOAuthService.php`
- `config/oauth.php`
- `docs/LOGOWANIE_GOOGLE_APPLE_OAUTH.md`
- `docs/GOOGLE_APPLE_LOGIN_WDROZENIE_RAPORT.md`

## 3. Zmiany w istniejących plikach
- `.env.example`, `.env.install.example` – nowe zmienne konfiguracyjne.
- `database/zrodlo_slowa.sql` – dodano strukturę tabeli `user_oauth_accounts`.
- `public/index.php` – rejestracja nowych tras.
- `views/auth/login.php` – przyciski logowania.
- `public/assets/css/slowo-system.css` – style dla przycisków OAuth.

## 4. Bezpieczeństwo i prywatność
- **State validation**: Zaimplementowano obowiązkową weryfikację parametru `state` przeciwko CSRF.
- **Token validation**: id_token jest weryfikowany pod kątem wydawcy (`iss`), odbiorcy (`aud`) oraz czasu wygaśnięcia (`exp`).
- **Privacy**: System prosi wyłącznie o zakresy `openid`, `email` i `profile`. Nie pobieramy żadnych danych z innych usług (Gmail, Dysk, etc.).
- **Token storage**: System NIE zapisuje `access_token`, `refresh_token` ani `authorization_code`.
- **RBAC**: Logowanie OAuth nie nadaje żadnych uprawnień. Użytkownik po rejestracji otrzymuje podstawową rolę `reader`.
- **2FA**: Lokalny mechanizm 2FA dla wysokich ról pozostaje aktywny i obowiązkowy.

## 5. Wyniki testów
- `php -l`: Wszystkie pliki PHP mają poprawną składnię.
- Migracja bazy danych: Tabela `user_oauth_accounts` została poprawnie utworzona z kluczem obcym i indeksami.
- Izolacja: Logowanie hasłem działa bez zmian. Przyciski OAuth reagują na ustawienia w `.env`.

## 6. Uwagi techniczne
- Apple Callback używa `response_mode=form_post` (wymóg dla pobierania danych użytkownika przy pierwszym logowaniu), dlatego trasa Apple Callback jest typu POST.
- Do pełnego działania wymagana jest konfiguracja po stronie Google Cloud Console i Apple Developer Portal zgodnie z dokumentacją w `docs/LOGOWANIE_GOOGLE_APPLE_OAUTH.md`.
