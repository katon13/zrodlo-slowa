# Logowanie Google i Apple (OAuth 2.0 / OpenID Connect)

System ŹRÓDŁO SŁOWA wspiera logowanie za pomocą kont Google oraz Apple ID. Jest to opcjonalna i wygodna metoda uwierzytelniania, która nie zastępuje tradycyjnego logowania e-mailem i hasłem.

## 1. Zasada działania

Google i Apple służą wyłącznie jako **zewnętrzni dostawcy tożsamości**. System pobiera od nich podstawowe dane profilu (identyfikator `sub`, e-mail, nazwę), aby:
- Zidentyfikować istniejące konto w systemie.
- Połączyć konto z Google/Apple na podstawie zweryfikowanego adresu e-mail.
- Utworzyć nowe, podstawowe konto czytelnika, jeśli użytkownik nie posiada jeszcze konta.

**WAŻNE:** Logowanie przez Google/Apple NIE nadaje żadnych uprawnień (admin, autor, redaktor). Wszystkie role i finanse są zarządzane wyłącznie wewnątrz ŹRÓDŁA SŁOWA.

## 2. Pobierane dane

System prosi wyłącznie o dostęp do podstawowego profilu (`openid`, `email`, `profile` / `name`).
- **Google**: identyfikator, e-mail, informacja o weryfikacji e-maila, nazwa, URL avatara.
- **Apple**: identyfikator, e-mail (opcjonalnie Hide My Email), nazwa (tylko przy pierwszym logowaniu).

System **nie pobiera** i nie ma dostępu do: Gmaila, Dysku, Kalendarza, Kontaktów, Zdjęć ani żadnych innych plików użytkownika.

## 3. Konfiguracja Google Cloud Console

1. Przejdź do [Google Cloud Console](https://console.cloud.google.com/).
2. Utwórz projekt lub wybierz istniejący.
3. Skonfiguruj **OAuth consent screen** (Internal lub External).
4. W sekcji **Credentials** utwórz **OAuth 2.0 Client ID** dla aplikacji webowej.
5. Dodaj **Authorized redirect URIs**:
   - `http://localhost/auth/google/callback` (dla testów lokalnych)
   - `https://zrodlo-slowa.pl/auth/google/callback` (produkcja)
6. Pobierz `Client ID` i `Client Secret`.

## 4. Konfiguracja Apple Developer

1. Przejdź do [Apple Developer Portal](https://developer.apple.com/).
2. W sekcji **Certificates, Identifiers & Profiles** utwórz **Services ID** dla Sign in with Apple.
3. Skonfiguruj domenę i **Return URLs**:
   - `https://zrodlo-slowa.pl/auth/apple/callback` (Apple wymaga HTTPS i publicznej domeny).
4. Utwórz **Private Key** (.p8) dla Sign in with Apple.
5. Zanotuj: `Client ID` (np. `pl.zrodlo-slowa.client`), `Team ID`, `Key ID`.
6. Umieść plik `.p8` na serwerze poza katalogiem publicznym repozytorium.

## 5. Ustawienia .env

Dodaj następujące zmienne do pliku `.env`:

```env
GOOGLE_LOGIN_ENABLED=1
GOOGLE_CLIENT_ID=twoj_client_id
GOOGLE_CLIENT_SECRET=twoj_client_secret
GOOGLE_REDIRECT_URI=http://localhost/auth/google/callback

APPLE_LOGIN_ENABLED=1
APPLE_CLIENT_ID=twoj_service_id
APPLE_TEAM_ID=twoj_team_id
APPLE_KEY_ID=twoj_key_id
APPLE_PRIVATE_KEY_PATH=/sciezka/poza/repo/AuthKey_ABC123.p8
APPLE_REDIRECT_URI=https://twoja-domena.pl/auth/apple/callback
```

## 6. Bezpieczeństwo

- **State**: Każde żądanie używa unikalnego parametru `state` zapisywanego w sesji, co zapobiega atakom CSRF.
- **id_token**: System weryfikuje podpis i roszczenia (`iss`, `aud`, `exp`) tokena ID otrzymanego od dostawcy.
- **Brak zapisu tokenów**: System nie zapisuje `access_token` ani `refresh_token` dostawcy. Zapisywany jest jedynie trwały identyfikator `provider_user_id`.
- **2FA**: Konta z wysokimi rolami (np. admin) nadal muszą przejść lokalną weryfikację 2FA po zalogowaniu przez Google/Apple.

## 7. Wyłączanie logowania

Aby wyłączyć dany sposób logowania, ustaw odpowiednią zmienną `_ENABLED=0` w pliku `.env`. Przyciski logowania znikną automatycznie z interfejsu.
