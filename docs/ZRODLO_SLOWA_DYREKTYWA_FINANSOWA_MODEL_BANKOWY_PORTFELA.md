# ŹRÓDŁO SŁOWA — DYREKTYWA FINANSOWA

## Pełna implementacja bankowego modelu bezpieczeństwa portfela

**Status dokumentu:** dyrektywa wdrożeniowa  
**Zakres:** portfele, transakcje, wypłaty, przelewy, bonusy, prowizje, korekty finansowe  
**Decyzja projektowa:** wdrożyć teraz jako pełny model docelowy, bez odkładania na późniejsze etapy  

---

# 1. Założenie główne

System ŹRÓDŁO SŁOWA jest w fazie rozwojowej, dlatego zabezpieczenia finansowe należy wdrożyć teraz, jako pełną architekturę finansową systemu.

Nie odkładamy modelu bankowego na później.
Nie traktujemy portfela jako zwykłej tabeli z saldem.
Nie pozwalamy, aby kontrolery lub moduły aplikacji samodzielnie zmieniały pieniądze.

Portfel użytkownika jest księgą finansową.
Każda zmiana salda musi wynikać z podpisanej transakcji w ledgerze.
Każda ręczna operacja finansowa musi zostać zatwierdzona przez dwie osoby: Administratora i Wydawcę.

---

# 2. Zakres obowiązkowy

Dyrektywa obejmuje wszystkie elementy finansowe systemu, w szczególności:

- `wallets`,
- `wallet_transactions`,
- `payouts`,
- przelewy między portfelami,
- portfel główny użytkownika,
- portfel `slowo`,
- portfel Talent, jeżeli występuje w danej wersji systemu,
- bonusy systemowe,
- prowizje systemowe,
- ręczne korekty salda,
- ręczne wypłaty,
- zwroty,
- operacje administracyjne dotyczące pieniędzy,
- przyszłe operacje finansowe powiązane z reklamami, publikacjami, ankietami, nagrodami lub aktywnością użytkowników.

---

# 3. Zasada podstawowa

W systemie ŹRÓDŁO SŁOWA pieniądze nie mogą być zmieniane bez śladu.

Zabronione są:

- ręczna zmiana salda bez transakcji,
- bezpośrednie `UPDATE wallets SET balance = ...` poza centralnym serwisem finansowym,
- usuwanie wpisów z `wallet_transactions`,
- edycja historycznych transakcji,
- cofanie historii przez nadpisanie danych,
- wykonywanie ręcznych wypłat bez zatwierdzenia,
- zatwierdzanie własnych operacji finansowych,
- tworzenie transakcji bez podpisu HMAC,
- omijanie audytu finansowego.

Dozwolone są:

- dopisanie nowej transakcji,
- dopisanie transakcji korygującej,
- odrzucenie zlecenia finansowego,
- anulowanie zlecenia przed wykonaniem,
- zablokowanie portfela do wyjaśnienia,
- wykrycie naruszenia integralności ledgera,
- wykonanie operacji automatycznej przez system, ale wyłącznie przez centralny serwis finansowy i podpisany ledger.

---

# 4. Ledger podpisywany cyfrowo — Chain of Trust

Każdy wpis w tabeli `wallet_transactions` musi być elementem łańcucha zaufania.

Każda transakcja musi mieć:

- `previous_hash`,
- `entry_hash`,
- `hash_algorithm`,
- `hash_version`,
- `signed_at`.

`entry_hash` musi być liczony jako HMAC z danych transakcji oraz `previous_hash` poprzedniej transakcji.

Minimalny zakres danych objętych hashem:

- `transaction_id`,
- `wallet_id`,
- `user_id`,
- `type`,
- `amount`,
- `currency`,
- `balance_before`,
- `balance_after`,
- `source`,
- `created_at`,
- `previous_hash`.

Klucz HMAC nie może być przechowywany w bazie danych.
Powinien być przechowywany w konfiguracji środowiskowej, np. w `.env`:

```env
WALLET_LEDGER_HMAC_SECRET=tu_wartosc_tajnego_klucza
```

Zmiana jakiejkolwiek historycznej transakcji musi powodować wykrywalne przerwanie łańcucha integralności.

Przykładowa zasada logiczna:

```text
previous_hash + dane_transakcji + tajny_klucz_systemowy = entry_hash
```

Jeżeli ktoś ręcznie zmieni kwotę, użytkownika, saldo lub źródło transakcji, system musi wykryć, że hash przestał się zgadzać.

---

# 5. Zakaz ręcznej zmiany salda

Saldo portfela nie może być zmieniane bez utworzenia wpisu w `wallet_transactions`.

Zakazane są bezpośrednie operacje typu:

```sql
UPDATE wallets SET balance = balance + 100 WHERE id = 1;
```

