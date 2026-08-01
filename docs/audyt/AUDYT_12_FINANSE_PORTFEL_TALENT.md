# AUDYT 12 — Finanse, Portfel i Talent

## 1. Struktura Portfela
System portfela w ŹRÓDLE SŁOWA jest podzielony na trzy główne obszary logiczne (sub-konta) w ramach tabeli `wallets`:
- **Konto Główne (`main_available_minor`)**: Środki wpłacone przez użytkownika (np. przez Stripe), przeznaczone na zakupy artykułów premium.
- **Konto Zarobkowe (`slowo_available_minor`)**: Środki zarobione przez autora ze sprzedaży tekstów, darowizn oraz bonusów wymienionych na PLN.
- **Punkty Talent (`points_balance`)**: Kapitał społeczny użytkownika, zdobywany za aktywność. Możliwy do wymiany na PLN według zmiennego kursu.

## 2. Mechanizm monetyzacji (70/30)
Przy zakupie artykułu premium (`ArticleEconomyService`):
1. Od kupującego pobierana jest kwota z Konta Głównego.
2. 70% kwoty trafia na Konto Zarobkowe autora.
3. 30% kwoty trafia do Serwisu (rejestrowane w `platform_revenues`).
4. Całość operacji odbywa się w ramach jednej transakcji bazy danych.

## 3. Punkty Talent i Bonusy
System nagradza użytkowników za aktywność (`TalentService`):
- **Bonusy stałe**: Rejestracja, logowanie, dzienna wizyta.
- **Bonusy za treść**: Przeczytanie artykułu, napisanie komentarza.
- **Bonusy reklamowe**: Obejrzenie reklamy, udział w ankiecie, PPV.
Punkty Talent mogą być wymieniane na PLN (`WalletTransferService`). Proces ten podlega kontroli limitów dziennych i może wymagać akceptacji administratora.

## 4. Wypłaty
Autorzy mogą zlecać wypłatę środków z Konta Zarobkowego:
1. Środki są rezerwowane (`reserved_minor`).
2. Wniosek trafia do Księgowej (`accountant`).
3. Po zatwierdzeniu i wykonaniu przelewu, środki są odejmowane z rezerwacji i oznaczane jako wypłacone.

## 5. Ryzyka i walidacja
- **Ujemne saldo**: System blokuje operacje prowadzące do ujemnego salda, chyba że operacja jest oznaczona jako korekta administracyjna.
- **Waluta**: Wszystkie operacje finansowe w bazie danych są trzymane w jednostkach mniejszych (`minor units`, np. grosze) jako liczby całkowite, co eliminuje problemy z zaokrąglaniem typowe dla liczb zmiennoprzecinkowych.
- **Idempotencja**: Każde doładowanie ze Stripe posiada klucz powiązany z ID sesji, co zapobiega wielokrotnemu księgowaniu tej samej wpłaty.

## 6. Tabela operacji finansowych
| Operacja | Źródło | Cel | Prowizja | Walidacja |
| --- | --- | --- | --- | --- |
| Doładowanie | Stripe | Konto Główne | 0%* | Podpis Stripe |
| Zakup tekstu | Konto Główne (Kupujący) | Konto Zarobkowe (Autor) | 30% | Stan konta |
| Bonus aktywności | System | Punkty Talent | 0% | Limity dzienne |
| Transfer TT -> PLN | Punkty Talent | Konto Zarobkowe | Zależna | Zgoda admina |
| Wypłata | Konto Zarobkowe | Konto Bankowe | 0% | Minimalna kwota |

\* Prowizja operatora Stripe jest kosztem zewnętrznym i nie jest uwzględniana w saldzie portfela użytkownika.
