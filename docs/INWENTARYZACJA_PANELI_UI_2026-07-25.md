# Inwentaryzacja i modernizacja paneli UI

Data kontroli końcowej: 25.07.2026  
Zakres: `X:\zrodlo-slowa`  
Wzorzec wizualny: panel **Portfel**

## 1. Wynik

Modernizacja wizualna i funkcjonalna została zakończona.

Sprawdzono łącznie 34 ekrany:

- 24 ekrany administratora i zespołu redakcyjnego;
- 10 ekranów użytkownika, czytelnika, autora i portfela.

Wynik końcowego audytu przeglądarkowego:

| Kontrola | Wynik |
|---|---:|
| Sprawdzone ekrany | 34 |
| Poprawne odpowiedzi | 34 |
| Błędy krytyczne PHP | 0 |
| Błędy JavaScript strony | 0 |
| Nieoczekiwane przekierowania do logowania | 0 |
| Poziome przepełnienia przy 390 px | 0 |
| Formularze administratora bez trasy POST | 0 z 38 |

Nieistniejący raport kampanii zwraca teraz kontrolowane HTTP 404 zamiast błędu krytycznego z kodem 200.
Tak samo naprawiono raport ankiety bez poprawnego identyfikatora.

## 2. Najważniejsze zmiany

### Wspólny wygląd

- poszerzono główną treść i dopasowano ją do szerokości menu;
- wprowadzono wspólny system nagłówków, kart, sekcji, pól, przycisków, tabel, komunikatów i pustych stanów;
- ujednolicono typografię, czerwone akcenty, obramowania i odstępy;
- poprawiono wszystkie wykryte przepełnienia mobilne;
- tabele mają własne przewijanie na małych ekranach i nie rozszerzają całej strony;
- formularze autora, moderatora, ustawień oraz bezpieczeństwa korzystają z tego samego języka wizualnego co Portfel.

### Panel moderatora

- rozdzielono zmianę statusu od edycji danych artykułu;
- przycisk **ZATWIERDŹ STATUS** zmienia wyłącznie status;
- przycisk **AKTUALIZUJ DANE** zapisuje cenę, dostęp, udział autora, premium, unikalność, notatkę i etykietę bez zmiany statusu;
- przycisk **GENERUJ TŁUMACZENIA** ma osobną, czytelną akcję;
- naprawiono podgląd wybranej etykiety oraz komunikat po zapisie;
- usunięto błąd związany z niezdefiniowaną listą etykiet;
- dodano zapis etykiety do dziennika zdarzeń artykułu.
- lista statusów pokazuje teraz wyłącznie przejścia dozwolone dla bieżącego stanu tekstu;
- usunięto powtarzające się identyfikatory HTML przy autorze mającym kilka artykułów.

### Konta, role i usuwanie

- zwykła zmiana typu konta nie kasuje już ról moderatora, redaktora, wydawcy, korektora ani księgowego;
- role redakcyjne są rozpoznawane dokładnie, a nie przez częściowe dopasowanie tekstu;
- status `deleted` usunięto ze zwykłej listy statusów — usuwanie prowadzi wyłącznie przez raport i bezpieczną anonimizację;
- administrator nie może przypadkowo zablokować własnego konta ani odebrać sobie roli administratora;
- nieistniejące i zanonimizowane konta są odrzucane przy zmianie ról.

### Ankiety, kampanie i kategorie

- raport nieistniejącej ankiety zwraca kontrolowane HTTP 404;
- ankieta bez pytań nie może zostać aktywowana;
- pytania można usuwać tylko z właściwej ankiety, a ostatniego pytania nie można usunąć z aktywnej ankiety;
- zweryfikowano kolejność dat, kwoty, limit odpowiedzi i budżet ankiety;
- zapis odpowiedzi, nagrody i wpisu ledger jest teraz jedną operacją transakcyjną;
- kampania odrzuca nieprawidłowe kwoty, daty, adresy i aktualizację nieistniejącego rekordu;
- budżet kampanii jest limitem całości, a nie ceną jednego zdarzenia PPV/live;
- kategorie odrzucają pustą nazwę, nieistniejący rekord i nieprawidłowy kod języka tłumaczenia.

