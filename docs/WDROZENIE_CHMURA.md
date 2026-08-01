# Plan wdrożenia do chmury

> Ten dokument jest planem operacyjnym. ETAP 10 nie tworzy konta, infrastruktury,
> zasobów płatnych ani wdrożenia w żadnej chmurze.

## Założenia neutralne wobec dostawcy

Wymagane klasy usług to: rejestr obrazów, runtime kontenerów, load balancer,
zarządzany PostgreSQL, zarządzany Valkey, magazyn zgodny z S3, dostawca poczty oraz
centralny odbiornik logów JSON. Kod komunikuje się z nimi przez konfigurację i
adaptery. Nie wymaga konkretnego WAF, SIEM, KMS ani platformy orkiestracji.

| Potrzeba | Kontrakt aplikacji | Implementacja lokalna |
|---|---|---|
| baza | PDO PostgreSQL | kontener PostgreSQL |
| sesje/cache/limity/sygnały | adapter Valkey | kontener Valkey |
| pliki | `ObjectStorageInterface`, API S3 | MinIO |
| sekrety | `SecretProviderInterface` | zmienne środowiskowe |
| szyfrowanie | `EncryptionProviderInterface` | klucz środowiskowy |
| poczta | adapter transportu | Mailpit/SMTP |
| płatności | adapter bramki i webhooka | konfiguracja testowa |
| AI | kontrolowany adapter dostawcy | domyślnie wyłączony |
| audyt i logi | zdarzenia JSON | stdout/stderr i PostgreSQL |

## Bramka przed wdrożeniem

- release powstaje z czystego, oznaczonego commita; pliki nieśledzone nie trafiają
  do kontekstu obrazu;
- `composer install` używa lockfile i produkcyjnego targetu obrazu;
- testy, analiza statyczna, test świeżej bazy, ETAP 8, ETAP 9 i verifier księgi są
  zielone dla tego samego commita;
- wykonano backup PostgreSQL oraz próbę odtworzenia w izolowanym środowisku;
- znane są limity połączeń, CPU, RAM i współbieżności każdej roli;
- produkcyjne sekrety są silne, różne od lokalnych i nie znajdują się w Git;
- skonfigurowano DNS, TLS, retencję logów i alerty podstawowej dostępności;
- istnieje właściciel decyzji rollback oraz okno na migracje.

## Kolejność pierwszego wdrożenia

1. Zbuduj raz produkcyjny obraz z commita i oznacz go niezmiennym digestem.
2. Utwórz sieć prywatną oraz zarządzany PostgreSQL z backupami i szyfrowaniem
   dostawcy. Nie udostępniaj bazy publicznie.
3. Utwórz zarządzany Valkey z uwierzytelnianiem/TLS i limitem pamięci.
4. Utwórz prywatny bucket S3, reguły CORS/lifecycle tylko jeśli są potrzebne oraz
   konto o minimalnych uprawnieniach do jednego prefiksu.
5. Wstrzyknij konfigurację z `.env.production.example` przez bezpieczny mechanizm
   runtime. Nie kopiuj pliku `.env` do obrazu.
6. Uruchom jednorazowy job migracji. Zatrzymaj start aplikacji, jeśli migracja,
   `scripts/install.php --check` albo kontrola księgi zakończy się błędem.
7. Uruchom dwie instancje HTTP bez publicznego dostępu, sprawdź `/health/live`,
   `/health/ready`, logowanie, rejestrację, artykuły, saldo i obiekt S3.
8. Uruchom osobno `worker-earnings`, `worker-email` i `scheduler`. `worker-ai`
   pozostaw wyłączony do czasu konfiguracji uprawnień, budżetu i dostawcy.
9. Podłącz load balancer, TLS i stopniowo skieruj ruch. Sticky sessions są zbędne.
10. Obserwuj błędy, p95, połączenia DB, blokady, kolejki, retry, koszty i audyt.
    Zwiększaj ruch dopiero po przejściu kontroli sald i historii.

## Migracja istniejących danych

Migrację MySQL do PostgreSQL wykonuje się poza Laragonem i tylko na kopii/eksporcie.
Laragon nie jest przekonfigurowywany. Przed przełączeniem należy porównać liczności,
klucze obce, sumy finansowe, salda i historię. Uploady przenosi skrypt z manifestem i
sumami kontrolnymi, po czym wykonuje test odczytu i usunięcia obiektu.

Przełączenie ruchu jest dozwolone dopiero po:

- zakończeniu migracji i migracji schematu;
- porównaniu danych źródłowych i docelowych;
- `scripts/reconcile_finances.php` oraz `scripts/verify_ledger.php` z wynikiem OK;
- utworzeniu i weryfikacji anchora;
- smoke teście na obu instancjach.

## Backup, odtworzenie i eksport

Repozytorium zawiera polecenia:

```bash
scripts/ops/postgres_backup.sh
scripts/ops/postgres_export.sh
scripts/ops/postgres_restore.sh
```

Backup jest użyteczny dopiero po okresowej próbie odtworzenia do odseparowanej bazy
i ponownej kontroli aplikacji oraz księgi. ETAP 10 nie wdraża nieusuwalnych backupów,
ale format i procedura nie zależą od dostawcy. Retencję, szyfrowanie i dostęp należy
ustalić przed produkcją.

## Rolling update i rollback

Migracje muszą być zgodne wstecz z poprzednią wersją przez czas wdrożenia. Najpierw
wdraża się zmianę rozszerzającą schemat, potem kod, a usunięcie starej kolumny lub
mechanizmu odbywa się dopiero w osobnym wydaniu po pomiarze zgodności.

Rollback kodu używa poprzedniego digestu. Migracji danych nie cofa się automatycznie
destrukcyjnym skryptem; preferowana jest migracja naprawcza. Przy błędzie finansowym
należy zatrzymać workery finansowe, zachować ruch odczytowy jeśli jest bezpieczny,
zabezpieczyć logi/audyt, wykonać uzgodnienie i dopiero potem wznowić przetwarzanie.

## Przygotowanie pod późniejsze zabezpieczenia

- MFA/passkeys można dodać do istniejącego modelu uwierzytelniania i reautoryzacji;
- Secret Manager/KMS zastąpi adapter środowiskowy bez zmiany domeny;
- WAF może stanąć przed load balancerem bez zależności w kodzie;
- zdarzenia JSON mogą zostać wysłane do SIEM przez niezależny collector;
- immutable backup może zostać włączony jako polityka magazynu backupów;
- automatyczne uzgodnienia mogą podłączyć kolejne źródło przez interfejs finansowy.

Żaden z tych elementów nie jest wdrażany „na zapas” w ETAPIE 10. Decyzja wymaga
analizy ryzyka, kosztu i wymagań prawnych.

## Kontrola kosztów

Na start nie jest wymagany Kubernetes, osobny broker ani replika odczytowa. Ustaw
budżety i alerty kosztowe, limity autoskalowania, retencję logów i lifecycle
obiektów. AI pozostaje oddzielnym, zatrzymywalnym workerem z limitem kosztu. Skala
jest zwiększana na podstawie pomiarów opisanych w [SKALOWANIE.md](SKALOWANIE.md), a
nie na podstawie prognozy bez danych.
