

# ŹRÓDŁO SŁOWA MOBILE — ZEWNĘTRZNA POWŁOKA NAD ISTNIEJĄCYM SERWISEM

## 0. MISJA

Zbuduj nową, osobną aplikację Android:

```text
ŹRÓDŁO SŁOWA
```

Ma to być lekka, bezpieczna, mobilna powłoka nad tym, co już działa w serwisie.

Nie budujesz drugiego silnika platformy.

Nie przepisujesz do telefonu logiki:

- kont;
- ról;
- publikacji;
- tłumaczeń artykułów;
- portfela;
- sald;
- punktów TT;
- kursu TT;
- zarobków;
- wypłat;
- Premium;
- komentarzy;
- powiadomień;
- 3DORS;
- księgi;
- uprawnień.

Źródłem prawdy pozostają:

```text
istniejący backend PHP
PostgreSQL
obecny serwis WWW
obecny system publikacji
obecny system językowy
obecny system powiadomień
obecny portfel i księga
3DORS Author
3DORS Admin
```

Aplikacja tylko:

```text
pokazuje istniejący serwis
→ zapewnia mobilną nawigację
→ utrzymuje bezpieczną sesję
→ obsługuje pliki, aparat i linki
→ pokazuje istniejące powiadomienia
→ otwiera 3DORS Author, gdy backend wymaga podpisu
```

---

# 1. BEZWZGLĘDNY ZAKAZ ZMIAN W ISTNIEJĄCYM SYSTEMIE

Pracuj wyłącznie w nowym katalogu:

```text
X:\zrodlo-slowa\mobile\zrodlo-slowa-android
```

Możesz czytać i analizować cały projekt:

```text
X:\zrodlo-slowa
```

ale nie wolno Ci zmieniać żadnego istniejącego pliku poza nową aplikacją.

Nie zmieniaj:

```text
app/
config/
database/
public/
resources/
scripts/
tests/
views/
compose.yaml
mobile/3dors-android/
```

Nie poprawiaj przy okazji backendu, serwisu, panelu, 3DORS, tłumaczeń WWW ani istniejących workerów.

Nie dodawaj nowych tras, endpointów, tabel, migracji ani workerów.

Jeżeli istniejący system czegoś nie udostępnia:

```text
opisz brak w audycie
→ oznacz funkcję jako niedostępną w pierwszej wersji
→ nie twórz obejścia
→ nie zmieniaj backendu
```

Nowa aplikacja ma korzystać wyłącznie z tego, co już istnieje.

Dokumentację audytu i odbioru zapisuj wewnątrz nowej aplikacji:

```text
mobile/zrodlo-slowa-android/docs/
```

---

# 2. ETAP A0 — PEŁNY AUDYT ZALEŻNOŚCI PRZED KODOWANIEM

Przed rozpoczęciem implementacji wykonaj pełny audyt obecnego systemu.

Nie zakładaj, że opis projektu wystarcza. Sprawdź rzeczywisty kod, routing, widoki, role, sesje, publikacje, języki, portfel, powiadomienia i 3DORS.

Utwórz:

```text
mobile/zrodlo-slowa-android/docs/AUDYT_ZALEZNOSCI_ZRODLO_SLOWA_MOBILE.md
```

Dopiero po ukończeniu audytu rozpocznij budowę aplikacji.

## 2.1. Audyt publicznego serwisu

Sprawdź i opisz:

- stronę główną;
- główny materiał;
- polecane publikacje;
- najnowsze publikacje;
- kategorie i tematy;
- listę artykułów;
- widok artykułu;
- autorów;
- wyszukiwarkę;
- komentarze;
- ankiety;
- kampanie;
- Premium;
- logowanie;
- rejestrację;
- konto użytkownika;
- ustawienia konta;
- konto autora;
- szkice i teksty autora;
- portfel;
- zasilenie konta;
- TT;
- zarobki;
- wpływy;
- historię operacji;
- wypłaty;
- powiadomienia;
- upload i download plików;
- logout;
- trasy anonimowe i chronione.

Dla każdego ekranu przygotuj mapę:

```text
ekran aplikacji
→ istniejąca trasa serwisu
→ wymagane konto
→ wymagana rola
→ wymagane uprawnienia
→ język
→ sposób otwarcia
```

## 2.2. Audyt ról