poza centralnym serwisem finansowym.

Każda zmiana salda musi przechodzić przez jeden serwis:

```text
FinancialService / WalletLedgerService
```

Kontrolery, panele, moduły, akcje administratora, akcje wydawcy i automaty systemowe nie mogą samodzielnie modyfikować sald.

Zasada obowiązkowa:

```text
Nie ma transakcji w ledgerze = nie ma zmiany salda.
```

---

# 6. Maker-Checker — Administrator + Wydawca

Wszystkie ręczne operacje finansowe wymagają zatwierdzenia przez dwie osoby:

- Administrator,
- Wydawca.

To jest docelowy model czterech oczu dla ŹRÓDŁA SŁOWA.
Nie tworzymy rozbudowanej struktury korporacyjnej.
Nie mnożymy sztucznych ról.
Wystarczy twardy układ:

```text
Admin + Wydawca
```

Zasada działania:

```text
Osoba A tworzy zlecenie finansowe.
Osoba B zatwierdza zlecenie finansowe.
Ta sama osoba nie może zatwierdzić własnego zlecenia.
Dopiero po zatwierdzeniu powstaje właściwa transakcja w wallet_transactions.
```

Jeżeli operację tworzy Administrator, zatwierdza ją Wydawca.
Jeżeli operację tworzy Wydawca, zatwierdza ją Administrator.

---

# 7. Operacje wymagające zatwierdzenia

Zatwierdzenia przez dwie osoby wymagają obowiązkowo:

- ręczne wypłaty,
- ręczne korekty salda,
- ręczne dodanie środków,
- ręczne odjęcie środków,
- zwroty,
- cofanie wypłat,
- operacje techniczne na portfelu,
- ręczne operacje administracyjne dotyczące pieniędzy,
- większe operacje finansowe wskazane przez konfigurację limitów.

Operacja ręczna nie może tworzyć od razu wpisu w `wallet_transactions`.
Najpierw musi powstać zlecenie w tabeli zatwierdzeń.

---

# 8. Tabela `financial_approvals`

Należy utworzyć tabelę `financial_approvals` dla zleceń wymagających zatwierdzenia.

Minimalne pola:

- `id`,
- `operation_type`,
- `operation_payload`,
- `amount`,
- `currency`,
- `wallet_id`,
- `user_id`,
- `requested_by`,
- `requested_role`,
- `approved_by`,
- `approved_role`,
- `status`,
- `reason`,
- `reject_reason`,
- `created_at`,
- `approved_at`,
- `rejected_at`,
- `executed_at`.

Statusy:

- `pending`,
- `approved`,
- `rejected`,
- `cancelled`,
- `executed`,
- `failed`.

Zatwierdzenie jest ważne tylko wtedy, gdy:

```text
requested_by != approved_by
```

oraz gdy jedna strona ma rolę:

```text
admin
```

następnie druga strona ma rolę:

```text
publisher / wydawca
```

---

# 9. Wykonanie zatwierdzonej operacji

Po zatwierdzeniu zlecenia przez drugą wymaganą rolę system musi:

1. sprawdzić, czy zatwierdzający nie jest twórcą zlecenia,
2. sprawdzić role: Administrator + Wydawca,
3. sprawdzić saldo,
4. sprawdzić limity,
5. przygotować wpis w `wallet_transactions`,
6. wyliczyć `previous_hash`,
7. wyliczyć `entry_hash`,
8. zapisać transakcję,
9. zaktualizować saldo,
10. zapisać audyt,
11. oznaczyć approval jako `executed`.

Jeżeli którykolwiek warunek nie zostanie spełniony, operacja nie może zostać wykonana.

---

# 10. Hard-Limit Protection

Na poziomie bazy danych należy wprowadzić bezwzględny zakaz salda ujemnego dla portfeli użytkownika typu:

- `main`,
- `slowo`.

Zabezpieczenie ma działać niezależnie od kodu aplikacji.

Należy zastosować constrainty i/lub triggery bazy danych.

Jeżeli operacja miałaby spowodować saldo ujemne, baza ma ją odrzucić.

Zasada:

```text
Portfel użytkownika main/slowo nie może zejść poniżej zera.
```

To zabezpieczenie ma chronić system również wtedy, gdy w aplikacji pojawi się błąd.

---

# 11. Nieusuwalność historii

Wpisów w `wallet_transactions` nie wolno usuwać ani edytować.

Korekty wykonuje się wyłącznie przez nowe transakcje korygujące.

Zakazane jest edytowanie pól historycznych, w szczególności:

- `amount`,
- `balance_before`,
- `balance_after`,
- `wallet_id`,
- `user_id`,
- `previous_hash`,
- `entry_hash`,
- `created_at`,
- `source`,
- `type`.

