# Architektura backendu 3DORS Mobile

## Komponenty

- `Dors3MobileService` — rejestracja, żądania, decyzje, lifecycle, limity i audyt.
- `MobileProtocol` — jeden kanoniczny format payloadu v1 zgodny z Androidem.
- `MobileSignatureVerifier` — X.509 SPKI, EC `prime256v1/secp256r1`, SHA-256/ECDSA.
- `Dors3OperationFingerprintService` — fingerprint artykułu, publikacji, wypłaty i zmiany roli.
- `Dors3MobileOperationExecutor` — wykonanie submit/publish, payout oraz zmiany roli podstawowej/redakcyjnej.
- kontrolery API i panelu — walidacja transportu, JSON `no-store`, CSRF na trasach przeglądarkowych.

## Model danych

Migracja `database/postgresql/migrations/20260803_001_3dors_mobile.sql` jest addytywna. Dodaje urządzenia, credentiale, enrollmenty, approval requests, podpisy, polityki, operacje odroczone i liczniki limitów. Nie zmienia istniejącej księgi ani tabel wypłat.

Sekrety enrollmentu, challenge, nonce i kod porównawczy nie są przechowywane jawnie: używane są hashe do porównania i ciphertext do późniejszej rekonstrukcji protokołu. Klucz prywatny istnieje wyłącznie w Android Keystore.

## Stan żądania

```text
pending -> consumed (approved_at albo rejected_at)
pending -> expired
pending -> cancelled (utrata/zawieszenie/unieważnienie urządzenia)
```

Podczas decyzji rekord żądania, urządzenia i credentialu jest blokowany `FOR UPDATE`. Backend odtwarza payload z danych DB, sprawdza TTL, status, wariant, użytkownika, urządzenie, credential, algorytm i podpis. Operacja odroczona, jej oznaczenie `executed` i zużycie approval request następują w jednej transakcji. Replay zwraca 409.

## Endpointy

- enrollment: complete/confirm;
- request: details/approve/reject;
- device: pending request/status/heartbeat;
- auth browser: start/status/challenge/complete;
- panel admina: start enrollment, suspend, lost, revoke.

Dokładne payloady są w `mobile/3dors-android/docs/KONTRAKT_BACKENDU_3DORS_MOBILE.md`.

## Wiele instancji

Cały stan autoryzacji jest w PostgreSQL, a rate limiting aplikacyjny może używać Valkey. Brak zależności od pamięci konkretnej instancji HTTP. Blokady wierszy i unikalny indeks oczekującej operacji zabezpieczają wyścigi między instancjami. Ciężkie skanowanie/indeksowanie pozostaje w istniejących workerach; podpis nie uruchamia aplikacji przy autosave.
