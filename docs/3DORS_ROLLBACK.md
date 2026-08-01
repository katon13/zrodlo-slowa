# 3DORS — rollback

## Obecny tryb `prepare`

FIDO2 i WebAuthn są już wyłączone, więc rollback nie wymaga omijania klucza. Najpierw wykonaj backup PostgreSQL i zapisz bieżące wartości `security_settings`, liczbę credentiali, kodów, sesji oraz ostatnie zdarzenia.

Zmiany bazy są addytywne. Przy rollbacku aplikacji pozostaw tabele 3DORS do zachowania audytu i kompatybilności migracji. Nie usuwaj `security_events`, kodów ani credentiali podczas awarii. Usuwanie tabel wymaga osobnej, kontrolowanej migracji retencyjnej po eksporcie audytu.

## Kolejność

1. zablokuj administracyjne mutacje i wykonaj backup;
2. potwierdź salda i historię portfeli przed zmianą;
3. wycofaj kod aplikacji do wskazanego commita;
4. zachowaj bezpieczne zmienne `prepare` i nie przełączaj samoczynnie trybu;
5. odtwórz/restartuj wyłącznie kontenery aplikacji, bez zmiany portów i usług Laragona;
6. uruchom migracje kompatybilne w przód, smoke test, logowanie oraz test odczytu panelu;
7. ponownie porównaj salda i historię portfeli.

## Po przyszłym włączeniu FIDO2

- `test` musi zawsze pozwalać administratorowi wejść hasłem;
- z `required` nie wolno zejść zwykłą zmianą `.env` lub checkboxem;
- kontrolowane `required → test` wymaga działającego klucza albo recovery CLI;
- nigdy nie przechodź automatycznie do niezdefiniowanego trybu `off`;
- po zmianie trybu zakończ stare sesje i zwiększ `session_version`.

## Kryterium bezpiecznego wycofania

Rollback jest zakończony dopiero po poprawnym logowaniu, działaniu `app-1` i `app-2`, przejściu testów, potwierdzeniu spójności `security_settings` ze środowiskiem oraz identyczności sald i historii finansowej względem raportu sprzed operacji.