Nie wolno usuwać transakcji.
Nie wolno poprawiać historii ręcznie.
Nie wolno nadpisywać stanu finansowego.

Jeżeli wystąpił błąd, należy utworzyć nową transakcję korygującą.

---

# 12. Audyt finansowy

Każda operacja finansowa musi być zapisana w audycie.

Audyt musi wskazywać:

- kto wykonał operację,
- z jakiej roli,
- kiedy,
- z jakiego modułu,
- czego dotyczyła,
- jaki był powód,
- jaki był stan przed,
- jaki był stan po,
- czy operacja była automatyczna czy ręczna,
- czy wymagała zatwierdzenia,
- kto ją zatwierdził,
- kiedy została zatwierdzona,
- czy została wykonana,
- czy została odrzucona,
- czy zakończyła się błędem.

Należy utworzyć lub rozbudować tabelę:

```text
financial_audit_log
```

Audyt finansowy nie zastępuje ledgera.
Ledger jest księgą transakcji.
Audyt jest opisem czynności i odpowiedzialności.

---

# 13. Blokada portfela przy naruszeniu

Jeżeli system wykryje naruszenie integralności finansowej, portfel musi zostać oznaczony jako zablokowany do wyjaśnienia.

Dotyczy to sytuacji takich jak:

- przerwany hash chain,
- niezgodność `balance_before` / `balance_after`,
- próba zejścia poniżej zera,
- próba wykonania ręcznej operacji bez zatwierdzenia,
- próba zatwierdzenia własnego zlecenia,
- edycja historycznej transakcji,
- brak zgodności HMAC,
- próba pominięcia centralnego serwisu finansowego.

Do tabeli `wallets` należy dodać pola:

- `is_locked`,
- `locked_reason`,
- `locked_at`,
- `locked_by`.

Zablokowany portfel nie może wykonywać nowych operacji finansowych do czasu wyjaśnienia.

---

# 14. Osobna baza finansowa

Tabele finansowe mają być przeniesione do osobnej bazy danych albo osobnej logicznej konfiguracji połączenia.

Docelowa baza przykładowa:

```text
zrodlo_slowa_finance
```

Zakres bazy finansowej:

- `wallets`,
- `wallet_transactions`,
- `payouts`,
- `financial_approvals`,
- `financial_audit_log`.

Dostęp do tej bazy ma mieć wyłącznie centralny serwis finansowy.

Pozostała aplikacja nie może bezpośrednio wykonywać operacji finansowych poza tym serwisem.

---

# 15. Centralny serwis finansowy

Należy utworzyć centralny serwis finansowy odpowiedzialny za całą logikę pieniędzy.

Nazwa robocza:

```text
FinancialService / WalletLedgerService
```

Serwis odpowiada za:

- księgowanie transakcji,
- podpisywanie HMAC,
- sprawdzanie `previous_hash`,
- aktualizację sald,
- blokowanie salda ujemnego,
- obsługę zatwierdzeń Admin + Wydawca,
- wykonywanie wypłat,
- obsługę prowizji,
- obsługę bonusów,
- obsługę przelewów między portfelami,
- audyt,
- blokowanie portfela przy naruszeniu,
- weryfikację integralności ledgera.

Żaden kontroler nie może samodzielnie zmieniać salda.

---

# 16. Panel Administratora i Wydawcy

W panelu Administratora i Wydawcy należy dodać sekcję:

```text
Operacje finansowe do zatwierdzenia
```

Administrator widzi zlecenia wymagające jego zatwierdzenia.
Wydawca widzi zlecenia wymagające jego zatwierdzenia.

Przy zleceniu mają być widoczne:

- użytkownik,
- portfel,
- typ operacji,
- kwota,
- waluta,
- kto utworzył,
- kiedy utworzył,
- powód,
- status,
- przyciski: `Zatwierdź` / `Odrzuć`.

Jeżeli użytkownik próbuje zatwierdzić własne zlecenie, system ma odmówić.

Komunikat przykładowy:

```text
Nie możesz zatwierdzić własnej operacji finansowej. Wymagane jest zatwierdzenie przez drugą rolę: Administrator + Wydawca.
```

---

# 17. Zasada wykonania transakcji ręcznej

Operacja ręczna nie tworzy od razu wpisu w `wallet_transactions`.

Najpierw powstaje wpis w `financial_approvals` ze statusem:

```text
pending
```

Dopiero po zatwierdzeniu przez drugą wymaganą rolę system:

- waliduje operację,
- sprawdza saldo,
- sprawdza role,
- tworzy wpis `wallet_transactions`,
- podpisuje wpis HMAC,
- aktualizuje saldo,
- zapisuje audyt,
- oznacza approval jako `executed`.

---

# 18. Operacje automatyczne