### Płatności i ustawienia

- ręczne oznaczanie płatności jako opłaconej jest dostępne tylko dla operatora `manual`;
- Stripe i inne bramki mogą potwierdzać płatność wyłącznie swoim webhookiem;
- zaksięgowanie płatności, zdarzenia i dostępu do tekstu jest atomowe;
- częściowy zapis ustawień AI nie resetuje pozostałych parametrów;
- przywrócono widoczne pole szacowanego kosztu AI na 1000 znaków i opisano jednostki w groszach;
- zapis sekcji Antyfraud nie wyłącza już głównego SNAJPERA, trybu ścisłego ani audytu;
- ustawień AI, płatności i portfela nie można już omijać przez surowy formularz „Pozostałe”;
- reguły Talentu i ustawienia ogólne mają walidację zakresów oraz komunikat powodzenia lub błędu;
- link przycisku i obraz Baneru Głównego odrzucają niebezpieczne schematy, np. `javascript:`.

### Etykiety artykułów

- publiczne zapytania artykułów pobierają teraz `article_label`;
- wybrana etykieta jest widoczna jako wyraźny czerwony badge przy autorze na stronie głównej i liście tekstów;
- etykieta jest również używana przy metadanych pojedynczego artykułu i w panelu czytelnika;
- dodano lokalizację etykiet dla PL, EN, DE, FR, IT i ES;
- sprawdzono pełny przepływ na artykule testowym: zmiana `Hot News → Important`, zapis, odczyt po przeładowaniu i przywrócenie wartości;
- potwierdzono, że aktualizacja etykiety nie zmienia statusu publikacji;
- na stronie głównej etykieta `Hot News` jest poprawnie wyświetlana jako **PILNE**.

### Przebudowane ekrany

- Panel czytelnika;
- Zatwierdzenia finansowe;
- Bezpieczeństwo konta;
- Ustawienia konta;
- Bezpieczne usuwanie użytkownika;
- Panel moderatora;
- mobilny układ panelu wydawcy;
- responsywne tabele i formularze pozostałych paneli.

## 3. Panele administratora i redakcji

| Panel | Trasa | Wynik |
|---|---|---|
| Pulpit | `/admin` | Zgodny |
| Redakcja Główna | `/admin/articles` | Zgodny |
| Wydawca — lista | `/admin/editorial` | Zgodny, poprawiony mobilnie |
| Wydawca — edycja | `/admin/editorial/edit?id=44` | Zgodny |
| Korekta — edycja | `/admin/proofreader/edit?id=44` | Zgodny |
| Ankiety | `/admin/surveys` | Zgodny |
| Raport ankiety | `/admin/surveys/report?id=1` | Zgodny |
| Kampanie | `/admin/campaigns` | Zgodny |
| Raport kampanii | `/admin/campaigns/report?id=1` | Kontrolowany 404 przy braku rekordu |
| Antyfraud | `/admin/anti-fraud` | Zgodny |
| Wypłaty | `/admin/payouts` | Zgodny |
| Użytkownicy | `/admin/users` | Zgodny |
| Role | `/admin/roles` | Zgodny, poprawiony mobilnie |
| Panel moderatora | `/admin/role-panel?panel=moderator` | Zgodny i funkcjonalnie naprawiony |
| Usuwanie użytkownika | `/admin/users/delete?id=…` | Zmodernizowany |
| Baner Główny | `/admin/main-banner` | Zgodny |
| Kategorie | `/admin/categories` | Zgodny, poprawiony mobilnie |
| Ustawienia | `/admin/settings` | Zgodny, długie klucze zawijają się poprawnie |
| Kolejka maili | `/admin/mails` | Zgodny |
| Płatności | `/admin/payments` | Zgodny |
| AI redakcyjne | `/admin/ai` | Zgodny |
| Ledger portfeli | `/admin/ledger` | Zgodny |
| Zatwierdzenia finansowe | `/admin/finance/approvals` | Zmodernizowany |
| Raport finansowy | `/admin/finance` | Zgodny |

