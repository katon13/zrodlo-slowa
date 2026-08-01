# 3DORS — FIDO2 i etap po zakupie kluczy

## Stan bieżący

FIDO2 nie jest aktywne i nie może zablokować administratora. Projekt ma przypiętą w `composer.lock` bibliotekę `web-auth/webauthn-lib` 5.3.5, model credentiali i challenge, ścisłą kontrolę origin/RP, challenge 32-bajtowe, hash przechowywany w PostgreSQL, ważność 5 minut oraz atomową ochronę przed replay między `app-1` i `app-2`.

Nie zaimplementowano jeszcze ceremonii rejestracji ani weryfikacji podpisu. Kryptografia WebAuthn nie jest implementowana ręcznie. Endpointy i kontrolki rejestracji celowo nie istnieją w trybie `prepare`.

## Dlaczego przygotowanie przed zakupem jest zasadne

Model etapowy pozwala teraz uszczelnić sesje, operacje krytyczne, audyt i recovery, a później wymienić dostawcę step-up bez zmiany wszystkich kontrolerów. Ogranicza to ryzyko blokady konta przez niedokończoną konfigurację sprzętu. Jednocześnie samo posiadanie biblioteki i tabel nie daje ochrony FIDO2 — do czasu poprawnej rejestracji kluczy ochroną przejściową pozostaje świeże hasło.

## Procedura po zakupie

Następny etap powinien rozpocząć się od osobnej, jawnej dyspozycji użytkownika:

1. wykonać i sprawdzić backup PostgreSQL;
2. wdrożyć ceremonie rejestracji, uwierzytelnienia, testu i odwołania przy użyciu przypiętej biblioteki;
3. weryfikować challenge, origin, RP ID, typ ceremonii, user handle, UV oraz licznik podpisów;
4. przejść kontrolowanie do `test`, synchronizując środowisko i `security_settings`;
5. zarejestrować osobno klucz podstawowy i zapasowy;
6. przetestować oba klucze, replay, zły origin, wygaśnięcie i przepływ `app-1 → app-2`;
7. wygenerować 10 kodów odzyskiwania, zapisać offline i potwierdzić;
8. przetestować recovery CLI na koncie testowym;
9. pozostawić logowanie hasłem działające w `test` i obserwować zdarzenia;
10. dopiero po spełnieniu całej bramki i kolejnej jawnej zgodzie rozważyć `required`.

## Bramka `required`

Wymagane są: dwa aktywne i przetestowane klucze, 10 potwierdzonych kodów, test CLI, sprawdzony backup, test między instancjami, replay, błędny origin oraz jawna decyzja właściciela. W `required` nie może istnieć zwykły checkbox cofający ochronę do hasła.

Produkcyjny origin musi używać HTTPS i odpowiadać RP ID. `localhost` i `http://localhost:8080` są poprawne wyłącznie dla lokalnego środowiska deweloperskiego.

