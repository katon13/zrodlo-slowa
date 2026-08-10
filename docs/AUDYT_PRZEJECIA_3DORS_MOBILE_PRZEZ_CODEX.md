# Audyt przejęcia 3DORS Mobile przez Codex

Data audytu: 3 sierpnia 2026. Zakres: wyłącznie `X:\zrodlo-slowa`, klient Android w `mobile/3dors-android` i istniejący backend PHP/PostgreSQL.

## Stan zastany

Klient był pojedynczą aplikacją Compose. Miał już klucz EC `secp256r1` w Android Keystore (StrongBox z kontrolowanym fallbackiem), biometrię/PIN dla każdego podpisu, podpisane `approve` i `reject`, kanoniczny payload v1, rozdzielone identyfikatory enrollment/device/credential, TTL, lokalną ochronę replay, App Links, wyłączony backup i `FLAG_SECURE`. Brakowało fizycznego rozdzielenia Admin/Author i działającego backendu kontraktu mobilnego.

Backend posiadał przygotowany panel `/admin/security/3dors`, audyt `security_events`, password step-up, recovery codes, fundament WebAuthn/FIDO2, maker-checker wypłat, księgę, kolejkę, Valkey i konfigurację dwóch instancji. FIDO2 pozostawał celowo wyłączony bez kompletnej biblioteki/klucza produkcyjnego.

## Zidentyfikowane luki i decyzje

| Obszar | Luka | Rozwiązanie |
|---|---|---|
| Android | jeden APK | flavor `admin` i `author`, osobne applicationId, zasoby i skompilowane polityki |
| Backend | brak mobile auth | addytywna migracja `20260803_001_3dors_mobile.sql`, serwis, kontrolery i endpointy |
| Rejestracja | brak QR i lifecycle | jednorazowy token, zaszyfrowany kod porównawczy, lokalnie generowany SVG QR, statusy urządzenia |
| Integralność | brak serwerowej weryfikacji | rekonstrukcja payloadu wyłącznie z DB, ECDSA-SHA256, binding użytkownik–wariant–device–credential |
| Operacje | ryzyko TOCTOU | fingerprint, odroczona operacja i wykonanie w tej samej transakcji co zużycie podpisu |
| Spam/replay | brak limitów mobilnych | limity DB per konto/device/IP, limit pending, unikalna operacja pending, deduplikacja |
| Wdrożenie | ryzyko regresji | wszystkie flagi domyślnie false, tryb `disabled`; `required` nie został włączony |

## Weryfikacja wykonana

- PHPUnit Unit: 57 testów, 429 asercji — wynik OK.
- PHPStan dla zmienionego kodu 3DORS — wynik OK.
- Android: `testAdminDebugUnitTest`, `testAuthorDebugUnitTest`, `assembleAdminRelease`, `assembleAuthorRelease` — wynik OK.
- Android Lint: `lintAdminRelease` i `lintAuthorRelease` — BUILD SUCCESSFUL; raport zawiera wyłącznie rekomendacje zależności/KTX i nieużywanych zasobów, bez błędów blokujących.
- APK Analyzer: pakiety `pl.zrodloslowa.dors3.admin` i `pl.zrodloslowa.dors3.author`; dekompilacja `VariantPolicy` potwierdziła osobne zbiory operacji.
- Pełne testy integracyjne dodano w `tests/Integration/Dors3MobileIntegrationTest.php`, ale lokalnie nie uruchomiono ich, ponieważ na stacji nie ma dostępnego PostgreSQL ani Docker Desktop. Nie wykonywano resetu bazy.

## Otwarte blokady odbiorowe

- Uzupełnić prawdziwe hosty API/App Links i wygenerować dwa niezależne klucze podpisu release.
- Uruchomić migrację i scenariusze E2E na lokalnym PostgreSQL.
- Przetestować biometrię i StrongBox na fizycznym Androidzie.
- Dokończyć fizyczny scenariusz WebAuthn/FIDO2 po dostarczeniu klucza i włączeniu biblioteki. Polityka `mobile_and_fido2` jest przygotowana fail-closed.

Nie zmieniono sald, wpisów księgi ani rzeczywistych wypłat. Nie wykonano commita ani push.