## 4. Panele użytkownika

| Panel | Trasa | Wynik |
|---|---|---|
| Panel czytelnika | `/reader` | Zmodernizowany |
| Ustawienia konta | `/account/settings` | Zmodernizowany |
| Bezpieczeństwo konta | `/account/security` | Zmodernizowany |
| Panel autora | `/author` | Zgodny |
| Nowy tekst | `/author/articles/create` | Zgodny |
| Edycja tekstu | `/author/articles/edit?id=…` | Zgodny |
| Portfel | `/wallet` | Wzorcowy |
| Doładowanie | `/wallet/topup` | Zgodny |
| Wynik doładowania — sukces | `/wallet/topup/success` | Zgodny |
| Wynik doładowania — anulowanie | `/wallet/topup/cancel` | Zgodny |

## 5. Weryfikacja techniczna

- PHP lint: wszystkie zmienione pliki bez błędów składni;
- PHPUnit: **49 testów, 209 asercji, 100% zaliczone**;
- PHPStan: **0 błędów**;
- audyt przeglądarkowy 34 ekranów przy 390 px: **0 przepełnień i 0 błędów krytycznych**;
- dodatkowy przegląd wizualny wszystkich 24 ekranów administratora i 10 ekranów użytkownika;
- 38 literalnych akcji formularzy administracyjnych ma zarejestrowane trasy POST.
- wszystkie zadeklarowane trasy wskazują istniejące, publiczne metody kontrolerów;
- 35 administracyjnych punktów POST sprawdzono również na błędnych danych — żaden nie ujawnił ostrzeżenia PHP ani błędu krytycznego;
- interaktywne przyciski wydawcy, zakładki Baneru Głównego i rozwijanie tłumaczeń kategorii sprawdzono w prawdziwej przeglądarce;
- testy integracyjne pracują w transakcjach i wycofują dane testowe.

Dodano testy regresyjne dla:

- zapisu ceny i etykiety bez zmiany statusu artykułu;
- obecności etykiety w publicznej liście artykułów;
- lokalizacji etykiet artykułów;
- obecności etykiety w dzienniku zdarzeń wyceny.
- zachowania ról redakcyjnych przy zmianie typu konta;
- blokady obejścia anonimizacji zwykłą zmianą statusu;
- pełnych przepływów kategorii, ankiety, kampanii i ręcznej płatności;
- atomowego rozliczenia odpowiedzi ankietowej;
- limitu budżetu i poprawnego kosztu zdarzenia PPV;
- częściowych zapisów AI i SNAJPERA bez resetu innych ustawień;
- bezpiecznych adresów Baneru Głównego;
- kompletności tras, formularzy i metod kontrolerów.

Połączeń do prawdziwego OpenAI, Stripe ani serwera pocztowego nie wykonywano podczas audytu. Sprawdzono ich walidację, blokady bezpieczeństwa, konfigurację i obsługę błędów bez wysyłania rzeczywistych płatności, wiadomości ani płatnych zapytań AI.

## 6. Pozostały dług techniczny

W starszych widokach nadal istnieją lokalne bloki CSS i część atrybutów `style`. Wspólna warstwa systemowa ujednolica ich wynik wizualny i naprawia responsywność, dlatego nie powodują obecnie błędów interfejsu. W przyszłości można je mechanicznie przenieść do centralnego arkusza, aby uprościć utrzymanie kodu; nie jest to blokada działania paneli.
