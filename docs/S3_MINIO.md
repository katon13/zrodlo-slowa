# Magazyn obiektowy S3/MinIO

## Zakres ETAPU 5

Uploady artykułów i avatarów korzystają z `ObjectStorageInterface`. Docker używa
prywatnego bucketu MinIO przez zgodny interfejs S3, a dotychczasowy tryb Laragona
pozostaje na adapterze lokalnym. Kontenery aplikacji nie przechowują trwałych
uploadów na swoich dyskach.

Klucze obiektów są unikalne i mają losowy składnik. Zapis weryfikuje rzeczywisty
MIME i limit rozmiaru, a obsługiwane rozszerzenia są jawnie ograniczone. Publiczne
obrazy mają klucze z prefiksem `public/` i są wydawane przez aplikację jako
`/objects/...`. Obiekt bez tego prefiksu zwraca 404 na publicznej trasie. Bucket
nie wymaga publicznej polityki.

## Konfiguracja

Wspólne zmienne adaptera:

```dotenv
OBJECT_STORAGE_DRIVER=s3
S3_ENDPOINT=http://minio:9000
S3_REGION=us-east-1
S3_BUCKET=zrodlo-slowa-local
S3_ACCESS_KEY=...
S3_SECRET_KEY=...
S3_PATH_STYLE=true
S3_MAX_ATTEMPTS=3
S3_CONNECT_TIMEOUT=2
S3_REQUEST_TIMEOUT=10
S3_MAX_READ_BYTES=10485760
```

`S3_ENDPOINT` może pozostać pusty dla standardowego endpointu dostawcy. MinIO
wymaga lokalnie `S3_PATH_STYLE=true`. Sekrety produkcyjne muszą pochodzić spoza
repozytorium. Produkcja wymaga `OBJECT_STORAGE_DRIVER=s3` i HTTPS dla jawnie
podanego endpointu.

Compose automatycznie uruchamia jednorazowy `object-storage-init`, który tworzy
bucket, jeżeli jeszcze nie istnieje. Ręczne, idempotentne przygotowanie:

```powershell
docker compose run --rm object-storage-init
```

## Migracja istniejących uploadów

Przed migracją wykonaj backup bazy oraz katalogu `public/uploads`. Domyślny tryb
jest tylko raportem i niczego nie zapisuje:

```powershell
docker compose run --rm upload-migration
```

Migracja właściwa:

```powershell
docker compose run --rm upload-migration --apply
```

Skrypt obsługuje referencje obrazów w `media.path`, `users.avatar_path`,
`user_profiles.avatar_path` i `main_banners.image_path`. Dla każdego rekordu:

1. sprawdza, czy ścieżka pozostaje w `public/uploads`;
2. weryfikuje rozmiar, rozszerzenie i MIME obrazu;
3. zapisuje obiekt pod deterministycznym, unikalnym kluczem;
4. potwierdza obecność obiektu w bucketcie;
5. aktualizuje bazę tylko wtedy, gdy stara referencja nadal jest aktualna.

Usuwanie plików źródłowych jest osobną, opcjonalną operacją:

```powershell
docker compose run --rm upload-migration --apply --delete-source
```

Użyj jej dopiero po sprawdzeniu raportu, widoczności wszystkich obrazów i kopii
zapasowej. Plik jest usuwany tylko wtedy, gdy wszystkie odnoszące się do niego
rekordy zostały poprawnie przeniesione.

Profil narzędziowy montuje wyłącznie `public/uploads` do jednorazowego kontenera.
Zwykłe kontenery aplikacji nadal nie mają dostępu do trwałych plików hosta.

## Weryfikacja zgodności

Test integracyjny wykonuje rzeczywiste operacje PUT, HEAD, GET i DELETE na MinIO,
sprawdza odczyt tego samego publicznego obrazu przez obie instancje aplikacji oraz
potwierdza brak publicznego dostępu do obiektu prywatnego:

```powershell
docker compose exec -T app-1 vendor/bin/phpunit tests/Integration/S3ObjectStorageIntegrationTest.php tests/Integration/PublicObjectRouteIntegrationTest.php
```

`/health/ready` sprawdza dostępność SDK i bucketu. Błąd magazynu podczas publicznego
odczytu daje kontrolowane 503 z `Retry-After`, zamiast błędu aplikacji lub próby
zapisu na lokalny dysk.

## Zasady operacyjne

- publiczny URL nie ujawnia sekretów ani danych dostępowych do S3;
- obiekty prywatne wymagają przyszłej, autoryzowanej ścieżki domenowej;
- usunięcie starego obiektu następuje dopiero po zapisaniu nowej referencji;
- nie montuj `public/uploads` jako współdzielonego trwałego wolumenu kontenerów;
- retry ma ograniczoną liczbę prób i jawne timeouty;
- adapter nie zależy od konkretnego dostawcy chmury.
