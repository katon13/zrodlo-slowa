# Raport startowy selektywnego przywracania

Data inwentaryzacji: 1 sierpnia 2026 r.

Repozytorium: `X:\zrodlo-slowa`, gałąź `main`

Źródło: niezmieniony katalog danych Laragona, MySQL 8.4, baza `zrodlo_slowa`

Cel: PostgreSQL w izolowanym środowisku Docker

## Zabezpieczenie przed zmianami

Artefakty znajdują się w ignorowanym przez Git katalogu:

```text
X:\zrodlo-slowa\backups\restore-admin-20260801-093517
```

| Artefakt | Rozmiar | SHA-256 |
|---|---:|---|
| logiczny dump MySQL `mysql-zrodlo_slowa-logical.sql` | 553244 B | `13aa0e99fd1de320a7af72e91b60880ebae192b52f37860cafafdd3cdaf1f29a` |
| dump PostgreSQL `postgres-before.dump` | 556643 B | `40098f5aa21978675d6ed614ad784f245e137ba71efb2aa919b55cf0440f1e22` |
| manifest uploadów `uploads-sha256.csv` | 768 B | `4ed357e7ec36330a569d24ecab9e1c184c5fdee3292e667f06eca1d50f989c54` |
| manifest fizycznego snapshotu MySQL | 39593 B | `ec06ba4929952c2209919b95386eb29a0a756eed3eb5e1e858da0e9ada3bf547` |

Fizyczny snapshot `mysql-physical-pristine/data` obejmuje 325 plików i 252154211
bajtów. Każdy wpis manifestu ma zgodny rozmiar oraz SHA-256 z zatrzymanym katalogiem
źródłowym. Snapshot pristine nie jest uruchamiany. Zapytania diagnostyczne wykonuje
osobna kopia robocza na `127.0.0.1:3307`. Oryginalny MySQL Laragona pozostaje
zatrzymany i niezmieniony.

Manifest `public/uploads` obejmuje 7 plików. Nie usunięto ani nie zmieniono plików
źródłowych.

## Jednoznaczna identyfikacja administratora

Znaleziono dokładnie jeden rekord odpowiadający wskazanej osobie:

| Pole | Wartość źródłowa |
|---|---|
| `users.id` | 4 |
| `legacy_id` | 8 |
| e-mail | `admin@100pl.pl` |
| nazwa wyświetlana | `Paweł Zastrzeżyński` |
| kod UTF-8 nazwy | `50617765C582205A617374727A65C5BC79C584736B69` |
| status | `deleted` |
| role | `author` |
| portfel | `wallets.id=4`, `user_id=4` |
| data utworzenia | `2019-05-02 08:32:39` |

Nie znaleziono drugiego podobnego rekordu. Źródło nie zawiera osobnej tabeli
uprawnień operacyjnych. Stan `deleted` i brak roli administratora są rozbieżne z
docelową dyspozycją przywrócenia aktywnego superadministratora. Hash hasła, e-mail,
ID źródłowe i dane finansowe nie zostały zapisane w raporcie ani zmienione.

## Portfel administratora

| Pole | Wartość źródłowa |
|---|---:|
| saldo główne dostępne | 0 minor |
| saldo główne zarezerwowane | 0 minor |
| saldo Słowo dostępne | 0 minor |
| saldo Słowo zarezerwowane | 0 minor |
| punkty | 129362 |
| waluta | PLN |
| liczba transakcji | 41 |
| blokada portfela | nie |

Źródło ma globalny łańcuch HMAC: 90 zachowanych transakcji, głowę wskazującą
transakcję `id=123`, bez tabel głów per-portfel i bez anchorów Merkle. Wśród 41
transakcji administratora 7 wskazuje poprzedni hash transakcji innego portfela, a 8
transakcji innych użytkowników zależy od wpisu administratora. Selektywne skopiowanie
istniejących hashy nie może więc dać matematycznie kompletnego łańcucha.

Zgodnie z dyspozycją importer finansów pozostawał zablokowany do decyzji o jawnym,
audytowanym otwarciu nowego łańcucha per-portfel na podstawie zatwierdzonego salda
źródłowego. Na etapie raportu nie utworzono salda ani transakcji wyrównawczej.

## Treści źródłowe

| Zakres | Liczba |
|---|---:|
| artykuły | 11 |
| artykuły opublikowane | 9 |
| artykuły obecnie przypisane administratorowi | 0 |
| artykuły przypisane innemu autorowi | 11 |
| różni autorzy źródłowi artykułów | 1 |
| tłumaczenia artykułów | 31 |
| rewizje artykułów | 37 |
| rewizje tłumaczeń | 37 |
| kategorie | 6 |
| relacje artykuł–kategoria | 20 |
| media | 5 |
| bannery główne | 1 |
| tłumaczenia bannerów | 6 |
| ankiety | 1 |
| kampanie | 0 |

Wszystkie 11 artykułów musi po imporcie otrzymać `author_id=4`. Pierwotne ID autora
zostanie zachowane wyłącznie w śladzie migracyjnym, bez tworzenia jego aktywnego
konta.

## Stan celu przed importem

PostgreSQL zawierał schemat, migracje oraz dwa rekordy instalacyjne, ale nie zawierał
treści:

| Zakres | Liczba |
|---|---:|
| użytkownicy | 2 |
| artykuły / tłumaczenia / kategorie / media | 0 / 0 / 0 / 0 |
| portfele | 2 |
| transakcje | 0 |

Rekordy instalacyjne to lokalny administrator Docker i konto platformowe. Ich los
musiał zostać jawnie rozstrzygnięty przez selektywny importer; nie wykonano resetu
bazy ani cichego merge'u.

## Wnioski do kolejnych etapów

1. Brak treści wynika z uruchomienia świeżego PostgreSQL bez importu danych MySQL.
2. Konto wskazanej osoby jest jednoznaczne, ale źródłowo usunięte i ma tylko rolę
   autora; aktywacja oraz nadanie ról są kontrolowaną transformacją.
3. Treści można przenieść bez importu pozostałych dziewięciu użytkowników.
4. Finansów nie wolno zastosować przez proste kopiowanie podzbioru globalnej księgi.
5. Laragon, źródłowa baza MySQL, hashe haseł i `FINANCE_HMAC_KEY` pozostają bez zmian.
