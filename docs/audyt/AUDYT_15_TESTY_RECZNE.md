# AUDYT 15 — Testy ręczne (Checklista scenariuszowa)

## 1. Scenariusze Publiczne
- [ ] **Strona główna**: Czy Baner Główny wyświetla się poprawnie w języku przeglądarki/domeny?
- [ ] **Zmiana języka**: Czy przełączenie języka w menu zmienia treści interfejsu i artykułów na liście?
- [ ] **Artykuł SEO**: Czy wejście na przyjazny adres (np. `/moj-artykul`) otwiera właściwy tekst?
- [ ] **Responsywność**: Czy layout zachowuje czytelność na telefonie (szczególnie menu i baner)?

## 2. Scenariusze Konta i Autoryzacji
- [ ] **Rejestracja Autora**: Czy po rejestracji użytkownik widzi komunikat o oczekiwaniu na akceptację?
- [ ] **Logowanie 2FA**: Czy przy logowaniu na konto z wysoką rolą (np. admin) system wymaga kodu 2FA?
- [ ] **Avatar**: Czy wgranie zdjęcia profilowego poprawnie aktualizuje miniaturkę w nagłówku?
- [ ] **Zmiana hasła**: Czy procedura "Zapomniałem hasła" generuje poprawny link w logach/mailu?

## 3. Scenariusze Warsztatu Autora
- [ ] **Nowy Artykuł**: Czy autor może zapisać szkic z obrazkiem i wrócić do jego edycji?
- [ ] **Wysłanie do Redakcji (Submit)**: Czy po kliknięciu "Wyślij do redakcji" tekst zostaje zablokowany do edycji dla autora?
- [ ] **Blokada Autora**: Czy administrator może czasowo zablokować autorowi możliwość wysyłania tekstów?

## 4. Scenariusze Redakcyjne
- [ ] **Zatwierdzanie (Chief Editor)**: Czy tekst `submitted` może zostać przesunięty do `approved` lub `rejected`?
- [ ] **Korekta (Proofreader)**: Czy Korektor może zmienić treść, ale ma zablokowaną edycję tytułu i ceny?
- [ ] **Publikacja (Publisher)**: Czy tekst `approved` staje się widoczny publicznie dopiero po zmianie statusu na `published`?
- [ ] **Tłumaczenie AI**: Czy zlecenie tłumaczenia AI tworzy szkic w docelowym języku z poprawną treścią?

## 5. Scenariusze Finansowe
- [ ] **Doładowanie Portfela**: Czy symulacja opłaconej sesji Stripe (test) dodaje środki do Konta Głównego?
- [ ] **Zakup Artykułu**: Czy zakup tekstu premium odejmuje środki kupującemu i dodaje 70% autorowi (Konto Zarobkowe)?
- [ ] **Wypłata**: Czy zlecenie wypłaty rezerwuje środki w portfelu autora?
- [ ] **Bonusy**: Czy przeczytanie artykułu przez zalogowanego użytkownika nalicza punkty Talent?

## 6. Scenariusze Administracyjne
- [ ] **Role**: Czy odebranie roli admina skutkuje natychmiastowym brakiem dostępu do `/admin`?
- [ ] **Ustawienia AI**: Czy zmiana modelu w ustawieniach jest honorowana przy kolejnych zadaniach AI?
- [ ] **Anulowanie użytkownika**: Czy procedura anonimizacji usuwa dane wrażliwe (email, nazwa), zachowując spójność finansową?
