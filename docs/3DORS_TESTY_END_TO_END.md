# Testy end-to-end 3DORS

## Testy wykonane bez bazy/sprzętu

- PHPUnit Unit: protokół, ECDSA i tamper, warianty, migracja, routing/CSRF, QR, flagi, limity treści.
- PHPStan: zmieniony kod 3DORS bez błędów.
- Android Unit dla Admin i Author: OK.
- Android Lint dla obu release: BUILD SUCCESSFUL (47 nieblokujących rekomendacji na wariant, brak błędów).
- Build obu release APK: OK.
- APK Analyzer: osobne applicationId i fizycznie różne polityki operacji.

## Automatyzacja integracyjna

`tests/Integration/Dors3MobileIntegrationTest.php` obejmuje enrollment z realnym kluczem EC, kod porównawczy, approve, podpisany reject, replay, tamper, trwałe TTL, zły wariant, deduplikację i zawieszenie urządzenia. Test wymaga PostgreSQL ze wszystkimi migracjami.

```powershell
php scripts/migrate.php
php vendor/bin/phpunit tests/Integration/Dors3MobileIntegrationTest.php
```

Na stacji odbiorowej w dniu implementacji Docker Desktop/PostgreSQL nie były dostępne; pełny PHPUnit czekał na połączenie i został przerwany limitem. Z tego powodu poniższe scenariusze są obowiązkową checklistą, a nie deklaracją wykonania.

## E2E Admin

1. Włączyć wyłącznie flagi testowe Admin.
2. Zarejestrować APK Admin, porównać kod, sprawdzić StrongBox/TEE.
3. Zalogować się przez approve i powtórzyć przez reject/TTL.
4. Utworzyć testową wypłatę bez realnych środków; sprawdzić dane telefonu.
5. Approve równolegle z drugim retry; potwierdzić dokładnie jeden maker-checker request i audyt.
6. Sprawdzić reject, zawieszenie, lost i revoke.

## E2E Author

1. Aktywne konto author + `can_write=1`; zarejestrować APK Author.
2. Szkic z tytułem, treścią i obrazem; approve submit ma dać jeden status `submitted`.
3. Reject pozostawia szkic; zmiana treści po utworzeniu request powoduje fingerprint mismatch.
4. Po zatwierdzeniu i z rolą publisher/chief_editor sprawdzić publish.
5. Autor bez aktywnej współpracy i APK Author próbujące operacji Admin muszą zostać odrzucone.

## E2E FIDO2

Po dostarczeniu klucza: utworzyć matching authorization, ustawić testowo `mobile_and_fido2`, potwierdzić brak wykonania po samym mobile i dokładnie jedno wykonanie po obu czynnikach. Następnie cofnąć politykę do niewymaganej.