Zachowaj obowiązującą logikę:

```text
CZYTELNIK
→ może mieć konto
→ może się logować
→ może zasilać konto
→ może posiadać TT
→ może wspierać autorów
→ nie może otrzymywać pieniędzy
→ nie może wykonywać wypłat
→ nie korzysta z 3DORS
```

```text
DZIENNIKARZ / AUTOR
→ kwalifikuje się do 3DORS Author
→ może pracować redakcyjnie
→ praca redakcyjna nie zależy od wypłat
→ wypłata wymaga osobno wallet_enabled=1 i payout_enabled=1
```

```text
ADMINISTRATOR
→ korzysta z panelu WWW
→ korzysta z osobnej aplikacji 3DORS Admin
→ panel administratora nie trafia do publicznej aplikacji Źródła Słowa
```

Nie twórz lokalnej logiki ról w telefonie.

Telefon może dostosować menu do odpowiedzi serwisu, ale nie jest źródłem uprawnień.

## 2.3. Audyt 3DORS

Sprawdź:

- kontrakt backendu 3DORS;
- automatyczne kierowanie operacji;
- App Links;
- schematy debug;
- `article.submit`;
- `article.publish`;
- TTL;
- approve;
- reject;
- powrót do aplikacji inicjującej;
- odświeżenie statusu po powrocie;
- błędy;
- wariant Author;
- granicę bezpieczeństwa między aplikacją główną a 3DORS.

Najważniejsza zasada:

> Źródło Słowa Mobile może otworzyć 3DORS Author, ale nigdy nie podpisuje operacji i nie zawiera kodu 3DORS.

Nie zmieniaj:

- kontraktu podpisu;
- identyfikatorów operacji;
- fingerprintu;
- payloadu kanonicznego;
- polityk Admin/Author;
- kodu aplikacji 3DORS;
- kluczy i credentiali 3DORS.

## 2.4. Audyt finansów i TT

Sprawdź rzeczywisty przepływ:

```text
saldo pieniężne
saldo TT
zasilenie
wsparcie autora
zarobek autora
wpływ
środki oczekujące
historia
konwersja TT
wypłata
wallet_enabled
payout_enabled
Maker–Checker
```

Aplikacja nie może:

- liczyć salda;
- samodzielnie przeliczać TT;
- ustalać kursu;
- tworzyć własnej historii;
- odtwarzać księgi;
- uznawać wartości lokalnego cache za saldo.

## 2.5. Audyt powiadomień

Sprawdź istniejące:

```text
worker-notifications
NotificationOutboxDispatcher
NotificationOutboxJobHandler
kolejki
tabele powiadomień
kursor
ACK
licznik nieprzeczytanych
```

Nie twórz drugiego workera.

Określ:

- które typy powiadomień już istnieją;
- które są dostępne użytkownikowi;
- jak są pobierane;
- jak są oznaczane jako przeczytane;
- czy istnieje lekki sygnał Valkey;
- co można pokazać bez zmian backendu.

## 2.6. Audyt języków i publikacji

Sprawdź cały istniejący system językowy:

```text
config/languages.php
config/sites.php / sites.json
PublicLanguageService
PublicSiteResolver
public_language()
public_language_url()
public_article_language_url()
language_switcher
article_language_switcher
ustawienie interface_language
wersje językowe artykułu
fallback tłumaczenia
strona braku tłumaczenia
```

Obsługiwane języki wynikają z istniejącej konfiguracji. Obecnie są to:

```text
PL
EN
DE
FR
IT
ES
```

Sprawdź istniejące marki i domeny:

```text
PL — ŹRÓDŁO SŁOWA
EN — SOURCE OF WORD
DE — WORTQUELLE
FR — SOURCE DES MOTS
IT — FONTE DI PAROLE
ES — FUENTE DE PALABRAS
```

Nie twórz w aplikacji własnego systemu tłumaczeń artykułów.

Aplikacja ma otwierać wyłącznie istniejące, opublikowane wersje językowe.

## 2.7. Audyt „KURSU SŁOWA” TT

Uwaga:

> „Kurs TT” oznacza istniejący wskaźnik **KURS SŁOWA**, np. `10 TT = 1 PLN`. Nie jest to kurs edukacyjny.

Sprawdź:

