# 3DORS — ustawienia

## Bezpieczny profil obecnego etapu

```dotenv
DORS3_MODE=prepare
DORS3_FIDO2_ENABLED=false
DORS3_FIDO2_REQUIRED=false
DORS3_CRITICAL_STEP_UP=password
DORS3_PHYSICAL_APPROVAL=disabled
DORS3_ADMIN_IDLE_TIMEOUT_SECONDS=900
DORS3_ADMIN_SESSION_MAX_SECONDS=28800
WEBAUTHN_ENABLED=false
WEBAUTHN_RP_ID=localhost
WEBAUTHN_RP_NAME=Źródło Słowa — 3DORS
WEBAUTHN_ORIGIN=http://localhost:8080
WEBAUTHN_USER_VERIFICATION=required
WEBAUTHN_CHALLENGE_TTL_SECONDS=300
WEBAUTHN_STEP_UP_TTL_SECONDS=300
```

Te wartości są domyślne w `compose.yaml` i plikach przykładowych środowiska. Laragon pozostaje poza tym układem; aplikacja działa na `http://localhost:8080`, PostgreSQL na `5433`, Valkey na `6380`, MinIO na `9001`, a Mailpit na `8025`.

## Niezmienniki

Walidator środowiska zatrzymuje start przy niebezpiecznej kombinacji:

- `prepare` wymaga wyłączonych FIDO2 i WebAuthn oraz `password` step-up;
- `test` nie może wymagać FIDO2 przy logowaniu;
- `required` wymaga jednocześnie aktywnego WebAuthn, FIDO2 i step-up `fido2`;
- administracyjny WebAuthn zawsze wymaga `userVerification=required`;
- origin i RP ID muszą do siebie pasować;
- środowisko produkcyjne wymaga originu HTTPS;
- Fizyczna Brama Zgody pozostaje `disabled` do czasu wdrożenia prawdziwego dostawcy;
- tryb w środowisku i rekordzie `security_settings` musi być zgodny.

## Źródła konfiguracji

- środowisko określa dozwolony tryb procesu i parametry RP/origin;
- `security_settings` jest trwałym stanem bezpieczeństwa i bramki aktywacyjnej;
- rozbieżność jest błędem fail-closed, a nie pretekstem do automatycznego obniżenia ochrony.

Nie należy zmieniać samego `.env`, aby „włączyć klucz”. Etap po zakupie wymaga kodu rejestracji i weryfikacji WebAuthn, dwóch przetestowanych kluczy, kodów odzyskiwania, backupu i jawnej decyzji właściciela.

## Diagnostyka

```powershell
docker compose exec -T postgres psql -U zrodlo -d zrodlo_slowa -c "SELECT dors3_mode,fido2_enabled,fido2_required,critical_step_up_method,physical_approval FROM security_settings WHERE id=1;"
docker compose exec -T app-1 php -r "require 'vendor/autoload.php'; var_export(class_exists('Webauthn\\PublicKeyCredential'));"
docker compose ps
```

Prawidłowy wynik obecnego etapu to `prepare | 0 | 0 | password | disabled` oraz dostępna klasa biblioteki WebAuthn.

