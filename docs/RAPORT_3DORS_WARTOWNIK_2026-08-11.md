# Raport wdrożenia — 3DORS Wartownik

Data: 2026-08-11  
Repozytorium: `katon13/zrodlo-slowa`  
Zakres: dopracowanie istniejącego Wartownika bez zmiany architektury 3DORS, recovery, sesji i autoryzacji.

## Wynik

Wartownik został uporządkowany jako panel operacyjny dla człowieka. Normalne zdarzenia pozostają w logach, a alerty są tworzone tylko dla zdarzeń wymagających uwagi. Kolejne techniczne etapy tej samej operacji są prezentowane jako jeden alert ze szczegółami.

System zachowuje wspólną bazę audytową PostgreSQL dla obu serwerów aplikacji. Archiwizacja działa w istniejącej trwałej kolejce, jest chroniona przez 3DORS i ma osobny, niskopriorytetowy worker. Nie utworzono równoległego systemu bezpieczeństwa ani logowania.

## Co widzi operator

- zakładki: Aktywne, Otwarte alerty, Przyjęte, Rozwiązane, Logi i Archiwum;
- konkretny opis alertu: czynność, osoba, zasób i powód zwrócenia uwagi;
- jedno kliknięcie „Przyjmij” zapisujące operatora i czas;
- „Rozwiąż” z krótkim, kontrolowanym powodem i opcjonalną notatką;
- etapy techniczne operacji dostępne dopiero po rozwinięciu szczegółów;
- maksymalnie 20 ostatnich zakończonych sesji i kompaktowo pogrupowane próby logowania;
- wyszukiwanie, zakres dat, obszar i użytkownik;
- kontrolę wielkości tabel logów oraz progi ostrzegawcze;
- chronione przez 3DORS zlecenie przeniesienia starych logów do archiwum.

Panel ma spójny tryb ciemny. Nowe teksty pochodzą z `resources/lang/dors3.json` i są kompletne dla PL, EN, DE, FR, IT oraz ES.

## Reguły alertów

- poprawne logowanie, rozpoczęcie i zakończenie sesji oraz zwykłe poprawne działania są logami;
- alert powstaje dla zdarzeń HIGH/CRITICAL oraz logowania z nowego kontekstu;
- pojedyncze nieudane próby hasła pozostają w logach, a alarm powstaje przy zdarzeniu przekroczenia limitu;
- techniczne zdarzenia rozpoczęcia potwierdzenia nie tworzą osobnych alertów;
- klucz operacji łączy etapy po korelacji, rodzaju operacji i zasobie;
- unikalny indeks w PostgreSQL gwarantuje jedną operację na jeden alert;
- historia źródłowa oraz przejścia statusów pozostają dostępne.

## Wydajność i retencja

- główne zapytania są ograniczone czasem, limitem rekordów i nowymi indeksami;
- liczebność dużych tabel jest szacowana przez statystyki PostgreSQL zamiast pełnego liczenia;
- rozmiar obejmuje dane i indeksy i jest pobierany przez `pg_total_relation_size`;
- domyślne progi zajętości: ostrzeżenie 1 GB, stan krytyczny 5 GB;
- logi kontenerów mają rotację `10 MB × 3 pliki` dla każdego serwisu Compose;
- archiwizacja ma osobną kolejkę `security-maintenance`, priorytet `-20`, limit 0,15 CPU i 128 MB RAM;
- porcje mają domyślnie 1000, a bezwzględny limit usługi wynosi 5000 rekordów na tabelę i transakcję;
- transakcja używa `SKIP LOCKED`, `lock_timeout=250ms` i `statement_timeout=10s`;
- worker robi przerwę po każdej porcji i nie wykonuje ciężkiej pracy w żądaniu HTTP;
- błędy korzystają ze standardowych ponowień i dead letter istniejącej kolejki.

## Bezpieczeństwo archiwum

- zlecenie wymaga aktywnej sesji administratora i świadomego potwierdzenia bieżącym hasłem w 3DORS;
- identyfikator autoryzacji, operator i granica czasu są zapisane w partii archiwizacji;
- kopiowanie do archiwum i usuwanie z tabeli bieżącej odbywa się atomowo w jednej transakcji;
- usuwane są wyłącznie rekordy faktycznie zapisane w archiwum;
- zdarzenia powiązane z alertami oraz ich korelacjami nie są przenoszone z aktywnego audytu;
- tabele archiwalne są niezmienne — PostgreSQL blokuje UPDATE i DELETE triggerem;
- źródłowe logi nie są kasowane ręcznie i nie giną.

## Migracja

Zastosowano `20260811_013_sentinel_operations_and_archive`:

- 32 instrukcje wykonane poprawnie w 100 ms;
- 10 alertów i 10 unikalnych kluczy operacji;
- 0 zduplikowanych operacji;
- utworzone indeksy, powiązania etapów, partie archiwum i niezmienne tabele archiwalne.

## Weryfikacja

- PHPUnit: 257 testów, 55 651 asercji, 0 błędów, 9 świadomie pominiętych;
- PHPStan: brak błędów;
- kontrola Compose: konfiguracja poprawna;
- migracja PostgreSQL: `applied`;
- kolejka `security-maintenance`: 0 dead letter i 0 oczekujących zadań po weryfikacji;
- aplikacja `app-1`: readiness OK, PostgreSQL/Valkey/MinIO OK;
- aplikacja `app-2`: readiness OK, PostgreSQL/Valkey/MinIO OK;
- proxy: health OK i odpowiedź HTTP 200;
- automatyczny test panelu i sześciu wersji językowych: PASS.

## Materiał wizualny

- `docs/screenshots/3dors-wartownik-pl.png` — aktywny panel PL;
- `docs/screenshots/3dors-wartownik-en.png` — aktywny panel EN;
- `docs/screenshots/3dors-wartownik-archiwum-pl.png` — archiwum, kontrola miejsca i operacja 3DORS.

## Pliki wdrożenia

Najważniejsze elementy znajdują się w:

- `app/Services/Dors3SentinelService.php`;
- `app/Services/Dors3SentinelAlertService.php`;
- `app/Services/Dors3SentinelArchiveService.php`;
- `app/Jobs/Dors3SentinelArchiveJobHandler.php`;
- `app/Controllers/Dors3SentinelController.php`;
- `views/admin/dors3_sentinel.php`;
- `scripts/worker_sentinel.php`;
- `database/postgresql/migrations/20260811_013_sentinel_operations_and_archive.sql`;
- `resources/lang/dors3.json`;
- `compose.yaml`.

## Status

Wdrożenie jest aktywne na obu serwerach aplikacji i gotowe do dalszych testów operatorskich. Nie wykryto blockera w obecnym kodzie.