```text
wallet.tt_per_pln
PaymentRuntimeConfigService
WalletTransferService
tt_rate_label
KURS SŁOWA w publicznym nagłówku
KURS SŁOWA w portfelu
display_currency
kursy NBP
```

Zasada:

> Aplikacja nie oblicza kursu TT. Wyświetla wartość przygotowaną przez istniejący backend.

## 2.8. Audyt wyglądu

Sprawdź:

- prawdziwe logo Źródła Słowa;
- znaki dla wszystkich wersji językowych;
- firmową czerwień;
- dark mode;
- obecne zdjęcia publikacji;
- układ strony głównej;
- układ artykułu;
- mobilną responsywność;
- widok Portfela;
- istniejące ikony;
- teksty interfejsu.

Nie odtwarzaj logo ani kolorów z pamięci lub z generatora.

Użyj istniejących zasobów projektu.

## 2.9. Wynik audytu

Każdy obszar oznacz jako:

```text
GOTOWE DO UŻYCIA
GOTOWE W WEBVIEW
GOTOWE DO NATYWNEJ POWŁOKI
NIEDOSTĘPNE BEZ ZMIAN SERWISU
NIE WOLNO PRZENOSIĆ DO APLIKACJI
RYZYKO
```

Raport ma zawierać:

- mapę ekranów i tras;
- macierz ról;
- mapę zależności 3DORS;
- mapę finansów;
- mapę TT i Kursu Słowa;
- mapę powiadomień;
- mapę języków i domen;
- mapę wersji językowych publikacji;
- listę elementów dostępnych bez zmian backendu;
- listę elementów niedostępnych;
- plan implementacji wyłącznie w nowej aplikacji.

---

# 3. ARCHITEKTURA NOWEJ APLIKACJI

Aplikacja ma być:

```text
natywna powłoka Android
+
kontrolowany WebView
+
istniejący serwis
+
mobilna nawigacja
+
bezpieczna sesja
+
obsługa plików
+
istniejące powiadomienia
+
przejście do 3DORS Author
```

Użyj:

```text
Kotlin
Jetpack Compose
Android WebView
```

Nie buduj lokalnego silnika treści ani finansów.

Proponowana struktura:

```text
mobile/zrodlo-slowa-android/
├── app/
│   └── src/main/
│       ├── java/.../
│       │   ├── MainActivity
│       │   ├── shell/
│       │   ├── navigation/
│       │   ├── web/
│       │   │   ├── SecureWebView
│       │   │   ├── WebSessionManager
│       │   │   ├── RouteResolver
│       │   │   ├── LanguageRouteResolver
│       │   │   ├── FileUploadHandler
│       │   │   ├── DownloadHandler
│       │   │   └── ExternalLinkHandler
│       │   ├── language/
│       │   │   ├── AppLanguageManager
│       │   │   └── SupportedLanguage
│       │   ├── notifications/
│       │   ├── dors3/
│       │   │   ├── Dors3AuthorLauncher
│       │   │   └── Dors3ResultHandler
│       │   ├── ui/
│       │   │   ├── shell/
│       │   │   ├── loading/
│       │   │   ├── offline/
│       │   │   └── error/
│       │   └── settings/
│       └── res/
└── docs/
```

---

# 4. MENU APLIKACJI

Stałe dolne menu:

```text
GŁÓWNA
ARTYKUŁY
PORTFEL
POWIADOMIENIA
KONTO
```

Menu ma odpowiadać zaakceptowanej makiecie:

- prostokątny pasek na pełną szerokość;
- wyraźna czerwona linia oddzielająca menu od treści;
- linia nie jest całkowicie prosta;
- w środkowej części łagodnie podnosi się wokół przycisku Portfela;
- Portfel jest okrągłym, wystającym przyciskiem;
- pasek nie jest dużym owalem;
- Portfel nie jest tarczą;
- nie używaj kłódki ani symboliki 3DORS;
- aktywna sekcja jest zaznaczona czerwienią;
- zdjęcia publikacji pozostają kolorowe.

Nie pokazuj dużej karty Portfela na ekranie głównym.

---

# 5. GÓRNY OBSZAR APLIKACJI

Górny obszar ma wykorzystywać istniejący styl i dane serwisu.

Powinien zapewniać dostęp do:

