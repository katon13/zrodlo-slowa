# Finanse i idempotencja

## Niezmienne zasady

PostgreSQL jest jedynym źródłem prawdy dla salda, historii, wypłat, płatności,
naliczeń i stanu kolejek finansowych. Valkey nie przechowuje salda rozstrzygającego
ani finalnego statusu transakcji. Każdy skutek finansowy powstaje w transakcji bazy,
ma stabilny klucz idempotencji i zapis księgowy możliwy do ponownej weryfikacji.

Jedno zdarzenie biznesowe może dać najwyżej jedno naliczenie. Dotyczy to podwójnego
kliknięcia, ponowienia HTTP, ponownej dostawy webhooka, retry workera i równoległej
pracy wielu workerów.

## Warstwy ochrony

1. Kontroler lub serwis wyprowadza klucz ze stabilnej tożsamości zdarzenia, np.
   użytkownika, aktywności, ankiety, zamówienia lub identyfikatora dostawcy.
2. Trwała kolejka ma unikalność `(queue_name, idempotency_key)` i odrzuca użycie
   tego samego klucza dla innego payloadu.
3. Worker pobiera rekord z leasingiem i blokadą `FOR UPDATE SKIP LOCKED`.
4. Operacja domenowa blokuje tylko portfele, których dotyczy zapis, w deterministycznej
   kolejności. Nie istnieje globalny lock wszystkich transakcji.
5. Wpis księgi ma własny klucz idempotencji i ograniczenie unikalne.
6. Saldo, wpis księgi oraz finalny stan zadania są zatwierdzane atomowo albo ich skutek
   jest rozpoznawany po ponowieniu.

Fingerprint payloadu chroni przed cichym użyciem tego samego klucza dla innych
danych. Klucze nie mogą zależeć od czasu wykonania ani losowego ID nowej próby.

## Zachowanie przy awarii

| Moment awarii | Stan po awarii | Bezpieczne wznowienie |
|---|---|---|
| przed rozpoczęciem transakcji | brak skutku | zadanie może zostać pobrane ponownie |
| przed `COMMIT` | rollback: brak salda i wpisu | retry wykonuje operację raz |
| po `COMMIT`, przed potwierdzeniem workera | skutek już istnieje, lease może wygasnąć | retry odnajduje idempotentny wynik i nie księguje drugi raz |
| po błędzie przejściowym | zadanie `retry` z backoffem | kolejna próba zachowuje ten sam klucz |
| po wyczerpaniu prób | stan terminalny/DLQ | ręczne wznowienie wymaga audytu i tego samego klucza |

Zewnętrzne płatności używają również identyfikatora/idempotency key dostawcy, ale
lokalna unikalność PostgreSQL pozostaje obowiązkowa. Webhook jest traktowany jako
powtarzalny komunikat, nie jako dowód, że otrzymano go tylko raz.

## Księga per portfel

Aktywny model prowadzi osobny łańcuch HMAC dla każdego portfela. Transakcja zawiera
hash poprzedniego wpisu danego portfela i kanoniczny podpis aktualnego wpisu.
Tabela głów portfeli pozwala serializować tylko operacje dotyczące tego samego
portfela, dzięki czemu niezależni użytkownicy nie blokują globalnej kolejki.

Scheduler tworzy okresowy Merkle root nad głowami portfeli. Anchor zawiera zakres
czasu, cutoff transakcji, manifest, liczbę portfeli i transakcji, poprzedni hash
anchora oraz podpis. Tworzenie anchora nie znajduje się w gorącej ścieżce HTTP.

Archiwalny globalny łańcuch pozostaje zachowany wyłącznie do audytu. Przejście na
model per-portfel zostało wykonane przez kontrolowaną migrację z porównaniem sald i
historii. Starego mechanizmu ani danych nie wolno usuwać bez ponownego raportu
zgodności wszystkich sald, liczności transakcji, historii, hashy i anchorów oraz
zatwierdzonego planu retencji.

## Kontrole operacyjne

Kontrola tylko do odczytu:

```powershell
docker compose exec -T app-1 php scripts/verify_ledger.php
docker compose exec -T app-1 php scripts/reconcile_finances.php
```

Utworzenie godzinnego anchora (idempotentne dla okresu):

```powershell
docker compose exec -T app-1 php scripts/create_ledger_anchor.php
```

Kontrolowana migracja starego łańcucha:

```powershell
docker compose exec -T app-1 php scripts/migrate_ledger_to_wallet_chains.php
```

Migrację wykonuje się tylko po backupie i próbie odtworzenia. Po migracji obowiązkowe
są oba odczytowe polecenia kontrolne. Skrypt `verify_ledger.php` musi zakończyć się
wynikiem poprawnym przed publikacją, po odtworzeniu backupu i po incydencie
dotyczącym danych finansowych.

## Uzgodnienia i audyt

Interfejs źródła uzgodnień pozwala później porównać lokalne salda i transakcje z
raportem operatora płatności lub systemu wypłat bez przebudowy domeny. Każda
administracyjna korekta, płatność, wypłata, naliczenie i wynik uzgodnienia generuje
ustrukturyzowane zdarzenie audytowe z aktorem, użytkownikiem, operacją, request ID,
IP i wynikiem. Korekta nie modyfikuje historii w miejscu; tworzy jawny wpis
kompensacyjny.

## Testy wymagane przed wydaniem

- równoległe naliczenie tego samego zdarzenia przez dwa workery;
- podwójny klik i ponowienie HTTP;
- awaria przed i po `COMMIT`;
- wygaśnięcie lease i odzyskanie zadania;
- dokładnie jedna transakcja oraz oczekiwane saldo i historia;
- zgodność księgi per-portfel, głów i Merkle root;
- rollback całej operacji przy błędzie pośrednim;
- odtworzenie backupu i ponowna weryfikacja.

Scenariusze te realizują testy integracyjne oraz skrypty ETAPÓW 8 i 9. Szczegóły:
[test dwóch instancji](TEST_DWOCH_INSTANCJI.md) i
[testy obciążeniowe](TESTY_OBCIAZENIOWE.md).
