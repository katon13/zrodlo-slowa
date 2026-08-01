# ŹRÓDŁO SŁOWA — pełny update systemu

## Ekonomia tekstu, bonusy aktywności, ankiety/sondaże, reklamy, PPV, usuwanie użytkowników

## 1. Główna definicja systemu

ŹRÓDŁO SŁOWA to nie jest zwykły CMS ani blog.

To wirtualna redakcja i system obiegu wartości, w którym:

```text
autor publikuje teksty,
redakcja ocenia i wycenia treści,
czytelnik płaci za teksty premium / unikalne / rynkowe,
autor dostaje 70% przychodu,
serwis/redakcja dostaje 30%,
zwykły użytkownik może zarabiać za aktywność,
ankiety i sondaże tworzą osobny strategiczny moduł przychodowy,
reklamodawcy/zleceniodawcy płacą za dostęp do społeczności, danych i opinii.
```

Najważniejsze zdanie projektu:

```text
ŹRÓDŁO SŁOWA to system, w którym wartość tekstu, informacji, opinii i aktywności społeczności wraca do ludzi, którzy tę wartość tworzą.
```

---

## 2. Tekst TOP strony głównej

Na samym początku strony głównej:

```text
Masz coś do powiedzenia?
Tekst, historię, myśl albo informację, która ma wartość?

Daj temu własne źródło.
Publikuj tam, gdzie autor nie traci głosu ani zysku.
```

Pod spodem krótki opis:

```text
ŹRÓDŁO SŁOWA to wirtualna redakcja dla autorów, dziennikarzy i ludzi, których teksty, historie, informacje i opinie mają realną wartość.
```

---

## 3. Filar 1 — sprzedaż tekstów

Proces:

```text
1. Autor dodaje tekst.
2. Redakcja ocenia tekst.
3. Redakcja ustala cenę: np. 9,90 zł / 29 zł / 99 zł / cena indywidualna.
4. Tekst dostaje status: DARMOWY / PŁATNY / PREMIUM / UNIKALNY / SPECJALNY.
5. Czytelnik kupuje dostęp.
6. System zapisuje transakcję.
7. 70% przychodu trafia do portfela autora.
8. 30% zostaje dla serwisu/redakcji.
9. Autor może zlecić wypłatę.
```

### Statusy tekstów

```text
DRAFT           — szkic autora
SUBMITTED       — wysłany do redakcji
IN_REVIEW       — w ocenie
ACCEPTED        — zaakceptowany
REJECTED        — odrzucony
PUBLISHED_FREE  — opublikowany darmowo
PUBLISHED_PAID  — opublikowany płatnie
PREMIUM         — tekst premium
UNIQUE          — tekst unikalny / informacja rynkowa
SPECIAL_PRICE   — cena indywidualna
ARCHIVED        — archiwalny
```

### Pola artykułu

W tabeli artykułów powinny być pola:

```text
price
currency
is_paid
is_premium
is_unique
pricing_status
access_type
author_share_percent
platform_share_percent
editor_valuation_note
valued_by_admin_id
valued_at
```

Domyślny podział:

```text
author_share_percent = 70
platform_share_percent = 30
```

---

## 4. Filar 2 — czytelnik jako płacący użytkownik

Czytelnik nie musi być autorem. Może korzystać jako odbiorca treści.

Czytelnik może:

```text
czytać teksty darmowe,
kupować teksty premium,
kupować treści unikalne,
płacić za dostęp czasowy lub stały,
brać udział w ankietach,
oglądać reklamy,
zarabiać za aktywność,
budować własne saldo,
wspierać autorów.
```

---

## 5. Filar 3 — użytkownik zarabia za aktywność

To jest sedno dawnego systemu SŁOWO PISANE i musi zostać przeniesione do nowej aplikacji.

Użytkownik może zarabiać za:

```text
rejestracja,
dzień wizyty,
logowanie,
komentarz,
kliknięcie w link,
łapka w górę,
udostępnienie,
zgłoszenie błędu,
czytanie artykułów reklamowych,
oglądanie reklam,
udział w ankiecie,
otworzenie maila od redakcji.
```

Model reklam obejmuje:

```text
płać za kliknięcie,
reklamy,
transmisje live,
PPV,
ankiety.
```

### Komunikaty live

System ma pokazywać natychmiastowe komunikaty:

```text
Zarobiłeś +0,05 zł za logowanie.
Zarobiłeś +0,10 zł za dzisiejszą wizytę.
Zarobiłeś +0,20 zł za komentarz.
Zarobiłeś +0,30 zł za obejrzenie reklamy.
Zarobiłeś +0,50 zł za udział w ankiecie.
Zarobiłeś +0,10 zł za otworzenie maila redakcji.
```

To ma działać jako:

```text
toast / dymek live,
historia transakcji w portfelu,
panel „Ostatnio zarobiłeś”,
licznik dziennych bonusów,
raport aktywności.
```

---

## 6. Filar 4 — ankiety i sondaże jako klucz przychodowy

Ankiety i sondaże nie są dodatkiem. To strategiczny moduł zarabiania.

Za dane, odpowiedzi i opinie ludzie/organizacje realnie płacą.

Moduł obejmuje:

```text
ankiety konsumenckie,
sondaże wyborcze,
sondaże społeczno-polityczne,
badania lokalne,
badania opinii czytelników,
testy przekazu,
ankiety reklamowe,
badania przed kampanią,
raporty dla zleceniodawców.
```

Model:

```text
zleceniodawca płaci za ankietę / sondaż,
użytkownik dostaje bonus za udział,
system zbiera odpowiedzi,
redakcja może przygotować raport,
serwis zarabia na obsłudze badania i dostępie do społeczności.
```

Hasło modułu:

```text
Twoja opinia też ma wartość.
```

### Tabele ankiet

Proponowane tabele:

```text
surveys
survey_questions
survey_answers
survey_responses
survey_response_items
survey_rewards
survey_reports
```

### surveys

```text
id
title
description
type
client_name
budget
reward_per_user
max_responses
status
starts_at
ends_at
created_by_admin_id
created_at
updated_at
```

Typy:

```text
consumer
political_poll
social_poll
local_poll
advertising
editorial
market_research
```

### survey_responses

```text
id
survey_id
user_id
reward_amount
reward_status
completed_at
created_at
```

Po wypełnieniu ankiety system tworzy:

```text
wallet_transaction: survey_reward
komunikat live: Zarobiłeś za udział w ankiecie.
```

---

## 7. Filar 5 — reklamy, kliknięcia, transmisje, PPV

Moduł reklamowy docelowo obejmuje:

```text
płać za kliknięcie,
reklamy display,
artykuły sponsorowane,
oglądanie reklam,
czytanie artykułów reklamowych,
transmisje live,
PPV,
ankiety reklamowe.
```

Proponowane tabele:

```text
campaigns
campaign_events
ad_views
ad_clicks
sponsored_article_reads
ppv_events
live_events
```

### campaigns

```text
id
client_name
name
type
budget
cost_per_view
cost_per_click
cost_per_completed_survey
reward_for_user
status
starts_at
ends_at
created_at
updated_at
```

---

## 8. Wallet / portfel

Portfel dotyczy:

```text
autora,
czytelnika,
zwykłego użytkownika,
osoby zarabiającej za ankiety,
osoby zarabiającej za reklamy,
osoby zarabiającej za aktywność.
```

Jedna tabela transakcji powinna obsłużyć wszystko.

### wallet_transactions

```text
id
user_id
type
source
source_id
amount
currency
status
description
created_at
```

Typy:

```text
article_sale_author_share
article_sale_platform_share
activity_bonus
survey_reward
ad_view_reward
ad_click_reward
sponsored_article_reward
newsletter_open_reward
ppv_reward
withdrawal_request
withdrawal_paid
withdrawal_rejected
admin_adjustment
```

### Salda

Na start można liczyć saldo z transakcji. Później można dodać tabelę szybkiego salda:

```text
wallets
id
user_id
available_balance
pending_balance
bonus_balance
author_balance
currency
updated_at
```

---

## 9. Usuwanie użytkowników — pełne czyszczenie bez śmieci w bazie

To jest osobny, ważny moduł admina.

Cel:

```text
admin może usunąć użytkownika tak, żeby w bazie nie zostały osierocone śmieci,
system najpierw pokazuje raport zależności,
potem wykonuje kontrolowane czyszczenie,
na końcu pokazuje raport usunięcia.
```

Nie robić zwykłego DELETE bez raportu.

### Problem

Użytkownik może mieć:

```text
konto,
rolę,
sesje,
artykuły,
komentarze,
zakupy,
transakcje portfela,
wypłaty,
ankiety,
odpowiedzi w ankietach,
kliknięcia reklam,
polubienia,
udostępnienia,
zgłoszenia błędów,
maile/newsletter,
logi aktywności.
```

Jeżeli usunie się tylko rekord z `users`, zostaną śmieci i błędy.

### Proponowane podejście

Dodać w panelu admina:

```text
Admin → Użytkownicy → Szczegóły → Usuń użytkownika
```

Po kliknięciu najpierw ekran raportu:

```text
Użytkownik: Jan Kowalski
ID: 123
Artykuły: 5
Komentarze: 18
Zakupy: 3
Transakcje wallet: 42
Wypłaty: 1
Ankiety: 7 odpowiedzi
Kliknięcia reklam: 24
Polubienia: 31
Zgłoszenia błędów: 2
```

Dopiero potem przycisk:

```text
Usuń użytkownika i powiązane dane
```

Wymagane potwierdzenie:

```text
USUŃ UŻYTKOWNIKA 123
```

### Tryby usuwania

Warto zaplanować dwa tryby:

#### Tryb A — miękkie usunięcie / dezaktywacja

```text
user.status = deleted
user.email = deleted_user_123@example.local
user.name = Użytkownik usunięty
login zablokowany
dane osobowe usunięte
historia finansowa zostaje jako księgowa/techniczna
```

Ten tryb jest bezpieczniejszy, gdy są płatności, wypłaty i historia transakcji.

#### Tryb B — twarde usunięcie / pełne czyszczenie

```text
usuń użytkownika,
usuń sesje,
usuń komentarze,
usuń aktywności,
usuń odpowiedzi ankiet,
usuń kliknięcia reklam,
usuń bonusy,
usuń powiązane rekordy,
obsłuż lub usuń artykuły,
usuń powiązane pliki.
```

Twarde usunięcie powinno być dostępne tylko dla admina i najlepiej tylko w środowisku testowym lub dla kont bez istotnej historii finansowej.

### Ważna decyzja projektowa

Przy portfelu i wypłatach nie zawsze wolno usuwać wszystko bez śladu, bo historia finansowa może być potrzebna do rozliczeń.

Dlatego w praktyce najlepszy model:

```text
dane osobowe usuwać/anonymizować,
śmieci techniczne czyścić,
ale historię księgową trzymać jako rekord systemowy bez danych osobowych.
```

Czyli:

```text
użytkownik znika z systemu,
ale transakcja pozostaje jako rekord księgowy przypisany do deleted_user_id / anonimizowanego identyfikatora.
```

### user_delete_report

Dodać raporty usunięcia:

```text
user_delete_reports
id
deleted_user_id
deleted_by_admin_id
mode
summary_json
created_at
```

Raport zapisuje:

```text
co usunięto,
co zanonimizowano,
co zostawiono jako zapis finansowy,
czy wystąpiły błędy.
```

---

## 10. Relacje bazy i zasada braku śmieci

Docelowo baza powinna mieć sensowne relacje:

```text
ON DELETE CASCADE — dla danych technicznych, które mogą zniknąć razem z userem
ON DELETE SET NULL — dla treści, które mogą zostać jako „autor usunięty”
blokada usunięcia — dla rekordów finansowych wymagających rozliczenia
```

Przykłady:

```text
sessions                  → CASCADE
password_resets           → CASCADE
user_activity_reward_logs → CASCADE lub anonimizacja
comments                  → CASCADE albo SET NULL
likes                     → CASCADE
shares                    → CASCADE
survey_responses          → CASCADE albo anonimizacja
wallet_transactions       → NIE kasować bez decyzji; anonimizować
withdrawals               → NIE kasować bez decyzji; anonimizować
articles                  → SET NULL / przypisać do „Autor usunięty” / usunąć zależnie od statusu
```

---

## 11. Panel admina — potrzebne ekrany

### Admin / Artykuły

```text
lista tekstów,
status publikacji,
status wyceny,
cena,
premium/unikalny,
autor,
przycisk wyceny,
przycisk publikacji.
```

### Admin / Użytkownicy

```text
lista użytkowników,
rola,
status,
saldo,
liczba tekstów,
liczba transakcji,
aktywność,
usuń/dezaktywuj użytkownika.
```

### Admin / Ankiety

```text
lista ankiet,
nowa ankieta,
pytania,
budżet,
nagroda dla użytkownika,
status,
wyniki,
raport.
```

### Admin / Kampanie

```text
reklamy,
kliknięcia,
PPV,
live,
artykuły sponsorowane,
budżet,
koszt akcji,
nagroda użytkownika.
```

### Admin / Wallet

```text
transakcje,
wypłaty,
bonusy,
ręczne korekty,
raporty.
```

## Zasady języka i stylu

Nie pisać technicznie na stronie:

```text
CMS
monetyzacja contentu
plugin
dashboard
```

Pisać:

```text
autor
czytelnik
tekst
historia
myśl
informacja
opinia
wartość
źródło
redakcja
portfel autora
ankieta
sondaż
zarobiłeś za...
```

---

## 15. Najważniejsze filary do zapamiętania

```text
1. Tekst ma wartość.
2. Informacja ma wartość.
3. Opinia ma wartość.
4. Aktywność społeczności ma wartość.
5. Autor dostaje 70%.
6. Serwis/redakcja dostaje 30%.
7. Czytelnik może zarabiać za aktywność.
8. Ankiety i sondaże są kluczem przychodowym.
9. System ma pokazywać użytkownikowi, że zarabia.
10. Usuwanie użytkowników musi czyścić bazę bez śmieci i bez niszczenia ważnej historii finansowej.
```
