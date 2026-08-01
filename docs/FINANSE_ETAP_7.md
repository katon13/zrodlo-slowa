# Finanse — ETAP 7

ETAP 7 usuwa globalną blokadę księgi z gorącej ścieżki. Każdy portfel ma własny
łańcuch HMAC i własną blokowaną głowę. Niezależne portfele mogą być księgowane
równolegle, a operacje obejmujące kilka portfeli blokują je według rosnącego ID.

## Model danych

- `financial_wallet_ledger_heads` — bieżąca głowa i licznik transakcji portfela;
- `wallet_transactions.wallet_previous_hash` i `wallet_entry_hash` — podpisany
  łańcuch per-portfel;
- `financial_operations` — atomowa idempotencja podwójnego kliknięcia i retry;
- `financial_ledger_migration_state` — tryb, punkt odcięcia i podpisany raport
  zgodności;
- `financial_ledger_anchors` — godzinny manifest głów, Merkle root i HMAC anchora.

Kolumny `previous_hash`, `entry_hash` i tabela `financial_ledger_head` nie są
usuwane. Po przełączeniu są niezmiennym archiwum historii do punktu odcięcia.
Nowe transakcje rozszerzają wyłącznie łańcuch swojego portfela.

## Bezpieczne wdrożenie istniejącej bazy

Użyj tego samego `FINANCE_HMAC_KEY`, którym podpisano dotychczasową historię.
Zmiana klucza zatrzyma migrację.

```bash
php scripts/migrate.php
php scripts/migrate_ledger_to_wallet_chains.php
php scripts/verify_ledger.php
```

Druga komenda bierze wyłączną blokadę stanu migracji. Najpierw weryfikuje całą
starą historię, HMAC i wszystkie salda. Następnie tworzy łańcuchy per-portfel,
sprawdza je ponownie i dopiero przy raporcie bez błędów ustawia `per_wallet`.
Każdy błąd wycofuje całą transakcję i pozostawia `legacy_global`.

Podczas wdrożenia należy wymienić wszystkie instancje aplikacji i workery jako
jedną wersję. Stary proces nie zna stanu ETAPU 7 i nie może pozostać aktywny po
cutoverze.

## Anchory i weryfikacja

Scheduler, o pełnej godzinie UTC, pobiera uporządkowany manifest głów portfeli,
wylicza binarne drzewo Merkle i podpisuje rekord HMAC. Operacja jest idempotentna
dla przedziału godzinnego i nie działa w procesie HTTP.
Ręczną, idempotentną próbę można wykonać przez `scripts/create_ledger_anchor.php`.

`scripts/verify_ledger.php` kontroluje:

- archiwalny globalny łańcuch do zapisanego punktu odcięcia;
- każdą transakcję i głowę per-portfel;
- ciągłość `balance_before` / `balance_after` i arytmetykę zmian;
- wszystkie końcowe salda oraz salda łączne;
- manifesty, Merkle root, HMAC i ciągłość anchorów.

Kod wyjścia `1` blokuje wdrożenie lub operację utrzymaniową.

## Odporność operacyjna

- klucz idempotencji ma fingerprint żądania; ponowne użycie dla innych danych
  jest odrzucane;
- claim idempotencji i transakcja księgowa są w jednej transakcji DB, więc awaria
  przed commit nie zostawia stanu `processing`;
- awaria workera po commit może bezpiecznie ponowić zadanie — otrzyma istniejący
  identyfikator transakcji;
- limity nagród i kontrola duplikatu są wykonywane po blokadzie danego portfela;
- worker AI nadal nie ma udziału w księgowaniu użytkowników.

## Rollback

Przed cutoverem można wycofać kod, ponieważ stary łańcuch pozostaje jedynym
aktywnym modelem. Po cutoverze nie wolno przełączyć flagi ręcznie: transakcje
per-portfel nie rozszerzają archiwalnego łańcucha globalnego. Bezpieczne opcje to
forward-fix na kodzie ETAPU 7 albo odtworzenie backupu sprzed cutoveru i ponowna
weryfikacja. Starych kolumn i `financial_ledger_head` nie usuwać bez osobnej,
zatwierdzonej migracji finansowej.

Docker zachowuje porty `8080`, `5433`, `6380`, `19001` i `8025`. ETAP 7 nie
zmienia Laragona, jego MySQL, sesji, konfiguracji ani portów `80`, `443`, `3306`.
