# JĘZYKI I WERSJE JĘZYKOWE PUBLIKACJI — ŹRÓDŁO SŁOWA MOBILE

Zgodnie z pkt 2.6, 7 i 8 dyspozycji.

## 1. Obsługiwane wersje językowe/domenowe

Źródłem prawdy jest `config/sites.json` / `config/sites.php` (bez zmian). Powłoka odwzorowuje je 1:1 w `config/SiteConfig.kt`:

| Kod | Marka | Domena (produkcja) |
|---|---|---|
| PL | ŹRÓDŁO SŁOWA | wg `sites.json` |
| EN | SOURCE OF WORD | wg `sites.json` |
| DE | WORTQUELLE | wg `sites.json` |
| FR | SOURCE DES MOTS | wg `sites.json` |
| IT | FONTE DI PAROLE | wg `sites.json` |
| ES | FUENTE DE PALABRAS | wg `sites.json` |

Nazwy marek są sztywno zdefiniowane w `config/languages.php`/`sites.json` istniejącego serwisu — powłoka ich nie generuje ponownie, jedynie je odwzorowuje (`SiteConfig.kt`), zgodnie z zakazem tworzenia nowych nazw (pkt 7.5).

## 2. Rozpoznawanie języka aplikacji (ETAP 8, `AppLanguageManager`)

Kolejność zgodna z pkt 7.2 dyspozycji:

1. ostatni jawny wybór użytkownika w aplikacji — **RYZYKO / częściowo zaimplementowane**: `AppLanguageManager` odczytuje obecnie `Locale.getDefault()` systemu Android przy starcie (pkt 7.1); trwały zapis „ostatniego jawnego wyboru w aplikacji” niezależnie od zmiany języka systemu nie został jeszcze dodany jako osobny mechanizm (patrz „Braki” niżej).
2. preferencja `interface_language` zwrócona przez istniejący serwis po zalogowaniu — powłoka nie odczytuje jej programowo z odpowiedzi serwera (WebView renderuje treść po stronie serwisu); wartość jest widoczna użytkownikowi w treści WebView, ale nie jest automatycznie propagowana do wyboru domeny w aplikacji natywnej. Opisane jako ograniczenie.
3. język systemu Android przy pierwszym uruchomieniu — **GOTOWE**: `AppLanguageManager.resolveLanguageCode()`.
4. PL jako fallback — **GOTOWE**: gdy język systemu nie jest jednym z 6 obsługiwanych.

## 3. Ręczna zmiana języka (pkt 7.3)

Status: **NIEDOSTĘPNE BEZ DODATKOWEJ PRACY NATYWNEJ** — w bieżącej wersji powłoki nie ma dedykowanego natywnego przełącznika języka w pasku (Kurs Słowa/przełącznik pozostają w WebView, zgodnie z pkt 5 „nie hardkoduj”, „pozostaw istniejący serwerowy wskaźnik widoczny w WebView”). Użytkownik może zmienić język **wewnątrz WebView** korzystając z istniejącego `language_switcher` serwisu (`public_language_url`) — to jest realizowane w 100% przez serwis, zgodnie z zasadą „aplikacja nie buduje własnego systemu tłumaczeń”. Natywne menu, ekran logowania i powiadomienia zmieniają się automatycznie wraz z `Locale` systemu (`values`, `values-en/de/fr/it/es`), a nie przez osobny przełącznik w aplikacji.

Zapamiętanie wyboru w obrębie WebView odbywa się po stronie serwisu (cookie/sesja tej domeny) — zgodnie z pkt 14 zasadą braku kopiowania cookie między domenami.

## 4. Wersje językowe publikacji (pkt 8)

Powłoka nie zawiera żadnej własnej logiki tłumaczeń artykułów. `article_language_switcher`, `public_article_language_url()`, mapy wersji językowych artykułu są w całości renderowane przez serwis wewnątrz `SecureWebView` (ekran Artykuły / widok artykułu). Kliknięcie przełącznika języka artykułu w WebView otwiera bezpośrednio adres zwrócony przez serwis (link do konkretnej opublikowanej wersji) — nawigacja WebView pozostaje na tym samym ekranie, zgodnie z wymogiem „nie wracaj bez potrzeby do listy artykułów”.

Status: **GOTOWE W WEBVIEW**.

## 5. Zasoby natywne 6 języków (pkt 7.4)

Kompletne zestawy `values`, `values-en`, `values-de`, `values-fr`, `values-it`, `values-es` (ETAP 8) obejmują wyłącznie teksty natywne powłoki: dolne menu, logowanie, powiadomienia. Teksty artykułów/serwisu pozostają wyłącznie w istniejących tłumaczeniach WWW.

## 6. Braki / do rozważenia w kolejnej iteracji

- Brak trwałego, jawnego wyboru języka zapisywanego niezależnie od `Locale` systemu (pkt 7.2 p.1 i 7.3 „zapisać wybór lokalnie”) — obecnie język natywnej powłoki wynika wyłącznie z `Locale.getDefault()` przy każdym uruchomieniu (fallback PL), bez lokalnego zapisu ostatniego ręcznego wyboru. Wymaga to dodania prostego lokalnego przechowywania (np. `SharedPreferences`) w kolejnym przebiegu — nie wykonano w tej sesji, aby nie przekroczyć zakresu bez wyraźnej akceptacji.
- Brak automatycznego odczytu `interface_language` z odpowiedzi serwera po zalogowaniu do synchronizacji domeny natywnej powłoki — wartość widoczna jest tylko w WebView.