Operacje automatyczne systemu mogą być wykonywane bez ręcznego zatwierdzania, jeżeli są standardową częścią działania systemu.

Dotyczy to m.in.:

- bonusów za aktywność,
- prowizji systemowych,
- naliczeń punktowych,
- automatycznych rozliczeń systemowych,
- standardowych operacji wynikających z działania aplikacji.

Ale każda operacja automatyczna nadal musi:

- przechodzić przez `FinancialService`,
- tworzyć wpis w `wallet_transactions`,
- mieć `entry_hash`,
- mieć `previous_hash`,
- mieć audyt,
- nie łamać hard-limitów,
- nie omijać ledgera.

Zasada:

```text
Automat może księgować, ale tylko przez podpisany ledger.
```

---

# 19. Konfiguracja limitów finansowych

Należy przygotować plik konfiguracyjny limitów finansowych, np.:

```text
finance_limits.json
```

Konfiguracja powinna określać:

- od jakiej kwoty operacja automatyczna wymaga zatwierdzenia,
- jakie typy operacji zawsze wymagają zatwierdzenia,
- jakie portfele nie mogą zejść poniżej zera,
- jakie role mogą tworzyć zlecenia,
- jakie role mogą zatwierdzać zlecenia,
- jakie operacje są wyłącznie automatyczne,
- jakie operacje są zawsze ręczne i zatwierdzane.

Przykładowe typy operacji zawsze wymagające zatwierdzenia:

- `manual_credit`,
- `manual_debit`,
- `manual_payout`,
- `balance_correction`,
- `refund`,
- `payout_reversal`.

---

# 20. Weryfikacja integralności ledgera

Należy przygotować mechanizm sprawdzania integralności ledgera.

Nazwa przykładowej komendy:

```text
php finance:verify-ledger
```

Mechanizm powinien sprawdzać:

- ciągłość `previous_hash`,
- zgodność `entry_hash`,
- zgodność `balance_before` i `balance_after`,
- brak sald ujemnych dla chronionych portfeli,
- brak brakujących transakcji,
- brak zmienionych transakcji historycznych,
- zgodność sum transakcji z aktualnym saldem portfela.

Po wykryciu naruszenia system powinien:

1. zapisać incydent,
2. zablokować portfel,
3. uniemożliwić dalsze operacje,
4. pokazać informację w panelu administracyjnym.

---

# 21. Testy obowiązkowe

Należy przygotować testy lub scenariusze testowe dla następujących przypadków:

- próba ręcznej zmiany salda bez transakcji,
- poprawne księgowanie transakcji,
- poprawne wyliczanie HMAC,
- wykrycie zmiany historycznej kwoty,
- wykrycie przerwania `previous_hash`,
- próba zejścia poniżej zera,
- utworzenie zlecenia przez Administratora,
- zatwierdzenie zlecenia przez Wydawcę,
- utworzenie zlecenia przez Wydawcę,
- zatwierdzenie zlecenia przez Administratora,
- próba zatwierdzenia własnego zlecenia,
- odrzucenie zlecenia,
- wykonanie transakcji po zatwierdzeniu,
- blokada portfela po wykryciu naruszenia,
- wykonanie automatycznego bonusu przez ledger,
- wykonanie prowizji systemowej przez ledger,
- odmowa wykonania operacji z ujemnym saldem,
- zapis audytu dla każdej operacji.

---

# 22. Minimalny kontrakt implementacyjny

Implementacja ma spełniać następujący kontrakt:

```text
1. Każda zmiana salda ma transakcję.
2. Każda transakcja ma HMAC.
3. Każda transakcja zna previous_hash.
4. Historia nie jest edytowana ani usuwana.
5. Korekty są nowymi transakcjami.
6. Ręczne operacje wymagają Admin + Wydawca.
7. Ta sama osoba nie zatwierdza własnej operacji.
8. Portfel main/slowo nie schodzi poniżej zera.
9. Każda operacja ma audyt.
10. Naruszenie integralności blokuje portfel.
11. Kontrolery nie zmieniają sald bezpośrednio.
12. Całość przechodzi przez centralny serwis finansowy.
```

---

# 23. Zasada końcowa

Nie wdrażać półśrodków.

To ma być pełna implementacja bankowego modelu bezpieczeństwa portfela w fazie rozwojowej systemu.

Model obowiązkowy:

```text
Pieniądze ręcznie rusza jedna osoba.
Druga osoba to zatwierdza.
Historia jest podpisana.
Saldo nie może zejść poniżej zera.
Nic nie znika z ledgera.
Każda operacja zostawia ślad.
```

Dla ŹRÓDŁA SŁOWA wystarczy prosty, mocny i czytelny układ:

```text
Administrator + Wydawca
```

To jest docelowy model bezpieczeństwa finansowego systemu.

