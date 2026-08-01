# ŹRÓDŁO SŁOWA — WZORZEC STYLU UI V2

## 1. Decyzja główna

Ten dokument jest wzorcem stylu dla całego projektu **ŹRÓDŁO SŁOWA**.

Styl został zatwierdzony na podstawie aktualnych ekranów:

- rejestracja autora,
- panel autora,
- front redakcyjny,
- układ kart, formularzy i komunikatów.

Przy kolejnych zmianach nie projektujemy stylu od nowa. Każdy nowy ekran ma być rozwinięciem tego samego języka wizualnego.

---

## 2. Charakter stylu

ŹRÓDŁO SŁOWA ma wyglądać jak poważny, elegancki serwis redakcyjny, a nie techniczna aplikacja administracyjna.

Kierunek:

- biało-czarne tło,
- czerwony akcent,
- dużo oddechu,
- duże nagłówki,
- mocna typografia,
- cienkie ramki,
- proste karty,
- czytelne formularze,
- brak ozdobników bez funkcji,
- brak mieszania wielu stylów.

Hasło stylistyczne:

```text
redakcja, książka, cisza, tekst, autor, czytelnik
```

---

## 3. Układ ekranów

Preferowany układ ekranów formularzowych to układ dwukolumnowy.

### Lewa kolumna

Lewa strona jest redakcyjnym wprowadzeniem:

- mała etykieta uppercase,
- bardzo duży nagłówek szeryfowy,
- krótki opis,
- dużo pustej przestrzeni.

Przykład:

```text
DOŁĄCZ DO ŹRÓDŁA SŁOWA
Rejestracja autora
Załóż konto, dodawaj teksty, zapisuj szkice i buduj własny panel autora.
```

### Prawa kolumna

Prawa strona zawiera właściwą kartę formularza:

- cienka jasna ramka,
- pola ułożone w siatce 2 kolumny,
- labelki uppercase,
- duże odstępy,
- czerwony główny przycisk,
- link pomocniczy tekstowy.

---

## 4. Formularze

Formularze nie mogą wyglądać technicznie ani przypadkowo.

Zasady:

- każdy input ma labelkę,
- labelki są uppercase,
- labelki mają delikatne rozstrzelenie liter,
- pola mają jasne tło i cienką ramkę,
- formularz ma przestrzeń między polami,
- przycisk główny jest czerwony,
- link pomocniczy jest tekstowy, bez ciężkiej ramki.

Nie robić:

- ciasnych formularzy,
- pól ustawionych przypadkowo w jednej linii,
- domyślnych przeglądarkowych przycisków,
- wielu kolorów,
- wielu rodzajów ramek.

---

## 5. Panel autora — wzorzec

Aktualny panel autora jest zatwierdzonym wzorcem.

Ma trzymać układ:

- etykieta `PANEL AUTORA`,
- duży nagłówek `Twoje teksty i konto`,
- opis pomocniczy,
- karta salda autora po prawej,
- karta `Dodaj nowy tekst`,
- sekcja `Moje teksty`,
- komunikat dla pustego konta autora.

Styl panelu autora jest punktem odniesienia dla:

- panelu czytelnika,
- portfela,
- premium,
- ustawień konta,
- widoku wypłat,
- prostych ekranów administracyjnych.

---

## 6. Kolory

Paleta ma pozostać prosta.

### Główne kolory

```text
Tło: biały / bardzo jasny szary
Tekst główny: prawie czarny
Tekst pomocniczy: ciemny szary
Ramki: jasny szary
Akcent: czerwony ŹRÓDŁA SŁOWA
```

### Czerwony akcent

Czerwony służy do:

- głównego CTA,
- logo,
- małych etykiet,
- aktywnych akcentów,
- strzałek/linków ważnych.

Nie używać czerwonego wszędzie. Ma być akcentem, nie tłem całego systemu.

---

## 7. Typografia — zasada główna

Nie mnożyć czcionek.

W projekcie mają być używane maksymalnie dwie rodziny pisma:

```text
1. Czcionka nagłówkowa — do dużych tytułów i redakcyjnych akcentów.
2. Czcionka tekstowa/UI — do menu, formularzy, opisów, tabel, przycisków i paneli.
```

Nie dodawać nowych fontów dla pojedynczych ekranów.
Nie dobierać fontów od nowa przy każdej poprawce.
Nie mieszać różnych stylów typograficznych.

Każdy nowy ekran ma korzystać z tych samych klas i zmiennych CSS.

---

## 8. Gdzie definiować czcionki

Czcionki i ich użycie muszą być definiowane centralnie w CSS, nie lokalnie w widokach.

Główne miejsce:

```text
public/assets/css/app.css
```

Tam powinny być trzymane:

- zmienne fontów,
- klasy nagłówków,
- style formularzy,
- style kart,
- style przycisków,
- style komunikatów.

Preferowany model:

```css
:root {
    --font-heading: ...;
    --font-ui: ...;
    --color-accent: ...;
    --color-text: ...;
    --color-muted: ...;
    --color-border: ...;
}
```

