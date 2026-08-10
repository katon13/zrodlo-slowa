# Panel administracyjny 3DORS

Rozszerzenie wykorzystuje istniejący ekran `/admin/security/3dors`; nie powstał drugi, konkurencyjny panel.

## Sekcje mobilne

- rejestracja urządzenia dla wskazanego ID użytkownika i wariantu Admin/Author;
- jednorazowy lokalny QR oraz sześciocyfrowy kod porównawczy;
- lista urządzeń z właścicielem, wariantem, modelem, wersjami, poziomem Keystore, datami i ostatnim podpisem;
- akcje zawieś, oznacz jako utracone i unieważnij, każda z CSRF i aktualnym hasłem 3DORS;
- oczekujące decyzje z typem operacji, zasobem, fingerprintem i TTL;
- polityki operacji (`mobile`, `fido2`, `mobile_or_fido2`, `mobile_and_fido2`);
- ostatnie decyzje i istniejący wspólny audyt `security_events`.

Panel nie pokazuje tokenu ponownie po przeładowaniu. QR jest generowany na serwerze przez `endroid/qr-code` jako data URI SVG; sekret nie trafia do CDN ani usługi zewnętrznej. Tekstowy JSON pozostaje awaryjną reprezentacją do diagnostyki tylko na jednorazowym ekranie.

## Uprawnienia

Wejście wymaga roli administratora i istniejącej polityki sesji 3DORS. Start rejestracji wykonuje password re-auth. Zmiany lifecycle urządzenia korzystają z istniejącego critical step-up. Operacje są audytowane z request/correlation ID, aktorem, zasobem, wariantem i credentialem, bez zapisywania challenge, tokenu czy pełnego podpisywanego sekretu w logach.

Po ręcznym włączeniu `DORS3_ADMIN_CRITICAL_APPROVAL` zmiana roli podstawowej i synchronizacja ról redakcyjnych są odraczane do podpisu 3DORS Admin. Executor ponownie odczytuje bieżące role i porównuje fingerprint przed zapisem. Pozostałe pozycje krytyczne z katalogu polityk są przygotowane fail-closed, ale wymagają osobnego wykonawcy domenowego przed ustawieniem `enforced=1`.

## Tryb odbioru

Widok działa przy braku nowej migracji (sekcje mobilne są puste), co pozwala bezpiecznie wdrożyć kod przed migracją. Funkcje mobilne zaczynają działać dopiero po migracji i ręcznym ustawieniu flag testowych.