- logo właściwej wersji językowej;
- wyszukiwarki;
- przełącznika języka;
- wskaźnika `KURS SŁOWA`;
- ewentualnego licznika powiadomień.

Nie hardkoduj Kursu Słowa.

Nie kopiuj ręcznie nazwy marki dla języka, jeżeli można ją pobrać z istniejącego kontekstu strony.

Jeżeli bez zmiany backendu nie da się bezpiecznie odtworzyć wartości Kursu Słowa w natywnym pasku:

```text
pozostaw istniejący serwerowy wskaźnik widoczny w WebView
```

Nie parsuj ani nie licz kursu jako źródła prawdy.

---

# 6. STRONA GŁÓWNA

Pokaż istniejącą publiczną stronę główną i to, co jest na niej publikowane:

- logo i marka dla bieżącego języka;
- główny materiał;
- polecane;
- najnowsze;
- kategorie;
- tematy;
- ankiety;
- kampanie;
- istniejący Kurs Słowa;
- istniejący przełącznik języka;
- publiczne elementy Premium;
- wyszukiwanie;
- autorów;
- wszystkie inne publiczne sekcje, które audyt potwierdzi.

Nie twórz nowego algorytmu strony głównej.

Nie układaj publikacji lokalnie.

Nie pobieraj i nie kataloguj artykułów w oddzielnej bazie telefonu.

Zdjęcia:

- muszą pozostać kolorowe;
- mają pochodzić z publikacji;
- nie mogą być przerabiane do jednego sztucznego stylu;
- aplikacja nie generuje miniatur zastępczych, jeśli serwis ma prawdziwe zdjęcie.

## KURS SŁOWA na stronie głównej

Wskaźnik ma być widoczny zgodnie z istniejącym serwisem:

```text
KURS SŁOWA
10 TT = 1 PLN
```

lub w lokalnej walucie przygotowanej przez backend.

Kliknięcie otwiera istniejący Portfel.

Aplikacja nie może używać starej wartości kursu do wykonania konwersji.

---

# 7. AUTOMATYCZNE ROZPOZNAWANIE JĘZYKA

## 7.1. Pierwsze uruchomienie

Przy pierwszym uruchomieniu aplikacja odczytuje język Androida.

Jeżeli język urządzenia jest obsługiwany:

```text
pl
en
de
fr
it
es
```

otwiera odpowiednią istniejącą wersję serwisu.

Jeżeli język urządzenia nie jest obsługiwany:

```text
fallback = PL
```

Nie używaj lokalizacji GPS ani kraju karty SIM do wyboru języka.

## 7.2. Kolejność wyboru języka

Stosuj logiczną kolejność:

```text
1. ostatni jawny wybór użytkownika w aplikacji
2. preferencja interface_language zwrócona przez istniejący serwis po zalogowaniu
3. język systemu Android przy pierwszym uruchomieniu
4. PL
```

Nie nadpisuj jawnego wyboru użytkownika przy każdym uruchomieniu.

## 7.3. Ręczna zmiana

Użytkownik może ręcznie zmienić język.

Zmiana ma:

- korzystać z istniejących adresów i mechanizmu `public_language_url`;
- zachować bieżący kontekst, jeśli serwis to potrafi;
- zapisać wybór lokalnie;
- przyjąć wynik serwera jako źródło prawdy;
- zmienić natywne teksty powłoki.

## 7.4. Języki natywnej powłoki

Przygotuj komplet zasobów:

```text
res/values/strings.xml          — PL
res/values-en/strings.xml       — EN
res/values-de/strings.xml       — DE
res/values-fr/strings.xml       — FR
res/values-it/strings.xml       — IT
res/values-es/strings.xml       — ES
```

Dotyczy tylko tekstów natywnych aplikacji:

- dolne menu;
- ładowanie;
- offline;
- błąd;
- retry;
- wybór języka;
- komunikat otwarcia 3DORS;
- ustawienia aplikacji;
- powiadomienia systemowe powłoki.

Teksty artykułów i serwisu pozostają po stronie istniejących tłumaczeń WWW.

Nie wpisuj tekstów widocznych dla użytkownika bezpośrednio w Kotlinie.

## 7.5. Marki językowe

Powłoka ma respektować istniejącą markę językową:

```text
PL — ŹRÓDŁO SŁOWA
EN — SOURCE OF WORD
DE — WORTQUELLE
FR — SOURCE DES MOTS
IT — FONTE DI PAROLE
ES — FUENTE DE PALABRAS
```

Nie twórz nowych nazw.

---

# 8. WERSJE JĘZYKOWE PUBLIKACJI

Artykuł może posiadać kilka opublikowanych wersji językowych.

Aplikacja korzysta z istniejącego:

```text
article_language_switcher
public_article_language_url()
article_language_map
article_language_versions
```

Zasady:

```text
użytkownik zmienia język artykułu
→ aplikacja otwiera istniejącą wersję publikacji
```

Jeżeli wersja jest opublikowana:

```text
otwórz właściwy tytuł, slug i treść
```

Jeżeli wersja nie istnieje:

```text
pokaż istniejący komunikat serwisu
lub istniejący fallback do tekstu źródłowego
```

Aplikacja nie może:

- tłumaczyć artykułu sama;
- generować brakującej wersji;
- udawać, że tłumaczenie istnieje;
- zmieniać języka źródłowego publikacji;
- budować własnej mapy tłumaczeń poza URL dostarczonym przez serwis.

Przy zmianie języka na stronie artykułu nie wracaj bez potrzeby do listy artykułów. Użyj istniejącego linku do odpowiedniej wersji konkretnej publikacji.

---

# 9. ARTYKUŁY

Zakładka `ARTYKUŁY` pokazuje istniejący serwis:

- listę;
- kategorie;
- obrazy;
- tytuły;
- leady;
- autorów;
- daty;
- języki;
- Premium;
- wyszukiwanie;
- pełny tekst;
- komentarze;
- istniejące wsparcie autora;
- istniejące wersje językowe.

Nie buduj osobnego repozytorium artykułów.

## Rozmowa z autorem

Nie buduj silnika tej funkcji.

Jeżeli backend później opublikuje przy artykule:

```text
ROZMOWA Z AUTOREM — PREMIUM
```

powłoka ma ją wyświetlić jako istniejącą część strony artykułu.

---

# 10. PORTFEL

Portfel jest osobnym pełnym ekranem.

Ma dawać użytkownikowi poczucie osobistego konta:

```text
MÓJ PORTFEL
saldo
saldo TT
KURS SŁOWA
dostępne środki
oczekujące środki
całkowite środki
wpływy
zasil konto
wypłata
ostatnie operacje
```

Aplikacja korzysta z istniejącego widoku i istniejących działań serwisu.

Nie używaj tarczy ani kłódki 3DORS.

## Czytelnik

Pokazuj tylko to, co backend pozwala:

- saldo;
- TT;
- Kurs Słowa;
- zasilenie;
- wsparcie autorów;
- historię.

Nie pokazuj możliwości otrzymywania pieniędzy ani wypłaty.

## Autor

Dodatkowo, jeśli backend udostępnia:

- zarobki;
- wpływy;
- oczekujące środki;
- historia;
- wypłata.

Wypłata wyłącznie przy:

```text
wallet_enabled=1
payout_enabled=1
```

Brak `payout_enabled` nie może blokować pracy redakcyjnej.

## TT i KURS SŁOWA

Aplikacja wyświetla:

- bieżące saldo TT;
- bieżący `tt_rate_label`;
- istniejące informacje o konwersji.

Nie oblicza:

```text
TT → PLN
TT → waluta lokalna
```

Wartość pochodzi z serwera.

---

# 11. POWIADOMIENIA

Korzystaj z istniejącego systemu i istniejącego workera.

Nie twórz drugiego workera ani drugiej tabeli.

Zakładka może pokazać wyłącznie powiadomienia rzeczywiście dostępne w serwisie.

Docelowe typy, jeżeli audyt potwierdzi:

- nowy zarobek;
- wsparcie tekstu;
- zmiana statusu artykułu;
- publikacja;
- komentarz;
- wypłata;
- komunikat systemowy.

W pierwszej wersji nie udawaj powiadomień push, jeżeli backend nie ma istniejącej integracji push.

Możesz:

- pokazać istniejącą skrzynkę powiadomień;
- pokazać licznik;
- odświeżać zgodnie z istniejącym mechanizmem;
- obsłużyć istniejący kursor i ACK.

---

# 12. KONTO

## Czytelnik

