# Rejestracja urządzeń 3DORS Mobile

## Warunki

- Rejestrację rozpoczyna administrator po ponownym podaniu hasła.
- Wariant Admin wymaga aktywnego konta z rolą `admin`.
- Wariant Author wymaga aktywnego konta, roli `author` i `can_write=1` (aktywna współpraca).
- QR zawiera `application_variant`, środowisko, protokół v1, TTL i jednorazowy token.

## Przepływ

1. Administrator wybiera konto i wariant w panelu 3DORS.
2. Backend zapisuje hash tokenu i zaszyfrowany kod porównawczy, po czym pokazuje QR tylko raz.
3. Właściwa aplikacja sprawdza wariant, środowisko, protokół i TTL przed utworzeniem klucza.
4. Android generuje EC P-256 w Keystore; preferuje StrongBox, potem TEE, a poziom zapisuje jawnie jako metadane.
5. Telefon wysyła wyłącznie publiczny X.509 SPKI i metadane.
6. Backend atomowo zużywa enrollment, tworzy osobne `device_public_id` i `credential_public_id`, zwraca ten sam kod porównawczy.
7. Użytkownik porównuje kod z panelem i zatwierdza lub odrzuca.
8. Dopiero zgodność aktywuje urządzenie i credential. Odrzucenie je unieważnia.

Tokenu nie można użyć drugi raz. Zły wariant, zła rola, wygasły token, klucz inny niż EC P-256 i nieaktywny autor są odrzucane fail-closed. Reinstalacja aplikacji wymaga nowego enrollmentu/credentialu; starego klucza prywatnego nie można odzyskać.

## Lifecycle

`pending -> active -> suspended|lost|revoked`. Każda zmiana z `active` anuluje oczekujące żądania, unieważnia credential przy statusie lost/revoked i jest rejestrowana w audycie. Cofnięcie `lost` lub `revoked` nie jest automatyczne — należy przeprowadzić nową rejestrację.