Widoki PHP nie powinny definiować własnych fontów inline.

Nie robić:

```html
<h1 style="font-family: ...">
```

Nie dodawać nowych `font-family` w losowych miejscach plików widoków.

---

## 9. Hierarchia typografii

Styl ma być konsekwentny.

### Duże nagłówki

Użycie:

- hero,
- rejestracja,
- panel autora,
- tytuły ważnych ekranów.

Charakter:

- duże,
- szeryfowe,
- spokojne,
- mocne,
- redakcyjne.

### Labelki

Użycie:

- formularze,
- sekcje,
- typ artykułu,
- małe oznaczenia.

Charakter:

- uppercase,
- mały rozmiar,
- rozstrzelone litery,
- często czerwony albo ciemny szary.

### Tekst UI

Użycie:

- menu,
- przyciski,
- opisy,
- dane konta,
- komunikaty,
- formularze.

Charakter:

- prosty,
- czytelny,
- bez ozdobników,
- konsekwentny na całej stronie.

---

## 10. Jednorodność stylu

Przy każdej zmianie obowiązuje zasada:

```text
Najpierw sprawdź istniejący wzorzec.
Potem uprość ekran do tego wzorca.
Nie projektuj nowego stylu od zera.
```

Jeśli nowy ekran wygląda inaczej niż rejestracja autora albo panel autora, trzeba go dopasować do wzorca.

Najczęstsze błędy do unikania:

- nowy układ formularza bez wspólnych klas,
- nowy rozmiar przycisku bez powodu,
- nowy font tylko dla jednej strony,
- inne odstępy niż w reszcie systemu,
- inne ramki/karty,
- przypadkowy wygląd tabel,
- mieszanie stylu technicznego z redakcyjnym.

---

## 11. Klasy i komponenty powinny być wspólne

Przy rozwoju systemu należy dążyć do wspólnych klas CSS.

Przykładowe grupy:

```text
.auth-split
.auth-intro
.auth-card
.form-grid
.form-field
.btn-primary
.btn-secondary
.panel-hero
.panel-card
.content-card
.notice-success
.notice-error
```

Nie tworzyć osobnych klas dla każdego ekranu, jeśli istniejąca klasa może obsłużyć ten sam cel.

Zasada:

```text
mniej klas, ale lepiej zaprojektowanych
```

---

## 12. Komunikaty

Komunikaty mają być spokojne i czytelne.

### Sukces

- zielony lub delikatnie zielony akcent,
- jasne tło,
- tekst prosty: `Zapisano zmiany.`

### Błąd

- czerwony akcent,
- jasne tło,
- konkretny komunikat,
- bez technicznego stack trace dla użytkownika.

Komunikaty powinny pasować do reszty UI, nie wyglądać jak domyślne alerty przeglądarki.

---

## 13. Admin

Panel admina może być bardziej użytkowy, ale nadal ma trzymać styl ŹRÓDŁA SŁOWA.

Zasady:

- kafelki w siatce,
- cienkie ramki,
- jasne tło,
- czytelna typografia,
- brak ciężkiego dashboardu technicznego,
- nie mieszać stylu Bootstrap/admin-template z redakcyjnym stylem projektu.

Admin ma być prosty i redakcyjny, nie korporacyjny.

---

## 14. Czego nie robić

Nie wolno:

- mnożyć fontów,
- dodawać losowych stylów inline,
- kopiować wyglądu z obcych template’ów,
- zmieniać stylu każdego ekranu osobno,
- tworzyć ciężkiego frameworkowego wyglądu,
- używać przypadkowych kolorów,
- mieszać czerwieni, niebieskich linków i domyślnych buttonów,
- przebudowywać UI bez sprawdzenia wzorca.

---

## 15. Zasada pracy przy kolejnych zmianach

Każda korekta UI ma odpowiadać na pytania:

```text
Czy ekran wygląda jak część ŹRÓDŁA SŁOWA?
Czy używa tych samych fontów?
Czy używa tych samych odstępów?
Czy używa tych samych przycisków?
Czy nie mnoży nowych klas bez potrzeby?
Czy jest prostszy niż wcześniej?
```

Jeśli odpowiedź brzmi nie — ekran trzeba uprościć i dopasować do wzorca.

---

## 16. Krótka formuła do przeniesienia do nowego wątku

```text
W projekcie ŹRÓDŁO SŁOWA obowiązuje zatwierdzony styl UI: czysty redakcyjny układ, biało-czarne tło, czerwony akcent, duże szeryfowe nagłówki, małe uppercase labelki, dużo oddechu, cienkie ramki i eleganckie karty. Wzorcem są aktualne ekrany: rejestracja autora i panel autora. Nie projektować stylu od nowa, nie mnożyć fontów, nie robić stylów inline. Fonty i komponenty trzymać centralnie w public/assets/css/app.css. Każdy nowy ekran ma korzystać z jednego wspólnego języka wizualnego i upraszczać, a nie dokładać nowy styl.
```