```text
Moje dane
Język interfejsu
Waluta wyświetlania
Powiadomienia
Bezpieczeństwo konta
Ustawienia aplikacji
Wyloguj
```

## Autor

Dodatkowo:

```text
Moje teksty
Szkice
Zarobki
Statystyki
Panel autora
```

Nie umieszczaj panelu administratora.

Zmiana języka konta ma korzystać z istniejącego `interface_language`.

Powłoka ma przyjąć wynik serwera i zaktualizować własny język.

---

# 13. INTEGRACJA Z 3DORS AUTHOR

Przepływ:

```text
autor wykonuje akcję w Źródło Słowa Mobile
→ istniejący backend tworzy żądanie
→ backend automatycznie wybiera 3DORS Author
→ powłoka otwiera 3DORS Author
→ 3DORS Author pokazuje wiążące dane
→ ZATWIERDŹ albo ODRZUĆ
→ powrót do Źródło Słowa Mobile
→ odświeżenie istniejącej strony i statusu
```

Powłoka:

- nie wybiera typu podpisu;
- nie podpisuje;
- nie przechowuje klucza 3DORS;
- nie odtwarza fingerprintu;
- nie otwiera 3DORS Admin;
- nie zawiera kodu 3DORS;
- nie zmienia kontraktu.

---

# 14. BEZPIECZNY WEBVIEW

Wymagania:

- allowlista wszystkich istniejących oficjalnych domen językowych;
- osobna allowlista debug dla lokalnego serwera;
- HTTPS w release;
- wyłączony mixed content;
- obce domeny w przeglądarce systemowej;
- brak dowolnego JavaScript bridge;
- brak `addJavascriptInterface` dla strony publicznej;
- WebView debugging wyłączony w release;
- Safe Browsing;
- kontrolowany upload;
- kontrolowany download;
- kontrola MIME;
- obsługa aparatu i galerii;
- brak przechowywania hasła w aplikacji;
- bezpieczne cookies;
- wylogowanie czyści właściwą sesję;
- brak backupu danych aplikacji;
- brak sekretów w logach;
- ekran offline;
- ekran błędu;
- retry;
- ochrona przed otwarciem fałszywej domeny.

Nie kopiuj cookies pomiędzy różnymi domenami wbrew zasadom przeglądarki.

Jeżeli istniejący serwis nie utrzymuje sesji pomiędzy wersjami domenowymi, opisz to jako ograniczenie. Nie obchodź tego przez niebezpieczne kopiowanie sesji.

---

# 15. CZEGO NIE ROBIĆ

Nie buduj:

- drugiego backendu;
- nowych endpointów;
- nowych migracji;
- drugiej bazy;
- lokalnego silnika artykułów;
- własnego tłumacza;
- własnej mapy publikacji językowych;
- własnego Kursu Słowa;
- lokalnej księgi;
- lokalnego liczenia salda;
- kopii panelu administratora;
- kodu 3DORS;
- drugiego workera powiadomień;
- własnego systemu Premium;
- czatu z autorami;
- funkcji, której serwis nie posiada.

Nie zmieniaj istniejącego projektu, aby dopasować go do aplikacji.

To aplikacja ma dopasować się do działającego serwisu.

---

# 16. TESTY

Sprawdź co najmniej:

## Publiczne treści

1. Strona główna.
2. Główny materiał.
3. Polecane.
4. Najnowsze.
5. Kategorie.
6. Artykuły.
7. Komentarze.
8. Ankiety i kampanie, jeśli są publiczne.
9. Kolorowe zdjęcia bez modyfikacji.

## Języki

10. Automatyczny język PL.
11. Automatyczny język EN.
12. Automatyczny język DE.
13. Automatyczny język FR.
14. Automatyczny język IT.
15. Automatyczny język ES.
16. Fallback do PL dla nieobsługiwanego języka.
17. Ręczna zmiana języka.
18. Zapamiętanie wyboru.
19. Przyjęcie `interface_language` po zalogowaniu.
20. Zmiana marki językowej.
21. Zmiana wersji językowej konkretnego artykułu.
22. Brak tłumaczenia i istniejący fallback.
23. Zmiana języka nie otwiera błędnego artykułu.
24. Sesja na różnych domenach zachowuje się zgodnie z istniejącym serwisem.

