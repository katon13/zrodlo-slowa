# Punkty rozszerzeń bezpieczeństwa

## Zakres

To przygotowanie architektury, a nie wdrożenie WAF, KMS, SIEM, passkeys ani
nieusuwalnych backupów. Kod nie zależy od konkretnej chmury lub dostawcy tych
usług.

## Uwierzytelnienie i ponowne potwierdzenie

`AuthenticationContext` przechowuje metodę, listę czynników, czas logowania i czas
ostatniego silnego potwierdzenia. Czynniki są otwartą listą, więc późniejszy
`passkey`/WebAuthn nie wymaga zmiany formatu sesji. `Session` potrafi sprawdzić
ważność silnego potwierdzenia dla przyszłych operacji krytycznych.

Obecne wysokie role nadal wymagają zweryfikowanego e-maila i 2FA. Dodanie
step-up dla konkretnej operacji powinno wykorzystywać kontekst sesji, krótki TTL
i jednorazowe wyzwanie, a nie nową rolę.

## Uprawnienia

`PermissionCatalog` oddziela role od szczegółowych uprawnień, między innymi:

- podgląd, planowanie, prompt, test dostawcy i konfigurację AI;
- edycję i publikację artykułów;
- wniosek i ocenę zatwierdzenia finansowego;
- uzgadnianie, wypłaty, audyt i zarządzanie rolami.

Zwykły `reader` i `author` nie mają żadnego uprawnienia AI. Kontroler AI używa
permission guardu, ponownie wymaga zabezpieczeń wysokiej roli i audytuje wynik.
Wywołanie modelu nie ma publicznego endpointu użytkownika.

## Audyt i logi JSON

`StructuredAuditService` i `StructuredLoggerInterface` tworzą zdarzenia z polami
`user_id`, `actor_user_id`, `actor_role`, `operation`, `ip`, `request_id`,
`result` i szczegółami domenowymi.

Każde żądanie aplikacji otrzymuje `X-Request-ID`. Błędy PHP, zdarzenia
administracyjne SNAJPERA, operacje AI i audyt finansowy mają reprezentację JSON.
Obecny adapter zapisuje JSON do standardowego kanału błędów kontenera. Późniejszy
adapter SIEM może implementować ten sam interfejs.

## Sekrety i szyfrowanie

`SecretProviderInterface` oddziela pobieranie sekretów od ich źródła. Obecny
adapter czyta ENV. `EncryptionProviderInterface` oddziela szyfrowanie, a
`SecretCipher` jest lokalnym adapterem Sodium. Późniejszy Secret Manager/KMS
implementuje te kontrakty bez zmiany domeny.

## Integracje

| Integracja | Kontrakt |
|---|---|
| poczta | `MailSenderInterface` |
| AI | `AiProviderInterface` |
| płatności/Stripe | `PaymentGatewayInterface` |
| S3/MinIO | `ObjectStorageInterface` |
| logi/SIEM | `StructuredLoggerInterface` |
| sekrety | `SecretProviderInterface` |
| szyfrowanie/KMS | `EncryptionProviderInterface` |
| uzgadnianie finansów | `FinancialReconciliationSourceInterface` |

Upload korzysta z wymiennego storage. Laragon zachowuje adapter lokalny, natomiast
Docker używa adaptera S3 z prywatnym bucketem MinIO. Produkcja może podłączyć
zgodny magazyn S3 bez zmiany kontrolerów. Migracja lokalnych referencji jest
idempotentna, najpierw weryfikuje obiekt, a dopiero potem atomowo zmienia rekord.

## Finanse

Provider-neutralny snapshot obejmuje salda portfeli, liczbę i sumę transakcji
oraz wypłaty otwarte i zapłacone. `FinancialReconciliationService` porównuje dwa
źródła bez znajomości Stripe, banku czy konkretnego systemu księgowego.

Obecne transakcje zachowują transakcję DB, idempotency key, status, audyt i HMAC.
Zdarzenie bramki płatniczej ma atomową deduplikację `ON CONFLICT`; test dwóch
procesów potwierdza brak podwójnego rekordu.

## Dalsze wdrożenie

- WAF ma działać przed proxy i przekazywać zaufany request ID/IP według jawnej
  listy zaufanych proxy.
- SIEM dostanie nowy adapter loggera, bez zmian kontrolerów i domeny.
- KMS/Secret Manager dostaną adaptery z kontrolą timeoutu i fail-closed.
- Passkeys dostaną provider czynnika oraz step-up; nie zastąpią permission guardu.
- Immutable backup dostanie osobny cel, retencję i cykliczny test restore.

Ciężkie działania administracyjne i AI pozostają przeznaczone dla osobnych
kolejek i workerów opisanych w `ARCHITEKTURA_WORKEROW.md`; nie wolno wykonywać ich
w ścieżce HTTP.