## Kurs Słowa i TT

25. KURS SŁOWA widoczny na stronie głównej.
26. Kurs prowadzi do Portfela.
27. Kurs nie jest hardkodowany.
28. Kurs aktualizuje się po zmianie ustawienia serwera.
29. Zmiana waluty wyświetlania pokazuje wartość przygotowaną przez backend.
30. Aplikacja nie używa lokalnej wartości do konwersji.
31. Saldo TT pochodzi z serwisu.

## Konta i role

32. Anonimowy użytkownik.
33. Czytelnik.
34. Autor.
35. Czytelnik nie ma wypłaty.
36. Czytelnik nie uruchamia 3DORS.
37. Autor bez `payout_enabled` może pracować redakcyjnie.
38. Autor bez `payout_enabled` nie może wypłacać.
39. Panel administratora nie jest dostępny z aplikacji.

## Sesja i pliki

40. Logowanie.
41. Wylogowanie.
42. Utrzymanie sesji.
43. Wygaśnięcie sesji.
44. Upload obrazu.
45. Aparat.
46. Galeria.
47. Download.
48. Obcy link.
49. Offline.
50. Retry.

## 3DORS

51. Otwarcie 3DORS Author dla `article.submit`.
52. Otwarcie 3DORS Author dla `article.publish`.
53. Powrót po approve.
54. Powrót po reject.
55. Powrót po TTL.
56. Brak możliwości uruchomienia 3DORS Admin.
57. Brak kodu podpisu w powłoce.

## UI

58. Dolne menu.
59. Czerwona linia z podniesieniem przy Portfelu.
60. Portfel pośrodku jako wystający przycisk.
61. Brak dużej karty Portfela na stronie głównej.
62. Dark mode.
63. Mały ekran.
64. Duży font.
65. Obrót ekranu, jeżeli wspierany.
66. Fizyczny telefon.

---

# 17. ODBIÓR

Dostarcz:

```text
mobile/zrodlo-slowa-android/docs/AUDYT_ZALEZNOSCI_ZRODLO_SLOWA_MOBILE.md
mobile/zrodlo-slowa-android/docs/MAPA_EKRANOW_I_TRAS.md
mobile/zrodlo-slowa-android/docs/JEZYKI_I_WERSJE_PUBLIKACJI.md
mobile/zrodlo-slowa-android/docs/KURS_SLOWA_TT.md
mobile/zrodlo-slowa-android/docs/BEZPIECZENSTWO_WEBVIEW.md
mobile/zrodlo-slowa-android/docs/INTEGRACJA_Z_3DORS_AUTHOR.md
mobile/zrodlo-slowa-android/docs/RAPORT_TESTOW.md
```

Dodatkowo:

- kod nowej aplikacji;
- APK debug;
- zrzuty pięciu głównych ekranów;
- zrzuty wszystkich sześciu języków;
- zrzut Kursu Słowa;
- zrzut wersji językowych artykułu;
- zrzut Portfela czytelnika;
- zrzut Portfela autora;
- zrzut przejścia do 3DORS Author;
- listę funkcji istniejących, ale niedostępnych bez zmian backendu.

Nie przedstawiaj funkcji jako wykonanej, jeżeli istnieje tylko w makiecie.

---

# 18. KRYTERIUM KOŃCOWE

Praca jest zakończona, gdy:

- audyt zależności powstał przed implementacją;
- nie zmieniono żadnego istniejącego pliku projektu;
- cały kod znajduje się w nowej aplikacji;
- aplikacja jest powłoką, a nie drugim silnikiem;
- publiczna strona główna i publikacje są poprawnie pokazane;
- KURS SŁOWA i TT korzystają z istniejącego backendu;
- aplikacja automatycznie rozpoznaje obsługiwany język Androida;
- użytkownik może ręcznie zmienić język;
- wybór języka jest zapamiętany;
- wszystkie sześć wersji językowych powłoki działa;
- wersje językowe publikacji korzystają z istniejącego systemu;
- role i finanse działają identycznie jak w serwisie;
- Portfel korzysta z istniejących danych;
- powiadomienia korzystają z istniejącego systemu;
- 3DORS pozostaje osobną aplikacją podpisu;
- menu odpowiada zaakceptowanej makiecie;
- aplikacja działa na fizycznym telefonie.
