# ŹRÓDŁO SŁOWA — PEŁNA SCALONA DOKUMENTACJA, AUDYT I TABELE

> Plik scalony z dokumentów `.md` oraz tabel `.csv` przekazanych do rozmowy. Zawartość została połączona bez skracania i bez streszczania.

## Spis treści

- [Dokumentacja opisowa i techniczna](#dokumentacja-opisowa-i-techniczna)
- [Audyty opisowe 01–15](#audyty-opisowe-01-15)
- [Tabele audytowe CSV](#tabele-audytowe-csv)
- [Dyspozycje dla JUNI](#dyspozycje-dla-juni)

---


# Dokumentacja opisowa i techniczna


---

<!-- ŹRÓDŁO: ZRODLO_SLOWA_DOKUMENTACJA_OPISOWA_ZASADY_SYSTEMU.md -->


# ŹRÓDŁO SŁOWA — Dokumentacja Opisowa i Zasady Systemu

## 1. Czym jest ŹRÓDŁO SŁOWA
ŹRÓDŁO SŁOWA to innowacyjna platforma wydawnicza łącząca świat tradycyjnego dziennikarstwa z nowoczesną ekonomią punktową (Talent) i wsparciem Sztucznej Inteligencji. System pozwala autorom publikować treści, monetyzować je poprzez sprzedaż bezpośrednią lub darowizny, a czytelnikom angażować się w życie portalu i zdobywać nagrody.

## 2. Role i Panele Użytkowników
- **Czytelnik**: Może przeglądać publiczne treści, brać udział w ankietach i zdobywać punkty Talent.
- **Komentator**: Może przygotowywać podpisane opinie i polemiki do oceny Redakcji. Ma Talent i Portfel TT, ale nie może publikować zwykłych artykułów ani otrzymywać wypłat pieniężnych.
- **Autor**: Posiada własny warsztat pracy do tworzenia tekstów, wgrywania obrazów i śledzenia swoich zarobków.
- **Redaktor Główny**: Pierwsza linia weryfikacji. Decyduje o dopuszczeniu tekstu autora do dalszych prac.
- **Korektor**: Odpowiada za czystość językową, poprawiając wyłącznie treść bez ingerencji w parametry biznesowe.
- **Wydawca**: "Gospodarz" strony głównej. Decyduje o kolejności tekstów i ostatecznej publikacji.
- **Moderator**: Ustawia ceny, statusy premium i przygotowuje treści do procesów AI.
- **Księgowa / Finanse**: Nadzoruje wypłaty i weryfikuje spójność finansową systemu.
- **Administrator**: Posiada pełny dostęp do konfiguracji technicznej, ról i ustawień AI.

## 3. Cykl życia artykułu
System wymusza rygorystyczny proces redakcyjny:
1. Autor tworzy **Szkic**.
2. Autor wysyła tekst do **Redakcji**.
3. Redakcja Główna analizuje tekst i go **Zatwierdza**.
4. Tekst trafia do **Korekty** oraz do **Moderacji** (wycena, status premium).
5. Wydawca ustala parametry ekspozycji i zmienia status na **Opublikowany**.

## 4. Ekonomia i Portfel
System opiera się na trzech filarach finansowych:
- **Sprzedaż Treści**: Podział kwalifikowanego wpływu 40% dla autora, 40% dla serwisu i 20% dla Safety Fund przy zakupie artykułów premium.
- **Punkty Talent (TT)**: Nagrody za zweryfikowane działania, w tym czytanie i faktycznie opublikowane opinie lub polemiki. TT stanowią "kapitał społeczny", który można wymienić na PLN zgodnie z aktywnymi zasadami.
- **Darowizny**: Możliwość bezpośredniego wsparcia autora przez czytelników.

## 5. Wsparcie AI
Sztuczna Inteligencja w systemie ŹRÓDŁO SŁOWA służy do:
- **Tłumaczeń**: Automatyczne generowanie wersji językowych (EN, DE, FR, IT, ES) z zachowaniem intencji autora.
- **Baneru Głównego**: Umożliwia dynamiczne dostosowanie głównej wiadomości portalu do odbiorców z całego świata.
Wszystkie treści wygenerowane przez AI wymagają weryfikacji przez ludzkiego edytora przed publikacją.

## 6. Zasady Bezpieczeństwa
- Każda operacja finansowa jest rejestrowana w nieusuwalnej księdze (ledger).
- Dostęp do paneli redakcyjnych jest chroniony przez weryfikację 2FA.
- System anty-fraudowy monitoruje próby nadużyć przy zdobywaniu punktów Talent.
- Dane osobowe są chronione, a użytkownik ma możliwość pełnej anonimizacji konta.


---

<!-- ŹRÓDŁO: ZRODLO_SLOWA_DOKUMENTACJA_TECHNICZNA_PELNA.md -->


# ŹRÓDŁO SŁOWA — Dokumentacja Techniczna

## 1. Architektura Systemu
Aplikacja została zbudowana w języku PHP (8.x) bez użycia klasycznego frameworka (tzw. "vanilla PHP"), stosując własną implementację wzorca MVC (Model-View-Controller) oraz Service Layer.

- **Frontend**: Czysty PHP w widokach, CSS (app.css, slowo-system.css), minimalny JS (vanilla).
- **Backend**: System kontrolerów (`app/Controllers`), usług biznesowych (`app/Services`) oraz jądro systemu (`app/Core`).
- **Baza danych**: MySQL/MariaDB, schemat oparty na jednym pliku `zrodlo_slowa.sql`.
- **Integracje**: OpenAI (tłumaczenia i procesy AI), Stripe (płatności i webhooki).

## 2. Kluczowe komponenty Core
- **App.php**: Singleton/Kontener bootujący aplikację, ładujący konfigurację i inicjujący sesję.
- **Router.php**: Obsługuje mapowanie URL na pary Kontroler@Metoda. Wymusza CSRF dla żądań POST.
- **Database.php**: Wrapper na PDO zapewniający bezpieczeństwo (preparowane zapytania) i obsługę transakcji.
- **bootstrap.php**: Zbiór globalnych funkcji pomocniczych do escapowania (e), tłumaczeń (t) i normalizacji URL.

## 3. Przepływy danych i Bezpieczeństwo
- **Bezpieczeństwo**: System chroniony przed SQLi, XSS oraz CSRF. Posiada moduł `AuthSecurityService` do obsługi 2FA i weryfikacji ról.
- **Finanse**: Cała logika finansowa przechodzi przez `LedgerService`, który zapewnia atomowość operacji i spójność sald na trzech sub-kontach (Główne, Zarobkowe, Talent).
- **Języki**: Wielopoziomowe wykrywanie języka z priorytetem dla prefiksu URL. Tłumaczenia interfejsu w JSON, tłumaczenia treści w osobnej tabeli SQL.

## 4. Moduły specjalne
- **AI i OpenAI**: System zleceń AI (`ai_jobs`) z asynchronicznym modelem wykonania. Obsługuje tłumaczenia artykułów i baneru z wykorzystaniem ustrukturyzowanych promptów.
- **Workflow redakcyjny**: Zaawansowany system statusów artykułów (draft -> submitted -> review -> approved -> published) z przypisaniem do konkretnych ról redakcyjnych.
- **Baner Główny**: Dynamiczny moduł zarządzania treścią na stronie głównej z pełnym wsparciem dla wielu języków i edycji AI.

## 5. Utrzymanie i Diagnostyka
System oferuje zestaw skryptów CLI (`scripts/`) do:
- Instalacji i weryfikacji bazy danych (`install.php`).
- Generowania raportów finansowych (`report_finance.php`).
- Skanowania pod kątem fraudów (`fraud_scan.php`).
- Zarządzania SEO i sitemapą.

## 6. Miejsca ryzyka i rekomendacje
1. **Adminer**: Zaleca się usunięcie lub ścisłe zabezpieczenie `public/adminer.php`.
2. **Koszty AI**: Wymagane monitorowanie zużycia tokenów OpenAI, aby uniknąć nieprzewidzianych kosztów.
3. **Refaktoryzacja**: Główne kontrolery (np. `AdminController`) stają się zbyt obszerne i wymagają w przyszłości podziału na mniejsze klasy tematyczne.


---

# Audyty opisowe 01–15


---

<!-- ŹRÓDŁO: AUDYT_01_MAPA_REPO.md -->


# AUDYT 01 — Mapa repozytorium

## 1. Struktura katalogów i ich rola

| Katalog | Rola w systemie |
| --- | --- |
| `app/Controllers` | Obsługa żądań HTTP, walidacja wejść, sterowanie przepływem (MVC - Controller). |
| `app/Services` | Logika biznesowa, operacje na bazie danych, integracje z AI, płatnościami i systemem plików. |
| `app/Core` | Rdzeń systemu: router, obsługa sesji, połączenie z bazą danych, bootowanie aplikacji, CSRF. |
| `app/Models` | Klasy reprezentujące obiekty danych (obecnie tylko `ArticleTranslation`). |
| `app/Middleware` | Filtry żądań (np. autoryzacja, sprawdzanie roli). |
| `app/Payments` | Specyficzna logika płatności, w tym integracja ze Stripe. |
| `config` | Konfiguracja aplikacji: baza danych, AI, parametry SEO, języki i domeny. |
| `database` | Główny schemat bazy danych (`zrodlo_slowa.sql`) oraz zarchiwizowane migracje (jeśli obecne). |
| `public` | Katalog publiczny: `index.php` (punkt wejścia), assety (CSS, JS, obrazy), uploady użytkowników. |
| `resources/lang` | Pliki lokalizacji interfejsu (JSON). |
| `scripts` | Skrypty administracyjne i serwisowe uruchamiane z linii komend (CLI). |
| `views` | Szablony widoków (PHP), podzielone na moduły (admin, author, wallet itp.). |
| `storage` | Pliki generowane przez system: logi, cache, tymczasowe pliki uploadu. |
| `docs` | Dokumentacja techniczna i funkcjonalna projektu. |
| `layout_design` | Pliki pomocnicze związane z projektem graficznym (HTML/CSS statyczne). |

## 2. Pliki istotne dla działania systemu

- `public/index.php`: Główny kontroler wejściowy (Front Controller).
- `app/Core/bootstrap.php`: Inicjalizacja środowiska, autoloadera i stałych.
- `app/Core/App.php`: Główny kontener aplikacji.
- `database/zrodlo_slowa.sql`: Aktualne "źródło prawdy" struktury bazy danych.
- `.env`: Konfiguracja środowiskowa (DB, API Keys, Admin credentials).

## 3. Pliki pomocnicze, testowe i diagnostyczne

- `scripts/install.php`: Instalacja i sprawdzanie spójności bazy danych.
- `scripts/report_finance.php`: Generowanie raportów finansowych (CLI).
- `scripts/test_openai.php`: Diagnostyka połączenia z OpenAI.

## 4. Pliki potencjalnie niebezpieczne lub wrażliwe

- `.env`: Zawiera hasła do bazy i klucze API.
- `public/adminer.php`: (Jeśli obecny) Narzędzie do zarządzania bazą przez WWW - ryzyko przy braku blokady.
- `scripts/install.php`: Możliwość zresetowania bazy danych (`--fresh`).
- `app/Controllers/PaymentWebhookController.php`: Endpointy odbierające powiadomienia o płatnościach.


---

<!-- ŹRÓDŁO: AUDYT_02_MAPA_TRAS.md -->


# AUDYT 02 — Mapa wejść systemu (Trasy)

## 1. Tabela tras i uprawnień

| Metoda | URL | Kontroler | Metoda | Rola użytkownika | CSRF | Moduł | Ryzyko |
| --- | --- | --- | --- | --- | --- | --- | --- |
| GET | `/` | HomeController | index | Publiczny | Nie | Home | Niskie |
| GET | `/jak-zarabiac` | HomeController | economy | Publiczny | Nie | Home | Niskie |
| GET | `/register` | AuthController | showRegister | Publiczny | Nie | Auth | Niskie |
| POST | `/register` | AuthController | register | Publiczny | Tak | Auth | Średnie |
| GET | `/login` | AuthController | showLogin | Publiczny | Nie | Auth | Niskie |
| POST | `/login` | AuthController | login | Publiczny | Tak | Auth | Średnie |
| POST | `/logout` | AuthController | logout | Zalogowany | Tak | Auth | Niskie |
| GET | `/sitemap.xml` | SitemapController | index | Publiczny | Nie | SEO | Niskie |
| GET | `/articles` | ArticleController | index | Publiczny | Nie | Articles | Niskie |
| GET | `/article` | ArticleController | show | Publiczny | Nie | Articles | Niskie |
| POST | `/article/support` | ArticleController | support | Zalogowany | Tak | Articles | Średnie |
| POST | `/article/buy` | ArticleController | buy | Zalogowany | Tak | Articles | Wysokie (Finanse) |
| GET | `/surveys` | SurveyController | index | Zalogowany | Nie | Surveys | Niskie |
| POST | `/survey/submit` | SurveyController | submit | Zalogowany | Tak | Surveys | Średnie |
| GET | `/campaigns` | CampaignController | index | Zalogowany | Nie | Campaigns | Niskie |
| POST | `/campaign/view` | CampaignController | viewAd | Zalogowany | Tak | Campaigns | Średnie |
| POST | `/activity/record` | ActivityController | record | Zalogowany | Tak | Activity | Średnie |
| GET | `/author` | AuthorController | dashboard | Autor | Nie | Authors | Niskie |
| POST | `/author/articles` | AuthorController | storeArticle | Autor | Tak | Authors | Średnie |
| GET | `/reader` | ReaderController | dashboard | Czytelnik | Nie | Reader | Niskie |
| GET | `/wallet` | WalletController | show | Zalogowany | Nie | Wallet | Średnie |
| POST | `/wallet/topup` | WalletTopupController | create | Zalogowany | Tak | Wallet | Wysokie |
| POST | `/stripe/webhook` | StripeWebhookController | handle | Publiczny | Nie* | Payments | Krytyczne |
| POST | `/wallet/transfer/talent-to-pln` | WalletTransferController | talentToPln | Zalogowany | Tak | Wallet | Wysokie |
| POST | `/wallet/payout-request` | WalletController | requestPayout | Zalogowany | Tak | Wallet | Wysokie |
| GET | `/admin` | AdminController | dashboard | Admin | Nie | Admin | Niskie |
| GET | `/admin/articles` | AdminController | articles | Admin / Chief Editor | Nie | Admin | Niskie |
| GET | `/admin/editorial` | AdminController | editorial | Admin / Editor / Publisher | Nie | Admin | Niskie |
| POST | `/admin/articles/status` | AdminController | setArticleStatus | Admin / Chief Editor | Tak | Admin | Średnie |
| POST | `/admin/articles/valuation` | AdminController | setArticleValuation | Admin / Moderator | Tak | Admin | Wysokie |
| GET | `/admin/payouts` | AdminController | payouts | Admin / Accountant | Nie | Payouts | Wysokie |
| GET | `/admin/ai` | AiAdminController | index | Admin | Nie | AI | Średnie |
| GET | `/admin/finance` | FinanceController | report | Admin / Accountant | Nie | Finance | Średnie |

\* `StripeWebhookController` omija CSRF, ale musi weryfikować podpis Stripe (do sprawdzenia w Etapie 11).

## 2. Podsumowanie zabezpieczeń wejść

- **CSRF**: Globalnie wymuszany dla wszystkich żądań `POST` przez `Router` (wywołanie `verify_csrf()`).
- **Autoryzacja**: Realizowana w kontrolerach przez metody `requireAuth`, `requireAdmin`, `requireAdminOrRoles`.
- **Walidacja**: Każda metoda kontrolera powinna walidować dane z `$_POST` i `$_GET`.
- **Publiczny dostęp**: Ograniczony do strony głównej, logowania, rejestracji, publicznych artykułów, sitemapy i webhooków.


---

<!-- ŹRÓDŁO: AUDYT_03_KONTROLERY.md -->


# AUDYT 03 — Kontrolery (Analiza metod)

## 1. Wstęp
Analiza obejmuje 23 kontrolery znajdujące się w `app/Controllers`. Każdy kontroler dziedziczy po `BaseController`, który dostarcza mechanizmy autoryzacji i renderowania widoków.

## 2. Główni aktorzy (Uprawnienia)
- **Publiczny**: Brak wymagań.
- **Zalogowany**: Metoda `requireAuth()`.
- **Autor**: `requireApprovedAuthor()` (Zalogowany + status 'active' + can_write=1).
- **Admin**: `requireAdmin()` (Rola 'admin' w sesji).
- **Role redakcyjne**: `requireAdminOrRoles()` (Specyficzne role: editor, chief_editor, publisher, moderator, proofreader, accountant).

## 3. Przegląd kluczowych kontrolerów

### AccountController.php
Obsługuje ustawienia profilu użytkownika.
- `showSettings()`: Wyświetla formularz ustawień.
- `updateSettings()`: Zapisuje zmiany (email, nazwa wyświetlana, waluta wyświetlania).
- `updateAvatar()`: Obsługuje wgrywanie zdjęcia profilowego.

### AdminController.php
Największy kontroler w systemie, zarządza procesami redakcyjnymi i administracyjnymi.
- `dashboard()`: Główny widok admina z kaflami ról.
- `articles()`: Panel Redaktora Naczelnego (`chief_editor`) - akceptacja nowych tekstów (`submitted`).
- `editorial()`: Panel Edytora/Wydawcy - zarządzanie tekstami do publikacji.
- `editProofreadingArticle()`: Panel Korektora (`proofreader`) - edycja językowa.
- `setArticleValuation()`: Panel Moderatora (`moderator`) - wycena i status premium.
- `mainBanner()`: Zarządzanie banerem głównym.

### ArticleController.php
Publiczna prezentacja treści.
- `index()`: Lista opublikowanych artykułów (z uwzględnieniem kategorii).
- `show()`: Wyświetlanie pojedynczego artykułu. Obsługuje SEO slugi i wersje językowe.
- `buy()`: Zakup artykułu premium (wymaga zalogowania).
- `support()`: Darowizna dla autora (wymaga zalogowania).

### AuthController.php
Zarządzanie sesją i dostępem.
- `register()`, `login()`, `logout()`: Podstawowe operacje.
- `verifyTwoFactorChallenge()`: Obsługa 2FA dla wysokich ról redakcyjnych.
- `forgot()`, `reset()`: Procedura resetowania hasła.

### AuthorController.php
Warsztat pracy autora.
- `dashboard()`: Lista własnych tekstów i status portfela.
- `storeArticle()`, `updateArticle()`: Tworzenie i edycja szkiców.
- `submitArticle()`: Wysłanie tekstu do redakcji (blokuje edycję dla autora).
- `uploadImageAjax()`: Zarządzanie obrazkami w treści.

### FinanceController.php
Nadzór nad pieniędzmi.
- `payments()`: Lista wszystkich wpływów (Stripe, ręczne).
- `ledger()`: Pełna historia operacji na wszystkich portfelach.
- `report()`: Zagregowany raport ekonomii systemu.
- `approveWalletTransfer()`: Zatwierdzanie transferów Talent -> PLN.

### WalletController.php
Interfejs portfela dla użytkownika.
- `show()`: Saldo i historia transakcji.
- `requestPayout()`: Zlecenie wypłaty środków.

## 4. Ryzyka zidentyfikowane w kontrolerach
1. **Złożoność AdminController**: Plik ma prawie 1000 linii, co utrudnia utrzymanie i testowanie.
2. **Duplikacja logiki**: Niektóre mechanizmy (np. `applyPublicLanguageToArticleList`) powtarzają się w `HomeController` i `ArticleController`.
3. **Brak silnej typizacji**: Wiele metod operuje na surowych tablicach z bazy danych bez mapowania na obiekty.
4. **Zależność od globalnych funkcji**: Kontrolery mocno polegają na funkcjach pomocniczych z `bootstrap.php` (np. `t()`, `e()`, `public_language()`).


---

<!-- ŹRÓDŁO: AUDYT_04_SERWISY.md -->


# AUDYT 04 — Serwisy (Logika biznesowa)

## 1. Wstęp
System opiera się na architekturze Service Layer, gdzie kontrolery delegują złożone operacje do dedykowanych klas w `app/Services`. Wykryto ok. 45 serwisów.

## 2. Główne grupy serwisów

### Artykuły i Treść
- **ArticleService.php**: Zarządza cyklem życia artykułu (draft -> submitted -> review -> approved -> published). Obsługuje również wersjonowanie (`article_versions`) i log zdarzeń (`article_events`).
- **ArticleTranslationService.php**: Obsługuje tłumaczenia artykułów. Kluczowe: publiczność wersji językowej zależy od statusu artykułu głównego, a nie od statusu tłumaczenia.
- **ArticleSeoService.php**: Buduje metatagi SEO, obsługuje canonicale i powiązania między językami.
- **CategoryService.php**: Zarządzanie hierarchią kategorii.

### Użytkownicy i Bezpieczeństwo
- **UserService.php**: Zarządzanie kontami, uprawnieniami (`can_write`, `wallet_enabled`) i statusami. Obsługuje również blokady wysyłania tekstów dla autorów.
- **AuthService.php**: Logika rejestracji i uwierzytelniania.
- **AuthSecurityService.php**: Weryfikacja 2FA, potwierdzanie e-mail i sprawdzanie gotowości "wysokich ról" do dostępu do paneli administracyjnych.
- **FraudGuardService.php**: System anty-fraudowy monitorujący anomalie w aktywnościach.

### Finanse i Ekonomia
- **LedgerService.php**: "Księga główna" systemu. Każda operacja finansowa (PLN, Talent) musi przejść przez metodę `post()`. Obsługuje sub-konta `main` (środki wpłacone) i `slowo` (środki zarobione).
- **WalletService.php**: Podstawowe operacje na portfelach.
- **TalentService.php**: Przyznawanie punktów Talent za zweryfikowaną aktywność (aktywna obecność, czytanie, polecenie aplikacji i opublikowane opinie lub polemiki).
- **WalletTopupService.php**: Procesowanie doładowań (m.in. ze Stripe).
- **ArticleEconomyService.php**: Obsługa zakupu artykułów premium (wersjonowany podział 40/40/20 w jednej transakcji).

### AI (OpenAI)
- **AiFoundationService.php**: Centralne zarządzanie ustawieniami AI, planowaniem zadań i logowaniem zdarzeń AI.
- **OpenAiClient.php**: Niskopoziomowy klient API OpenAI.
- **TranslationAiService.php**: Wysokopoziomowa usługa do wykonywania tłumaczeń przez AI.

## 3. Ryzyka zidentyfikowane w serwisach
1. **Transakcyjność**: Nie wszystkie złożone operacje są objęte transakcjami DB (choć kluczowe finansowe tak).
2. **Hardcoded IDs**: W niektórych miejscach (np. role) występują sztywne ciągi znaków zamiast stałych lub konfiguracji (choć `RoleService` to poprawia).
3. **Złożoność SQL**: Niektóre zapytania w serwisach są bardzo rozbudowane (szczególnie te pobierające artykuły z mediami i autorami), co może wpływać na wydajność przy dużej skali.
4. **Zależność od AI**: System mocno polega na OpenAI do tłumaczeń; brak mechanizmu fallbacku na inne silniki w razie awarii API (poza ręczną edycją).

## 4. Wybrane metody - Szczegóły

### LedgerService::post()
Kluczowa metoda dla bezpieczeństwa finansowego. Automatycznie aktualizuje salda i zapisuje historię w `wallet_transactions` z kluczem idempotencji.

### ArticleService::getAccessGrant()
Decyduje o tym, czy użytkownik widzi treść premium. Sprawdza tryb artykułu, autorstwo, rolę admina oraz aktywne granty czasowe.


---

<!-- ŹRÓDŁO: AUDYT_05_CORE.md -->


# AUDYT 05 — Core systemu (Rdzeń aplikacji)

## 1. Boot aplikacji (`App.php`)
Aplikacja jest inicjowana przez statyczną metodę `App::boot($rootPath)`. Proces obejmuje:
- Załadowanie konfiguracji z katalogu `config/`.
- Inicjalizację sesji PHP.
- Nawiązanie połączenia z bazą danych przez wrapper `Database`.
- Ustawienie nagłówka `Content-Type` na UTF-8.

## 2. Baza danych (`Database.php`)
System używa uproszczonego wrappera na PDO, który:
- Zapewnia leniwe połączenie (lazy connection).
- Wymusza tryb raportowania błędów jako wyjątki.
- Obsługuje transakcje przez metodę `transaction(callable $fn)`, która wspiera zagnieżdżone wywołania w ramach tej samej transakcji.
- Dostarcza metody pomocnicze: `query()`, `one()` (pojedynczy rekord), `all()` (wszystkie rekordy), `cell()` (pojedyncza wartość), `insert()` (zwraca lastInsertId).

## 3. Router i Mapa Tras (`Router.php`)
Router jest minimalistyczny i oparty na mapowaniu tablicy:
- Obsługuje metody `GET` i `POST`.
- **Wymusza CSRF dla każdego żądania POST** automatycznie przed przekazaniem sterowania do kontrolera.
- Nie obsługuje obecnie parametrów dynamicznych w samym URL (np. `/article/{id}`) — parametry przekazywane są przez `$_GET` (np. `/article?id=5`) lub normalizowane przez SEO Rewrite.

## 4. Sesja i CSRF (`Session.php`, `bootstrap.php`)
- **Sesja**: Trzymana w standardowym mechanizmie PHP, rozszerzona o obsługę komunikatów błyskawicznych (flash messages).
- **CSRF**: Token generowany przy pierwszej potrzebie (`csrf_token()`) i weryfikowany globalnie dla POST. Dostępny pomocnik `csrf_field()` do generowania ukrytego pola formularza.

## 5. Helpery i Globalne Funkcje (`bootstrap.php`)
Plik `bootstrap.php` zawiera kluczowe funkcje używane w całym systemie:
- `e($value)`: Skrót do `htmlspecialchars`, zapobiega XSS.
- `t($key, $lang)`: Tłumaczenie kluczy tekstowych.
- `public_language()`: Wykrywa bieżący język (z URL, sesji lub domeny).
- `public_normalized_uri()`: Normalizuje URI na potrzeby routera.
- `seo_article_rewrite_uri()`: Przekształca przyjazne slugi na zapytania techniczne (np. `/moj-tytul` -> `/article?id=X&seo_slug=moj-tytul`).

## 6. Widoki (`View.php`)
Prosty silnik szablonów PHP:
- Metoda `render(string $name, array $data)` wypakowuje tablicę `$data` do zmiennych lokalnych.
- Widoki są plikami `.php` w katalogu `views/`.
- Obsługuje layouty (zazwyczaj przez ręczne dołączanie części widoku).

## 7. Ryzyka w warstwie Core
- **Brak DI (Dependency Injection)**: Obiekty są często tworzone ręcznie w kontrolerach, co utrudnia testowanie jednostkowe (choć `BaseController` otrzymuje obiekt `App`).
- **Globalne funkcje**: Duża liczba funkcji w przestrzeni globalnej może prowadzić do konfliktów nazw w przyszłości.
- **Router**: Brak obsługi parametrów w ścieżce (np. `/user/1`) wymusza używanie parametrów query, co jest mniej eleganckie (choć system nadrabia to mechanizmem SEO Rewrite).


---

<!-- ŹRÓDŁO: AUDYT_06_BAZA_DANYCH.md -->


# AUDYT 06 — Baza danych (Struktura i spójność)

## 1. Wstęp
System przeszedł niedawno konsolidację bazy danych. Historyczny mechanizm 57 migracji został zastąpiony pojedynczym plikiem schematu `database/zrodlo_slowa.sql`, który stanowi aktualne "źródło prawdy".

## 2. Główne grupy tabel

### Treści (Artykuły)
- `articles`: Główna tabela artykułów. Zawiera statusy, tryby dostępu (free/paid), wycenę i flagi (is_featured, is_premium).
- `article_translations`: Tłumaczenia artykułów. Kluczowe pola: `article_id`, `language`, `slug`, `title`, `body`.
- `article_versions`: Snapshoty treści artykułów tworzone przy każdej edycji.
- `article_events`: Log zdarzeń związanych z artykułami (np. zmiana statusu, wysłanie do korekty).
- `media`: Załączniki multimedialne do artykułów.

### Finanse (Portfel i Płatności)
- `wallets`: Salda użytkowników. Posiada sub-konta: `main_available_minor` (wpłaty), `slowo_available_minor` (zarobki), `points_balance` (Talent).
- `wallet_transactions`: Szczegółowa historia wszystkich operacji finansowych.
- `payments`: Rejestr wpłat zewnętrznych.
- `payment_orders`: Zamówienia płatności (np. Stripe Checkout).
- `payouts`: Zlecenia wypłat dla autorów.
- `platform_revenues`: Rejestr udziału serwisu i Safety Fund wraz z wersją polityki podziału.

### Użytkownicy i Uprawnienia
- `users`: Dane podstawowe użytkowników, statusy kont, uprawnienia operacyjne (`can_write`, `wallet_enabled`).
- `user_roles`: Powiązanie użytkowników z rolami (wiele ról na jednego użytkownika).
- `auth_login_events`: Logowanie zdarzeń bezpieczeństwa (próby logowania, 2FA).

### AI i Narzędzia
- `ai_jobs`: Zadania zlecone silnikom AI (np. tłumaczenia).
- `ai_job_events`: Log postępów zadań AI.
- `ai_prompt_templates`: Szablony promptów dla OpenAI.
- `settings`: Ogólna konfiguracja systemowa w formacie klucz-wartość.

### Aktywność i Zaangażowanie
- `surveys`, `survey_questions`, `survey_responses`: System ankiet z nagrodami.
- `campaigns`, `campaign_events`: System kampanii reklamowych i PPV.
- `activity_reward_rules`, `activity_reward_logs`: Reguły i logi przyznawania bonusów za aktywność.

## 3. Spójność i Ryzyka
1. **Klucze obce**: Większość relacji jest zdefiniowana na poziomie bazy danych, co zapewnia spójność referencyjną.
2. **Kodowanie**: Cała baza używa `utf8mb4_unicode_ci`, co jest poprawne dla obsługi wielu języków i znaków specjalnych.
3. **Redundancja**: Tabela `wallets` zawiera zarówno nowe pola sub-kont, jak i stare pola (`available_minor`), co wynika z etapowej migracji systemu finansowego. Należy zachować ostrożność przy ich używaniu.
4. **Wydajność**: Brak indeksów na niektórych polach wyszukiwania (np. `meta_json` w `wallet_transactions` — choć to pole typu JSON).

## 4. Tabela "źródła prawdy"
Wszystkie aktualne tabele są opisane w pliku: `database/zrodlo_slowa.sql`. Instalacja systemu na nowym środowisku odbywa się za pomocą skryptu `php scripts/install.php`, który automatycznie ładuje ten schemat.


---

<!-- ŹRÓDŁO: AUDYT_07_WIDOKI_FORMULARZE.md -->


# AUDYT 07 — Widoki i formularze

## 1. Architektura widoków
System używa czystych plików PHP jako szablonów widoków. 
- Główny layout: `views/layouts/main.php`.
- Widoki podzielone na katalogi tematyczne: `admin/`, `author/`, `articles/`, `auth/`, `wallet/` itp.
- Reużywalne komponenty w `views/partials/` (np. przełącznik języków).

## 2. Bezpieczeństwo formularzy
- **CSRF**: Formularze konsekwentnie używają funkcji `csrf_field()` do generowania tokenów zabezpieczających.
- **Metoda POST**: Wszystkie operacje zmieniające stan systemu (zapis artykułu, płatność, zmiana roli) realizowane są metodą POST.
- **XSS**: Dane wyświetlane w widokach są escapowane za pomocą pomocnika `e()`, co minimalizuje ryzyko ataków typu Cross-Site Scripting.
- **Upload plików**: Formularze do wgrywania mediów (`enctype="multipart/form-data"`) znajdują się w panelu autora (artykuły) i ustawieniach konta (avatar).

## 3. Kluczowe formularze w systemie

| Widok | Opis formularza | Cel biznesowy |
| --- | --- | --- |
| `auth/register.php` | Rejestracja autora | Pozyskiwanie nowych twórców treści. |
| `author/create_article.php` | Nowy tekst | Tworzenie treści (tytuł, lead, body, obrazek). |
| `articles/show.php` | Zakup/Wsparcie | Monetyzacja treści i bezpośrednie wsparcie autora. |
| `admin/settings.php` | Ustawienia systemowe | Konfiguracja limitów, stawek i reguł AI. |
| `admin/main_banner.php` | Edytor baneru | Zarządzanie główną ekspozycją na stronie głównej. |
| `wallet/show.php` | Wypłata środków | Realizacja zarobków przez autorów. |

## 4. Wytyczne redakcyjne w UI
- **Pola tekstowe**: Większość formularzy redakcyjnych używa standardowych pól `textarea` lub prostych edytorów.
- **Podgląd**: System oferuje podgląd prywatny dla autorów i redakcji przed publikacją.
- **Języki**: Formularze edycji tłumaczeń są odseparowane od edycji tekstu źródłowego, co zapobiega pomyłkom.

## 5. Ryzyka i rekomendacje
1. **Brak edytora WYSIWYG**: Treści artykułów są edytowane w surowym tekście/HTML, co może być trudne dla mniej technicznych autorów.
2. **Walidacja po stronie klienta**: Wiele formularzy polega głównie na walidacji serwerowej; dodanie walidacji JS mogłoby poprawić UX.
3. **Złożone formularze Admina**: Niektóre ekrany (np. `admin/ai.php`) zawierają bardzo dużą liczbę pól konfiguracyjnych, co zwiększa ryzyko błędu przy zapisie.


---

<!-- ŹRÓDŁO: AUDYT_08_JEZYKI_TLUMACZENIA_SEO.md -->


# AUDYT 08 — Języki, tłumaczenia i SEO

## 1. Wykrywanie języka (`_lang` vs `interface_language`)
System posiada wielowarstwowy mechanizm wykrywania bieżącego języka:
1. **Prefiks URL** (np. `/en/`, `/de/`) — najwyższy priorytet, źródło prawdy dla renderowania strony.
2. **Parametr Query** (np. `?lang=de`).
3. **POST / JSON** (np. pole `_lang` w formularzu).
4. **Domena** — mapowanie hosta na konkretny język (zdefiniowane w `config/sites.php`).
5. **Sesja (`interface_language`)** — używana jako fallback głównie dla żądań POST/AJAX, aby zachować ciągłość językową przy operacjach w tle.

## 2. Tłumaczenia interfejsu
- Klucze tłumaczeń przechowywane są w `resources/lang/public.json`.
- Za ładowanie i serwowanie tłumaczeń odpowiada `PublicTranslationService`.
- Funkcja pomocnicza `t($key, $lang)` pozwala na łatwy dostęp do fraz w widokach i kontrolerach.

## 3. Tłumaczenia artykułów i AI
- Tłumaczenia artykułów trzymane są w osobnej tabeli `article_translations`.
- **Wersja źródłowa (PL)**: Zawsze znajduje się w głównej tabeli `articles`.
- **Wersje obce**: Generowane automatycznie przez `TranslationAiService` (OpenAI) lub edytowane ręcznie.
- **Statusy**: Tłumaczenia mają własne statusy (draft, ai_draft, approved, published), ale ich publiczna widoczność jest ściśle powiązana ze statusem głównego artykułu.

## 4. SEO i przyjazne adresy
- **Slugi**: Każda wersja językowa ma własny slug (np. `/moj-tytul` vs `/en/my-title`).
- **SEO Rewrite**: Funkcja `seo_article_rewrite_uri` w `bootstrap.php` odpowiada za mapowanie przyjaznych adresów na techniczne parametry kontrolera.
- **Metatagi**: `ArticleSeoService` generuje tagi `canonical` oraz `alternate hreflang`, co zapobiega duplikatom treści (duplicate content) i pomaga wyszukiwarkom w indeksowaniu wersji językowych.
- **Sitemap**: Dedykowany kontroler `SitemapController` generuje dynamiczną mapę strony w formacie XML, zawierającą wszystkie opublikowane artykuły we wszystkich językach.

## 5. Ryzyka i rekomendacje
1. **Kodowanie UTF-8**: W przeszłości występowały problemy z kodowaniem znaków w tłumaczeniach niemieckich. Zaleca się audyt wszystkich punktów styku z bazą pod kątem wymuszania `SET NAMES utf8mb4`.
2. **Duplikacja fraz**: Niektóre frazy są zakodowane na sztywno w kodzie (np. nazwy brandu w różnych językach w `config/languages.php`). Sugeruje się przeniesienie ich do plików JSON.
3. **Wydajność SEO Rewrite**: Mechanizm rewrite jest uruchamiany przy każdym żądaniu. Przy tysiącach artykułów wyszukiwanie sluga w bazie może wymagać optymalizacji (np. cacheowanie mapy slugów).


---

<!-- ŹRÓDŁO: AUDYT_09_ARTYKULY_REDAKCJA_KOREKTA.md -->


# AUDYT 09 — Artykuły, redakcja, korekta, wydawca

## 1. Cykl życia artykułu (Stany)
Artykuł w systemie przechodzi przez następujące statusy:
1. **draft**: Szkic autora, widoczny tylko dla niego i administracji.
2. **submitted**: Tekst przesłany do redakcji, zablokowany do edycji dla autora.
3. **review**: Tekst w trakcie analizy przez Redaktora Głównego.
4. **approved**: Zaakceptowany merytorycznie, przekazany do Wydawcy/Korektora.
5. **rejected**: Odrzucony przez redakcję (wraca do autora jako szkic).
6. **published**: Opublikowany publicznie na stronie.
7. **archived**: Wycofany z publikacji, dostępny w archiwum.

## 2. Role redakcyjne i uprawnienia

### Redaktor Główny (`chief_editor`)
- Odpowiada za pierwszy etap selekcji.
- Obsługuje przejście: `submitted → review → approved` lub `rejected`.
- Ma pełny wgląd w nowo nadesłane materiały.

### Wydawca (`publisher`)
- Zarządza artykułami ze statusem `approved`.
- Decyduje o: kolejności wyświetlania (`display_order`), wadze redakcyjnej (`editorial_weight`), wyróżnieniu (`is_featured`) i ostatecznym momencie publikacji (`published_at`).
- Może archiwizować teksty.

### Moderator (`moderator`)
- Skupia się na aspektach biznesowych artykułu.
- Ustawia: tryb dostępu (`access_mode`: free/paid), cenę (`price_minor`), status premium (`is_premium`).
- Decyduje o dopuszczeniu tekstu do tłumaczenia AI.

### Korektor (`proofreader`)
- Widzi artykuły tak jak Wydawca.
- **Uprawnienia ograniczone**: może edytować wyłącznie `lead` i `body`.
- Nie może zmieniać tytułu, zdjęcia, danych finansowych ani statusu publikacji.
- Po zapisaniu zmian przez Korektora, system generuje zdarzenie `proofread_saved` (informacja **KOREKTA** dla Wydawcy i Autora).

## 3. Workflow tłumaczeń
1. Artykuł źródłowy (zazwyczaj PL) zostaje zatwierdzony.
2. Moderator/Edytor zleca tłumaczenie AI (OpenAI).
3. Powstaje rekord w `article_translations` ze statusem `ai_draft`.
4. Edytor przegląda tłumaczenie, nanosi poprawki i zmienia status na `approved` (w ramach wersji językowej).
5. Publiczna widoczność wersji językowej następuje automatycznie, gdy artykuł główny ma status `published` i dana wersja jest oznaczona jako gotowa.

## 4. Ryzyka i rekomendacje
1. **Blokada edycji**: Autor traci możliwość poprawy tekstu po `submit`. Warto rozważyć mechanizm "prośby o wycofanie" lub automatycznego odblokowania przy statusie `rejected`.
2. **Korekta a Tłumaczenia**: Zmiany wprowadzone przez Korektora w tekście źródłowym nie aktualizują automatycznie już istniejących tłumaczeń. Wymaga to manualnej interwencji lub ponownego zlecenia AI.
3. **Powiadomienia**: System posiada kolejkę maili, ale warto upewnić się, że każde przejście stanu (szczególnie `approved` i `rejected`) generuje powiadomienie dla autora.


---

<!-- ŹRÓDŁO: AUDYT_10_BANER_GLOWNY.md -->


# AUDYT 10 — Baner Główny

## 1. Architektura modułu
Baner Główny na stronie głównej nie jest statycznym elementem kodu, lecz dynamicznym modułem zarządzanym z panelu administratora. Jego struktura jest analogiczna do systemu artykułów:
- **Tabela główna (`main_banners`)**: Przechowuje dane techniczne (slug, ścieżka do obrazka tła, URL przycisku).
- **Tabela tłumaczeń (`main_banner_translations`)**: Przechowuje treści (kicker, tytuł, lead, body, etykieta przycisku) dla poszczególnych języków.

## 2. Zarządzanie treścią
- **Wersja źródłowa**: Redakcja edytuje baner w języku polskim (PL).
- **Tłumaczenia AI**: Dostępny przycisk „Tłumacz baner główny”, który wykorzystuje OpenAI do wygenerowania wersji dla pozostałych aktywnych języków (EN, DE, FR, IT, ES).
- **Edycja ręczna**: Każda wersja językowa może być po przetłumaczeniu ręcznie poprawiona przez administratora.
- **Responsywność**: Tło graficzne jest ładowane z bazy danych, co pozwala na szybką zmianę wizualną bez ingerencji w kod CSS/JS.

## 3. Integracja z AI
- Moduł używa dedykowanego promptu `main_banner_translation`.
- Wynik z OpenAI jest walidowany pod kątem kompletności wszystkich wymaganych pól.
- Procesowanie odbywa się w tle z logowaniem zdarzeń w `ai_jobs`.

## 4. Ryzyka i rekomendacje
1. **Brak podglądu live**: W panelu admina brakuje wizualnego podglądu baneru przed zapisem (WYSIWYG), co utrudnia ocenę długości tekstów na różnych urządzeniach.
2. **Fallback językowy**: Jeśli brak tłumaczenia dla danego języka, system automatycznie wyświetla wersję polską. Jest to poprawne zachowanie, ale warto dodać ostrzeżenie w panelu admina o brakujących wersjach.
3. **Optymalizacja obrazów**: Ścieżka do obrazka jest tekstowa. Należy upewnić się, że wgrywane obrazy są automatycznie optymalizowane (rozmiar, format WebP), aby nie spowalniać ładowania strony głównej.


---

<!-- ŹRÓDŁO: AUDYT_11_BEZPIECZENSTWO.md -->


# AUDYT 11 — Bezpieczeństwo

## 1. Ochrona przed atakami typu Injection
- **SQL Injection**: Wszystkie zapytania do bazy danych przechodzą przez wrapper `Database.php`, który wymusza stosowanie preparowanych zapytań (prepared statements) z bindowaniem parametrów.
- **XSS (Cross-Site Scripting)**: Dane w widokach są konsekwentnie escapowane za pomocą funkcji `e()`. System nie używa domyślnie surowego wyjścia, co minimalizuje ryzyko wstrzyknięcia skryptów.

## 2. Zabezpieczenia sesji i dostępów
- **CSRF (Cross-Site Request Forgery)**: Router automatycznie weryfikuje token CSRF dla każdego żądania typu `POST`. Formularze generują tokeny przez `csrf_field()`.
- **Autoryzacja**: System stosuje rygorystyczne sprawdzanie uprawnień w kontrolerach (`requireAuth`, `requireAdmin`, `requireAdminOrRoles`).
- **Wysokie Role i 2FA**: Użytkownicy z rolami redakcyjnymi/administracyjnymi mają wymuszoną weryfikację e-mail oraz (opcjonalnie/wymuszane w konfiguracji) uwierzytelnianie dwuskładnikowe (2FA).

## 3. Bezpieczeństwo finansowe
- **Idempotencja**: Transakcje portfela w `LedgerService` obsługują klucze idempotencji, co zapobiega podwójnemu księgowaniu tych samych operacji.
- **Webhooki**: Webhooki Stripe są weryfikowane kryptograficznie (podpis HMAC-SHA256) przed przetworzeniem, co uniemożliwia sfałszowanie wpłat.
- **Podział wpływu**: Podział 40/40/20 dla artykułów premium jest realizowany w ramach jednej transakcji bazodanowej w `ArticleEconomyService`.

## 4. Zarządzanie plikami (Upload)
- **Walidacja**: `UploadService` sprawdza typ MIME pliku za pomocą `finfo`, a nie tylko rozszerzenie.
- **Optymalizacja**: Wszystkie wgrywane obrazy są konwertowane do formatu WebP i (opcjonalnie) skalowane, co usuwa potencjalnie niebezpieczne metadane (EXIF) z plików źródłowych.

## 5. Ryzyka produkcyjne
- **Adminer**: Obecność pliku `public/adminer.php` w repozytorium stanowi krytyczne ryzyko, jeśli nie zostanie usunięty lub silnie zabezpieczony (np. przez `.htaccess` lub IP allowlist) na produkcji.
- **Skrypty CLI**: Skrypty w katalogu `scripts/` powinny być zablokowane przed dostępem przez serwer WWW (zazwyczaj katalog ten znajduje się poza `public/`, co jest dobrą praktyką).
- **Zmienne środowiskowe**: Plik `.env` zawiera klucze API AI i Stripe. Należy upewnić się, że nie jest on serwowany publicznie.

## 6. Lista Ryzyk (Skrócona)
| ID | Moduł | Typ ryzyka | Opis | Priorytet |
| --- | --- | --- | --- | --- |
| R1 | Baza danych | SQLi/Auth | public/adminer.php widoczny w repo | KRYTYCZNE |
| R2 | Finanse | Nadpłata | Ryzyko dublowania manualnych wpłat bez klucza idempotencji | ŚREDNIE |
| R3 | AI | Koszty | Brak limitów wydatków na poziomie API (tylko limity systemowe) | ŚREDNIE |
| R4 | Upload | Wyciek ścieżki | Ujawnianie pełnych ścieżek fizycznych w błędach (APP_DEBUG=true) | NISKIE |


---

<!-- ŹRÓDŁO: AUDYT_12_FINANSE_PORTFEL_TALENT.md -->


# AUDYT 12 — Finanse, Portfel i Talent

## 1. Struktura Portfela
System portfela w ŹRÓDLE SŁOWA jest podzielony na trzy główne obszary logiczne (sub-konta) w ramach tabeli `wallets`:
- **Konto Główne (`main_available_minor`)**: Środki wpłacone przez użytkownika (np. przez Stripe), przeznaczone na zakupy artykułów premium.
- **Konto Zarobkowe (`slowo_available_minor`)**: Środki zarobione przez autora ze sprzedaży tekstów, darowizn oraz bonusów wymienionych na PLN.
- **Punkty Talent (`points_balance`)**: Kapitał społeczny użytkownika, zdobywany za aktywność. Możliwy do wymiany na PLN według zmiennego kursu.

## 2. Mechanizm monetyzacji (40/40/20)
Przy zakupie artykułu premium (`ArticleEconomyService`):
1. Od kupującego pobierana jest kwota z Konta Głównego.
2. 40% kwoty trafia na Konto Zarobkowe autora.
3. 40% kwoty trafia do Serwisu, a 20% na wydzielone saldo Safety Fund w tej samej księdze.
4. Całość operacji odbywa się w ramach jednej transakcji bazy danych.

## 3. Punkty Talent i Bonusy
System nagradza użytkowników za aktywność (`TalentService`):
- **Bonusy gotowe**: Rejestracja z trwałym jobem, potwierdzona aktywna wizyta i zweryfikowane przeczytanie.
- **Bonusy za treść**: Zweryfikowane przeczytanie artykułu oraz pierwsza faktyczna publikacja podpisanej opinii lub polemiki przez Redakcję.
- **Ankiety**: PLN jest snapshotowane w konkretnej odpowiedzi, a TT pochodzi z osobnej aktywnej reguły `survey_reward`; brak TT nie blokuje należnych PLN.
- **Reguły wstrzymane**: Samo logowanie oraz niepotwierdzone zdarzenia reklamowe i społecznościowe nie są aktywnymi źródłami nagród.
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
| Zakup tekstu | Konto Główne (Kupujący) | Konto Zarobkowe (Autor) | 40% Serwis / 20% Safety Fund | Stan konta |
| Bonus aktywności | System | Punkty Talent | 0% | Limity dzienne |
| Transfer TT -> PLN | Punkty Talent | Konto Zarobkowe | Zależna | Zgoda admina |
| Wypłata | Konto Zarobkowe | Konto Bankowe | 0% | Minimalna kwota |

\* Prowizja operatora Stripe jest kosztem zewnętrznym i nie jest uwzględniana w saldzie portfela użytkownika.


---

<!-- ŹRÓDŁO: AUDYT_13_AI_OPENAI.md -->


# AUDYT 13 — AI i OpenAI

## 1. Konfiguracja AI
System jest przygotowany do szerokiej integracji z modelami językowymi, z domyślnym wsparciem dla OpenAI. Konfiguracja znajduje się w `config/ai.php` oraz jest nadpisywalna z poziomu bazy danych (`settings`).
- **Klucz API**: Przechowywany w `.env` (`OPENAI_API_KEY`).
- **Model**: Domyślnie `gpt-4.1-mini` lub nowszy, konfigurowalny oddzielnie dla tłumaczeń i audytu.
- **Parametry**: Możliwość ustawienia `temperature`, `max_tokens` oraz limitów dziennych zapytań.

## 2. Architektura procesów AI
Zadania AI nie są wykonywane synchronicznie w głównym wątku żądania (co mogłoby powodować timeouty), lecz są rejestrowane jako "zadania" (`ai_jobs`):
1. **Planowanie**: Administrator lub system tworzy rekord zadania.
2. **Kolejkowanie**: Zadanie otrzymuje status `planned` lub `queued`.
3. **Wykonanie**: Po wywołaniu API OpenAI, status zmienia się na `running`, a po otrzymaniu poprawnego wyniku na `completed`.
4. **Logowanie**: Każdy etap i ewentualne błędy są zapisywane w `ai_job_events`.

## 3. Moduł Tłumaczeń AI
Najbardziej zaawansowana część integracji AI, obsługująca wielojęzyczność artykułów i baneru:
- **TranslationPromptBuilder**: Buduje złożone prompty systemowe i użytkownika, przekazując kontekst kulturowy, religijny i redakcyjny (zasada: "nie poprawiaj autora ideologicznie").
- **Structured Output**: System wymusza na AI zwracanie wyników w formacie JSON zgodnym ze schematem bazy danych, co pozwala na automatyczne wypełnianie pól tytułu, leadu i treści.
- **Review Workflow**: Tłumaczenia AI trafiają najpierw do statusu `ai_draft`, wymagając zatwierdzenia przez ludzkiego edytora.

## 4. Narzędzia administracyjne
Dedykowany panel `admin/ai` pozwala na:
- Monitorowanie budżetu AI i liczby zużytych tokenów.
- Testowanie połączenia z OpenAI.
- Globalną zmianę "dyspozycji dla tłumacza" (instrukcji redakcyjnych dla AI).
- Przegląd historii wszystkich wywołań API.

## 5. Ryzyka i rekomendacje
1. **Koszty API**: System nie posiada twardego limitu "kill-switch" na poziomie kosztów dolarowych w OpenAI, polega jedynie na limitach liczby zadań. Warto dodać monitorowanie kosztów w czasie rzeczywistym.
2. **Błędy formatowania**: Mimo stosowania `Structured Output`, modele AI czasem zwracają błędny JSON przy bardzo długich tekstach. `AiFoundationService` powinien posiadać mechanizm automatycznej ponownej próby (retry) lub naprawy JSON.
3. **Prywatność**: Dane artykułów przesyłane są do zewnętrznego dostawcy (OpenAI). Należy upewnić się, że polityka prywatności serwisu informuje o tym użytkowników/autorów.


---

<!-- ŹRÓDŁO: AUDYT_14_SKRYPTY.md -->


# AUDYT 14 — Skrypty diagnostyczne i utrzymaniowe

## 1. Wstęp
System posiada rozbudowany zestaw skryptów CLI (Command Line Interface) ułatwiających instalację, diagnostykę i utrzymanie systemu. Skrypty znajdują się w katalogu `scripts/` i powinny być uruchamiane wyłącznie z poziomu konsoli.

## 2. Kluczowe skrypty systemowe
| Skrypt | Cel | Bezpieczeństwo |
| --- | --- | --- |
| `install.php` | Główny skrypt instalacyjny. Obsługuje flagi `--fresh` (reset bazy) oraz `--check` (weryfikacja spójności). | **WRAŻLIWY** (ryzyko utraty danych) |
| `report_finance.php` | Generuje szczegółowy raport finansowy (salda, wypłaty, przychody) w formacie tekstowym i JSON. | Bezpieczny |
| `fraud_scan.php` | Uruchamia skaner anty-fraudowy w celu wykrycia anomalii w aktywności użytkowników. | Bezpieczny |
| `reset_admin_password.php` | Pozwala na awaryjną zmianę hasła administratora z poziomu CLI. | Bezpieczny (wymaga dostępu do serwera) |

## 3. Skrypty SEO i Językowe
- `generate_static_sitemap.php`: Generuje plik sitemap.xml dla wyszukiwarek.
- `seo_fix_short_urls_and_slugs.php`: Naprawia i generuje brakujące slugi dla wersji językowych.
- `zrodlo_missing_translation_keys_scan.php`: Skanuje widoki i kontrolery w poszukiwaniu brakujących kluczy w plikach JSON tłumaczeń.

## 4. Skrypty diagnostyczne i testowe
- `zrodlo_stage14_final_check.php`: Kompleksowy test poprawności działania kluczowych modułów systemu po aktualizacji.
- `zrodlo_currency_rate_check.php`: Weryfikuje poprawność pobierania i przeliczania kursów walut (NBP).
- `test_openai.php`: Sprawdza poprawność połączenia z API OpenAI i uprawnienia klucza.

## 5. Ryzyka i rekomendacje
1. **Dostęp publiczny**: Skrypty CLI nie powinny być dostępne przez serwer WWW. Należy upewnić się, że konfiguracja serwera (np. `.htaccess` lub Nginx config) blokuje dostęp do katalogu `scripts/`.
2. **Skrypty "Legacy"**: W katalogu znajduje się wiele skryptów z przedrostkiem `apply_migration_` lub `zrodlo_stage_`. Po zakończeniu fazy wdrożeniowej powinny one zostać zarchiwizowane lub usunięte, aby utrzymać porządek.
3. **Logowanie operacji**: Nie wszystkie skrypty logują swoje działania do plików w `storage/logs/`. Zaleca się dodanie spójnego mechanizmu logowania dla skryptów zmieniających dane w bazie (np. `reset_database.php`).


---

<!-- ŹRÓDŁO: AUDYT_15_TESTY_RECZNE.md -->


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
- [ ] **Zakup Artykułu**: Czy zakup tekstu premium odejmuje środki kupującemu i atomowo księguje 40% autorowi, 40% serwisowi i 20% Safety Fund?
- [ ] **Wypłata**: Czy zlecenie wypłaty rezerwuje środki w portfelu autora?
- [ ] **Bonusy**: Czy przeczytanie artykułu przez zalogowanego użytkownika nalicza punkty Talent?

## 6. Scenariusze Administracyjne
- [ ] **Role**: Czy odebranie roli admina skutkuje natychmiastowym brakiem dostępu do `/admin`?
- [ ] **Ustawienia AI**: Czy zmiana modelu w ustawieniach jest honorowana przy kolejnych zadaniach AI?
- [ ] **Anulowanie użytkownika**: Czy procedura anonimizacji usuwa dane wrażliwe (email, nazwa), zachowując spójność finansową?


---

# Tabele audytowe CSV


---

## AUDYT_01_LISTA_PLIKOW.csv

<!-- ŹRÓDŁO: AUDYT_01_LISTA_PLIKOW.csv -->


| FullName | Length | LastWriteTime |
| --- | --- | --- |
| X:\zrodlo-slowa\.env | 1078 | 2.06.2026 23:25:43 |
| X:\zrodlo-slowa\.env.example | 1107 | 2.06.2026 23:26:09 |
| X:\zrodlo-slowa\.env.install.example | 496 | 2.06.2026 23:26:11 |
| X:\zrodlo-slowa\.git | 37 | 1.06.2026 13:55:23 |
| X:\zrodlo-slowa\.gitignore | 86 | 1.06.2026 11:50:12 |
| X:\zrodlo-slowa\.output.txt | 30769 | 2.06.2026 23:34:46 |
| X:\zrodlo-slowa\composer.json | 26 | 1.06.2026 11:50:12 |
| X:\zrodlo-slowa\composer.lock | 3521 | 30.05.2026 18:29:08 |
| X:\zrodlo-slowa\README.md | 1047 | 30.05.2026 16:57:10 |
| X:\zrodlo-slowa\repo.zip | 5057230 | 2.06.2026 23:27:39 |
| X:\zrodlo-slowa\.idea\misc.xml | 284 | 28.05.2026 09:42:07 |
| X:\zrodlo-slowa\.idea\vcs.xml | 185 | 2.06.2026 06:59:33 |
| X:\zrodlo-slowa\.idea\workspace.xml | 4741 | 2.06.2026 09:13:47 |
| X:\zrodlo-slowa\.idea\inspectionProfiles\profiles_settings.xml | 174 | 28.05.2026 07:15:14 |
| X:\zrodlo-slowa\app\Controllers\AccountController.php | 7588 | 1.06.2026 12:41:26 |
| X:\zrodlo-slowa\app\Controllers\AccountSecurityController.php | 2717 | 28.05.2026 06:04:08 |
| X:\zrodlo-slowa\app\Controllers\ActivityController.php | 1482 | 27.05.2026 18:50:18 |
| X:\zrodlo-slowa\app\Controllers\AdminArticleTranslationController.php | 9516 | 1.06.2026 09:05:07 |
| X:\zrodlo-slowa\app\Controllers\AdminController.php | 41919 | 2.06.2026 06:01:38 |
| X:\zrodlo-slowa\app\Controllers\AiAdminController.php | 5302 | 1.06.2026 09:08:47 |
| X:\zrodlo-slowa\app\Controllers\ArticleController.php | 15326 | 1.06.2026 15:18:10 |
| X:\zrodlo-slowa\app\Controllers\AuthController.php | 8789 | 30.05.2026 16:20:00 |
| X:\zrodlo-slowa\app\Controllers\AuthorController.php | 11003 | 1.06.2026 19:19:38 |
| X:\zrodlo-slowa\app\Controllers\BaseController.php | 9074 | 30.05.2026 16:20:00 |
| X:\zrodlo-slowa\app\Controllers\CampaignController.php | 2896 | 28.05.2026 09:41:38 |
| X:\zrodlo-slowa\app\Controllers\DonationController.php | 965 | 27.05.2026 12:32:42 |
| X:\zrodlo-slowa\app\Controllers\FinanceController.php | 15656 | 2.06.2026 23:32:21 |
| X:\zrodlo-slowa\app\Controllers\HomeController.php | 4909 | 2.06.2026 05:43:50 |
| X:\zrodlo-slowa\app\Controllers\PaymentWebhookController.php | 580 | 27.05.2026 09:33:29 |
| X:\zrodlo-slowa\app\Controllers\ReaderController.php | 354 | 27.05.2026 08:54:57 |
| X:\zrodlo-slowa\app\Controllers\SitemapController.php | 1430 | 1.06.2026 10:45:18 |
| X:\zrodlo-slowa\app\Controllers\StripeWebhookController.php | 4644 | 28.05.2026 20:43:40 |
| X:\zrodlo-slowa\app\Controllers\SurveyController.php | 2063 | 28.05.2026 06:28:58 |
| X:\zrodlo-slowa\app\Controllers\WalletController.php | 4046 | 30.05.2026 15:39:26 |
| X:\zrodlo-slowa\app\Controllers\WalletTopupController.php | 3097 | 30.05.2026 14:52:33 |
| X:\zrodlo-slowa\app\Controllers\WalletTransferController.php | 857 | 30.05.2026 15:39:26 |
| X:\zrodlo-slowa\app\Core\App.php | 1286 | 30.05.2026 12:44:52 |
| X:\zrodlo-slowa\app\Core\bootstrap.php | 21489 | 1.06.2026 19:34:00 |
| X:\zrodlo-slowa\app\Core\Database.php | 2137 | 1.06.2026 11:50:12 |
| X:\zrodlo-slowa\app\Core\Router.php | 926 | 27.05.2026 08:54:56 |
| X:\zrodlo-slowa\app\Core\Session.php | 1067 | 30.05.2026 14:55:28 |
| X:\zrodlo-slowa\app\Core\SlowoSnajperConfig.php | 8689 | 28.05.2026 06:38:30 |
| X:\zrodlo-slowa\app\Core\View.php | 725 | 27.05.2026 08:54:56 |
| X:\zrodlo-slowa\app\Models\ArticleTranslation.php | 780 | 30.05.2026 09:03:34 |
| X:\zrodlo-slowa\app\Payments\PaymentGatewayInterface.php | 326 | 28.05.2026 20:42:00 |
| X:\zrodlo-slowa\app\Payments\PaymentGatewayResult.php | 1054 | 28.05.2026 20:42:00 |
| X:\zrodlo-slowa\app\Payments\Stripe\StripeGateway.php | 6192 | 28.05.2026 20:42:00 |
| X:\zrodlo-slowa\app\Payments\Stripe\StripeWebhookVerifier.php | 2339 | 28.05.2026 20:42:00 |
| X:\zrodlo-slowa\app\Services\ActivityService.php | 2078 | 27.05.2026 18:50:18 |
| X:\zrodlo-slowa\app\Services\ActivityUiHelper.php | 11344 | 30.05.2026 14:44:44 |
| X:\zrodlo-slowa\app\Services\AiFoundationService.php | 22558 | 1.06.2026 08:59:21 |
| X:\zrodlo-slowa\app\Services\ArticleEconomyService.php | 11950 | 1.06.2026 20:57:32 |
| X:\zrodlo-slowa\app\Services\ArticleSeoService.php | 11897 | 1.06.2026 11:29:48 |
| X:\zrodlo-slowa\app\Services\ArticleService.php | 21842 | 1.06.2026 19:19:38 |
| X:\zrodlo-slowa\app\Services\ArticleTranslationService.php | 21489 | 1.06.2026 10:45:18 |
| X:\zrodlo-slowa\app\Services\AuthSecurityService.php | 14569 | 28.05.2026 19:02:54 |
| X:\zrodlo-slowa\app\Services\AuthService.php | 2200 | 27.05.2026 18:19:22 |
| X:\zrodlo-slowa\app\Services\CampaignService.php | 9581 | 28.05.2026 06:27:52 |
| X:\zrodlo-slowa\app\Services\CategoryService.php | 5934 | 30.05.2026 12:50:00 |
| X:\zrodlo-slowa\app\Services\CurrencyRateService.php | 6193 | 30.05.2026 19:29:15 |
| X:\zrodlo-slowa\app\Services\DonationService.php | 1226 | 27.05.2026 12:32:36 |
| X:\zrodlo-slowa\app\Services\EconomyMapService.php | 4721 | 28.05.2026 20:05:55 |
| X:\zrodlo-slowa\app\Services\FraudGuardService.php | 14468 | 28.05.2026 06:27:32 |
| X:\zrodlo-slowa\app\Services\InstallService.php | 15603 | 2.06.2026 23:25:41 |
| X:\zrodlo-slowa\app\Services\LedgerService.php | 14525 | 30.05.2026 14:40:44 |
| X:\zrodlo-slowa\app\Services\MailService.php | 683 | 27.05.2026 09:32:54 |
| X:\zrodlo-slowa\app\Services\MainBannerService.php | 24695 | 2.06.2026 10:23:46 |
| X:\zrodlo-slowa\app\Services\OpenAiClient.php | 8469 | 31.05.2026 08:41:56 |
| X:\zrodlo-slowa\app\Services\PaymentGatewayEventService.php | 2586 | 28.05.2026 20:42:00 |
| X:\zrodlo-slowa\app\Services\PaymentOrderService.php | 3273 | 29.05.2026 22:42:11 |
| X:\zrodlo-slowa\app\Services\PaymentRuntimeConfigService.php | 7795 | 30.05.2026 14:36:21 |
| X:\zrodlo-slowa\app\Services\PaymentService.php | 5838 | 27.05.2026 12:32:29 |
| X:\zrodlo-slowa\app\Services\PayoutMethodService.php | 1444 | 27.05.2026 15:20:02 |
| X:\zrodlo-slowa\app\Services\PayoutService.php | 8468 | 28.05.2026 06:28:20 |
| X:\zrodlo-slowa\app\Services\PublicLanguageService.php | 8618 | 30.05.2026 16:57:10 |
| X:\zrodlo-slowa\app\Services\PublicSiteResolver.php | 10183 | 30.05.2026 16:20:00 |
| X:\zrodlo-slowa\app\Services\PublicTranslationService.php | 3436 | 30.05.2026 13:59:29 |
| X:\zrodlo-slowa\app\Services\RoleService.php | 11058 | 1.06.2026 19:07:54 |
| X:\zrodlo-slowa\app\Services\SeoSlugService.php | 7176 | 1.06.2026 11:22:14 |
| X:\zrodlo-slowa\app\Services\SlowoSnajperService.php | 1323 | 28.05.2026 05:49:00 |
| X:\zrodlo-slowa\app\Services\SupportService.php | 2399 | 27.05.2026 13:46:26 |
| X:\zrodlo-slowa\app\Services\SurveyService.php | 16232 | 30.05.2026 14:40:44 |
| X:\zrodlo-slowa\app\Services\TalentService.php | 8003 | 30.05.2026 14:40:44 |
| X:\zrodlo-slowa\app\Services\TranslationAiService.php | 16844 | 31.05.2026 08:52:44 |
| X:\zrodlo-slowa\app\Services\TranslationPromptBuilder.php | 6534 | 31.05.2026 08:41:54 |
| X:\zrodlo-slowa\app\Services\UploadService.php | 12250 | 1.06.2026 14:57:30 |
| X:\zrodlo-slowa\app\Services\UserDeletionService.php | 15839 | 27.05.2026 23:03:59 |
| X:\zrodlo-slowa\app\Services\UserService.php | 16352 | 1.06.2026 19:19:38 |
| X:\zrodlo-slowa\app\Services\WalletService.php | 1953 | 28.05.2026 05:49:44 |
| X:\zrodlo-slowa\app\Services\WalletTopupPackageService.php | 2516 | 29.05.2026 22:42:41 |
| X:\zrodlo-slowa\app\Services\WalletTopupService.php | 4189 | 28.05.2026 20:42:00 |
| X:\zrodlo-slowa\app\Services\WalletTransferService.php | 10706 | 29.05.2026 22:51:43 |
| X:\zrodlo-slowa\config\ai.php | 1190 | 30.05.2026 09:07:10 |
| X:\zrodlo-slowa\config\app.php | 281 | 27.05.2026 08:54:56 |
| X:\zrodlo-slowa\config\database.php | 326 | 2.06.2026 23:25:57 |
| X:\zrodlo-slowa\config\languages.php | 1038 | 30.05.2026 12:44:47 |
| X:\zrodlo-slowa\config\payments.php | 1604 | 29.05.2026 23:09:03 |
| X:\zrodlo-slowa\config\seo_languages.json | 1080 | 1.06.2026 11:29:48 |
| X:\zrodlo-slowa\config\sites.json | 1169 | 30.05.2026 16:20:00 |
| X:\zrodlo-slowa\config\sites.php | 3227 | 30.05.2026 12:31:16 |
| X:\zrodlo-slowa\config\slowo_snajper.json | 2396 | 30.05.2026 18:20:12 |
| X:\zrodlo-slowa\config\uploads.php | 479 | 1.06.2026 12:41:26 |
| X:\zrodlo-slowa\database\zrodlo_slowa.sql | 80768 | 2.06.2026 23:16:56 |
| X:\zrodlo-slowa\docs\ZRODLO SLOWA opis 2026 uzpelnieie zarbianie.md | 12123 | 27.05.2026 20:15:11 |
| X:\zrodlo-slowa\docs\ZRODLO SLOWA opis 2026.md | 9815 | 27.05.2026 13:15:45 |
| X:\zrodlo-slowa\docs\ZRODLO_SLOWA_WZORZEC_STYLU_UI.md | 8144 | 27.05.2026 16:15:19 |
| X:\zrodlo-slowa\docs\assets\zrodlo-slowa-artykul-premium.png | 1641703 | 27.05.2026 20:04:42 |
| X:\zrodlo-slowa\docs\assets\zrodlo-slowa-logo.png | 931778 | 27.05.2026 20:04:42 |
| X:\zrodlo-slowa\docs\assets\zrodlo-slowa-strona-glowna.png | 1518848 | 27.05.2026 20:04:42 |
| X:\zrodlo-slowa\docs\audyt\AUDYT_01_LISTA_PLIKOW.csv | 0 | 2.06.2026 23:34:58 |
| X:\zrodlo-slowa\public\adminer.php | 508623 | 27.05.2026 19:28:49 |
| X:\zrodlo-slowa\public\index.php | 11299 | 2.06.2026 23:32:15 |
| X:\zrodlo-slowa\public\robots.txt | 69 | 1.06.2026 11:29:48 |
| X:\zrodlo-slowa\public\sitemap.xml | 8713 | 1.06.2026 13:33:00 |
| X:\zrodlo-slowa\public\assets\css\app.css | 81899 | 2.06.2026 10:16:04 |
| X:\zrodlo-slowa\public\assets\css\slowo-system.css | 47029 | 1.06.2026 13:07:40 |
| X:\zrodlo-slowa\public\assets\css\zrodlo-slowa.css | 6474 | 2.06.2026 06:12:48 |
| X:\zrodlo-slowa\public\assets\img\articles\article-state.svg | 632 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\articles\hero-pier.svg | 633 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\articles\thumb-culture.svg | 600 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\articles\thumb-faith.svg | 598 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\articles\thumb-report.svg | 599 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\articles\thumb-society.svg | 608 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\banners\main-banner-editorial-soft-bg.webp | 33988 | 2.06.2026 07:19:04 |
| X:\zrodlo-slowa\public\assets\img\icons\arrow-right.svg | 215 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\icons\premium-lock.svg | 200 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\icons\star.svg | 240 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\icons\wallet.svg | 236 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\logo\logo-horizontal.svg | 559 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\logo\logo-mark.svg | 303 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\img\logo\logo-stack.svg | 655 | 27.05.2026 12:01:02 |
| X:\zrodlo-slowa\public\assets\js\slowo-image-editor.js | 8237 | 1.06.2026 11:07:40 |
| X:\zrodlo-slowa\public\uploads\articles\praca-sens-i-godnosc-czlowieka-a41.webp | 42798 | 1.06.2026 17:03:46 |
| X:\zrodlo-slowa\public\uploads\avatars\user_2\avatar.jpg | 63723 | 1.06.2026 18:07:50 |
| X:\zrodlo-slowa\public\uploads\avatars\user_2\avatar.webp | 32192 | 1.06.2026 17:02:49 |
| X:\zrodlo-slowa\resources\lang\public.json | 130087 | 1.06.2026 19:19:38 |
| X:\zrodlo-slowa\scripts\apply_migration_040.php | 677 | 30.05.2026 19:30:48 |
| X:\zrodlo-slowa\scripts\apply_migration_041.php | 1050 | 30.05.2026 16:48:48 |
| X:\zrodlo-slowa\scripts\apply_stage14_db_compat.php | 9932 | 30.05.2026 11:55:36 |
| X:\zrodlo-slowa\scripts\check_proofreader_panel.php | 2367 | 1.06.2026 15:47:58 |
| X:\zrodlo-slowa\scripts\cleanup_clockwork_only.php | 1156 | 1.06.2026 11:50:12 |
| X:\zrodlo-slowa\scripts\debug_articles_stats.php | 250 | 30.05.2026 15:01:14 |
| X:\zrodlo-slowa\scripts\fraud_scan.php | 1276 | 28.05.2026 06:30:32 |
| X:\zrodlo-slowa\scripts\generate_static_sitemap.php | 2001 | 1.06.2026 11:16:10 |
| X:\zrodlo-slowa\scripts\install.php | 677 | 27.05.2026 09:21:21 |
| X:\zrodlo-slowa\scripts\install_fresh.php | 2259 | 28.05.2026 06:19:12 |
| X:\zrodlo-slowa\scripts\migrate_author_approval.php | 744 | 27.05.2026 16:26:11 |
| X:\zrodlo-slowa\scripts\migrate_user_operational_permissions.php | 2102 | 27.05.2026 15:19:12 |
| X:\zrodlo-slowa\scripts\normalize_webp_upload_names.php | 3929 | 1.06.2026 12:37:20 |
| X:\zrodlo-slowa\scripts\remove_clockwork.php | 882 | 1.06.2026 11:35:10 |
| X:\zrodlo-slowa\scripts\report_content.php | 1534 | 27.05.2026 15:10:06 |
| X:\zrodlo-slowa\scripts\report_finance.php | 6163 | 27.05.2026 13:46:31 |
| X:\zrodlo-slowa\scripts\report_talent.php | 3086 | 27.05.2026 13:10:03 |
| X:\zrodlo-slowa\scripts\reset_admin_password.php | 2032 | 27.05.2026 15:57:21 |
| X:\zrodlo-slowa\scripts\reset_database.php | 2465 | 28.05.2026 06:19:12 |
| X:\zrodlo-slowa\scripts\search_db.php | 369 | 30.05.2026 14:25:35 |
| X:\zrodlo-slowa\scripts\seed_template_articles.php | 8137 | 27.05.2026 15:35:27 |
| X:\zrodlo-slowa\scripts\seo_backfill_translation_slugs.php | 1263 | 1.06.2026 10:45:18 |
| X:\zrodlo-slowa\scripts\seo_check_links.php | 1247 | 1.06.2026 10:54:06 |
| X:\zrodlo-slowa\scripts\seo_fix_short_urls_and_slugs.php | 2866 | 1.06.2026 11:16:10 |
| X:\zrodlo-slowa\scripts\update_currency_rates_nbp.php | 402 | 30.05.2026 13:15:34 |
| X:\zrodlo-slowa\scripts\verify_fix_041.php | 733 | 30.05.2026 16:48:56 |
| X:\zrodlo-slowa\scripts\zrodlo_author_language_check.php | 3900 | 30.05.2026 12:59:48 |
| X:\zrodlo-slowa\scripts\zrodlo_currency_rate_check.php | 3875 | 30.05.2026 14:03:16 |
| X:\zrodlo-slowa\scripts\zrodlo_editorial_texts_panel_check.php | 3227 | 30.05.2026 19:30:48 |
| X:\zrodlo-slowa\scripts\zrodlo_language_context_check.php | 3791 | 30.05.2026 12:45:02 |
| X:\zrodlo-slowa\scripts\zrodlo_missing_translation_keys_scan.php | 6392 | 30.05.2026 13:38:41 |
| X:\zrodlo-slowa\scripts\zrodlo_sites_json_check.php | 6080 | 30.05.2026 12:41:32 |
| X:\zrodlo-slowa\scripts\zrodlo_stage13_translation_tests.php | 7650 | 30.05.2026 13:06:01 |
| X:\zrodlo-slowa\scripts\zrodlo_stage14_final_check.php | 7279 | 30.05.2026 09:20:58 |
| X:\zrodlo-slowa\scripts\zrodlo_user_ui_translation_check.php | 4756 | 30.05.2026 12:59:41 |
| X:\zrodlo-slowa\storage\cache\currency_rates_nbp.json | 1929 | 30.05.2026 13:16:37 |
| X:\zrodlo-slowa\storage\logs\debug_de_wallet.html | 20889 | 30.05.2026 14:48:58 |
| X:\zrodlo-slowa\storage\logs\edd_product_report_20260527_103215.json | 6451 | 27.05.2026 12:32:15 |
| X:\zrodlo-slowa\storage\logs\edd_product_report_20260527_130756.json | 6451 | 27.05.2026 15:07:56 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_083500.json | 279 | 27.05.2026 10:35:00 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_103027.json | 285 | 27.05.2026 12:30:27 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_103054.json | 282 | 27.05.2026 12:30:54 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_103216.json | 513 | 27.05.2026 12:32:16 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_104314.json | 282 | 27.05.2026 12:43:14 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_105115.json | 548 | 27.05.2026 12:51:15 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_105145.json | 548 | 27.05.2026 12:51:45 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_111125.json | 911 | 27.05.2026 13:11:25 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_112051.json | 675 | 27.05.2026 13:20:51 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_114032.json | 675 | 27.05.2026 13:40:32 |
| X:\zrodlo-slowa\storage\logs\finance_report_20260527_125529.json | 901 | 27.05.2026 14:55:29 |
| X:\zrodlo-slowa\storage\logs\talent_report_20260527_111125.json | 903 | 27.05.2026 13:11:25 |
| X:\zrodlo-slowa\storage\logs\talent_report_20260527_112051.json | 484 | 27.05.2026 13:20:51 |
| X:\zrodlo-slowa\storage\logs\talent_report_20260527_114032.json | 484 | 27.05.2026 13:40:32 |
| X:\zrodlo-slowa\storage\logs\talent_report_20260527_125609.json | 903 | 27.05.2026 14:56:09 |
| X:\zrodlo-slowa\vendor\autoload.php | 748 | 30.05.2026 18:29:09 |
| X:\zrodlo-slowa\vendor\composer\autoload_classmap.php | 222 | 30.05.2026 18:29:09 |
| X:\zrodlo-slowa\vendor\composer\autoload_namespaces.php | 139 | 30.05.2026 18:29:09 |
| X:\zrodlo-slowa\vendor\composer\autoload_psr4.php | 208 | 30.05.2026 18:29:09 |
| X:\zrodlo-slowa\vendor\composer\autoload_real.php | 1087 | 30.05.2026 18:29:09 |
| X:\zrodlo-slowa\vendor\composer\autoload_static.php | 1075 | 30.05.2026 18:29:09 |
| X:\zrodlo-slowa\vendor\composer\ClassLoader.php | 16378 | 22.01.2026 14:08:50 |
| X:\zrodlo-slowa\vendor\composer\installed.json | 3196 | 30.05.2026 18:29:09 |
| X:\zrodlo-slowa\vendor\composer\installed.php | 1105 | 30.05.2026 18:29:09 |
| X:\zrodlo-slowa\vendor\composer\InstalledVersions.php | 17395 | 30.05.2026 18:29:09 |
| X:\zrodlo-slowa\vendor\composer\LICENSE | 1070 | 22.01.2026 14:08:50 |
| X:\zrodlo-slowa\views\account\security.php | 3798 | 28.05.2026 19:03:05 |
| X:\zrodlo-slowa\views\account\settings.php | 13455 | 1.06.2026 14:57:30 |
| X:\zrodlo-slowa\views\admin\ai.php | 13362 | 1.06.2026 08:59:21 |
| X:\zrodlo-slowa\views\admin\anti_fraud.php | 5455 | 28.05.2026 06:38:56 |
| X:\zrodlo-slowa\views\admin\articles.php | 7765 | 1.06.2026 19:19:38 |
| X:\zrodlo-slowa\views\admin\campaigns.php | 7799 | 28.05.2026 19:15:09 |
| X:\zrodlo-slowa\views\admin\campaign_report.php | 1639 | 27.05.2026 18:50:18 |
| X:\zrodlo-slowa\views\admin\categories.php | 10492 | 30.05.2026 12:50:20 |
| X:\zrodlo-slowa\views\admin\dashboard.php | 5043 | 2.06.2026 23:26:33 |
| X:\zrodlo-slowa\views\admin\editorial_edit.php | 15037 | 1.06.2026 15:47:56 |
| X:\zrodlo-slowa\views\admin\editorial_list.php | 12474 | 1.06.2026 18:43:56 |
| X:\zrodlo-slowa\views\admin\finance_report.php | 7697 | 2.06.2026 23:32:17 |
| X:\zrodlo-slowa\views\admin\ledger.php | 2542 | 29.05.2026 22:36:19 |
| X:\zrodlo-slowa\views\admin\mails.php | 2360 | 28.05.2026 19:30:53 |
| X:\zrodlo-slowa\views\admin\main_banner.php | 10514 | 2.06.2026 07:19:04 |
| X:\zrodlo-slowa\views\admin\payments.php | 20634 | 29.05.2026 23:11:57 |
| X:\zrodlo-slowa\views\admin\payouts.php | 4981 | 28.05.2026 20:05:59 |
| X:\zrodlo-slowa\views\admin\proofreader_edit.php | 3361 | 1.06.2026 15:47:56 |
| X:\zrodlo-slowa\views\admin\roles.php | 6072 | 28.05.2026 19:47:39 |
| X:\zrodlo-slowa\views\admin\role_panel.php | 24360 | 1.06.2026 19:07:54 |
| X:\zrodlo-slowa\views\admin\settings.php | 20965 | 30.05.2026 13:29:07 |
| X:\zrodlo-slowa\views\admin\surveys.php | 9051 | 28.05.2026 19:12:52 |
| X:\zrodlo-slowa\views\admin\survey_report.php | 1855 | 27.05.2026 18:43:02 |
| X:\zrodlo-slowa\views\admin\users.php | 10613 | 29.05.2026 22:36:25 |
| X:\zrodlo-slowa\views\admin\user_delete.php | 8846 | 27.05.2026 23:03:03 |
| X:\zrodlo-slowa\views\articles\index.php | 13867 | 2.06.2026 10:12:14 |
| X:\zrodlo-slowa\views\articles\show.php | 15420 | 30.05.2026 16:59:07 |
| X:\zrodlo-slowa\views\articles\translation_unavailable.php | 1578 | 1.06.2026 10:45:18 |
| X:\zrodlo-slowa\views\auth\forgot.php | 498 | 27.05.2026 18:51:16 |
| X:\zrodlo-slowa\views\auth\login.php | 1290 | 27.05.2026 16:16:40 |
| X:\zrodlo-slowa\views\auth\register.php | 1138 | 27.05.2026 16:08:29 |
| X:\zrodlo-slowa\views\auth\reset.php | 579 | 27.05.2026 18:51:16 |
| X:\zrodlo-slowa\views\auth\two_factor_challenge.php | 1440 | 28.05.2026 06:10:48 |
| X:\zrodlo-slowa\views\author\create_article.php | 5601 | 1.06.2026 11:07:40 |
| X:\zrodlo-slowa\views\author\dashboard.php | 6389 | 1.06.2026 19:19:38 |
| X:\zrodlo-slowa\views\author\edit_article.php | 11686 | 1.06.2026 15:47:58 |
| X:\zrodlo-slowa\views\campaigns\index.php | 1022 | 30.05.2026 13:29:10 |
| X:\zrodlo-slowa\views\campaigns\show.php | 2905 | 28.05.2026 06:29:20 |
| X:\zrodlo-slowa\views\donations\campaign.php | 1809 | 27.05.2026 18:51:34 |
| X:\zrodlo-slowa\views\economy\show.php | 2642 | 29.05.2026 23:12:07 |
| X:\zrodlo-slowa\views\layouts\error.php | 240 | 27.05.2026 16:47:31 |
| X:\zrodlo-slowa\views\layouts\main.php | 11415 | 1.06.2026 15:24:50 |
| X:\zrodlo-slowa\views\partials\article_language_switcher.php | 2350 | 1.06.2026 10:45:18 |
| X:\zrodlo-slowa\views\partials\language_switcher.php | 3412 | 1.06.2026 10:54:06 |
| X:\zrodlo-slowa\views\reader\dashboard.php | 233 | 27.05.2026 08:54:57 |
| X:\zrodlo-slowa\views\surveys\index.php | 1245 | 27.05.2026 18:24:16 |
| X:\zrodlo-slowa\views\surveys\show.php | 4443 | 28.05.2026 19:12:38 |
| X:\zrodlo-slowa\views\wallet\show.php | 22712 | 30.05.2026 19:29:03 |
| X:\zrodlo-slowa\views\wallet\topup.php | 4084 | 30.05.2026 14:53:12 |
| X:\zrodlo-slowa\views\wallet\topup_cancel.php | 748 | 30.05.2026 14:54:18 |
| X:\zrodlo-slowa\views\wallet\topup_success.php | 753 | 30.05.2026 14:53:54 |


---

## AUDYT_02_MAPA_TRAS.csv

<!-- ŹRÓDŁO: AUDYT_02_MAPA_TRAS.csv -->


| Metoda | URL | Kontroler | Metoda Klasy | Rola | CSRF | Moduł | Ryzyko |
| --- | --- | --- | --- | --- | --- | --- | --- |
| GET | / | HomeController | index | Publiczny | Nie | Home | Niskie |
| GET | /jak-zarabiac | HomeController | economy | Publiczny | Nie | Home | Niskie |
| GET | /register | AuthController | showRegister | Publiczny | Nie | Auth | Niskie |
| POST | /register | AuthController | register | Publiczny | Tak | Auth | Średnie |
| GET | /login | AuthController | showLogin | Publiczny | Nie | Auth | Niskie |
| POST | /login | AuthController | login | Publiczny | Tak | Auth | Średnie |
| POST | /logout | AuthController | logout | Zalogowany | Tak | Auth | Niskie |
| GET | /articles | ArticleController | index | Publiczny | Nie | Articles | Niskie |
| GET | /article | ArticleController | show | Publiczny | Nie | Articles | Niskie |
| POST | /article/buy | ArticleController | buy | Zalogowany | Tak | Articles | Wysokie |
| GET | /wallet | WalletController | show | Zalogowany | Nie | Wallet | Średnie |
| POST | /wallet/topup | WalletTopupController | create | Zalogowany | Tak | Wallet | Wysokie |
| POST | /stripe/webhook | StripeWebhookController | handle | Publiczny | Nie | Payments | Krytyczne |
| GET | /admin | AdminController | dashboard | Admin | Nie | Admin | Niskie |
| GET | /admin/articles | AdminController | articles | Admin / Chief Editor | Nie | Admin | Niskie |
| POST | /admin/articles/status | AdminController | setArticleStatus | Admin / Chief Editor | Tak | Admin | Średnie |
| GET | /admin/finance | FinanceController | report | Admin / Accountant | Nie | Finance | Średnie |


---

## AUDYT_03_KONTROLERY.csv

<!-- ŹRÓDŁO: AUDYT_03_KONTROLERY.csv -->


| Plik | Klasa | Metoda | Uprawnienia | Wejście | Wyjście | Widok | Ryzyka |
| --- | --- | --- | --- | --- | --- | --- | --- |
| HomeController.php | HomeController | index | Publiczny | None | HTML | articles/index | Niskie |
| AuthController.php | AuthController | login | Publiczny | POST | Redirect | None | Średnie |
| AdminController.php | AdminController | dashboard | Admin | None | HTML | admin/dashboard | Niskie |
| AdminController.php | AdminController | setArticleValuation | Admin / Moderator | POST | Redirect | None | Wysokie |
| ArticleController.php | ArticleController | show | Publiczny | GET | HTML | articles/show | Niskie |
| FinanceController.php | FinanceController | report | Admin / Accountant | None | HTML | admin/finance_report | Średnie |


---

## AUDYT_04_SERWISY.csv

<!-- ŹRÓDŁO: AUDYT_04_SERWISY.csv -->


| Plik | Klasa | Metoda | Kto wywołuje | Tabele SQL | Efekty uboczne | Ryzyka |
| --- | --- | --- | --- | --- | --- | --- |
| ArticleService.php | ArticleService | createDraft | AuthorController | articles | Zapis rekordu i snapshotu | Niskie |
| LedgerService.php | LedgerService | post | Wiele serwisów | wallets |  Zmiana salda i historia | Wysokie |
| AuthSecurityService.php | AuthSecurityService | verifyLoginTwoFactor | AuthController | auth_login_events | Logowanie próby | Średnie |
| AiFoundationService.php | AiFoundationService | createPlannedArticleJob | AiAdminController | ai_jobs | Planowanie pracy AI | Średnie |
| UploadService.php | UploadService | uploadArticleImage | AuthorController | media | Zapis pliku na dysku | Średnie |


---

## AUDYT_06_TABELA_MIGRACJI.csv

<!-- ŹRÓDŁO: AUDYT_06_TABELA_MIGRACJI.csv -->


| Tabela | Rola | Kluczowe kolumny | Zależności | Moduł |
| --- | --- | --- | --- | --- |
| users | Główna tabela użytkowników | email, password_hash, status | None | Auth |
| wallets | Salda portfeli | main_available_minor, points_balance | users | Finanse |
| articles | Główna tabela artykułów | title, slug, status, access_mode | users | Artykuły |
| article_translations | Tłumaczenia treści | language, title, body, slug | articles | Języki |
| wallet_transactions | Historia finansowa | amount_minor, type, status | wallets | Finanse |
| ai_jobs | Zadania AI | type, status, input_json | articles | AI |
| settings | Konfiguracja systemu | name, value | None | Core |
| main_banners | Zarządzanie banerem | slug, image_path | None | Banner |


---

## AUDYT_11_RYZYKA.csv

<!-- ŹRÓDŁO: AUDYT_11_RYZYKA.csv -->


| ID | Moduł | Funkcja | Typ ryzyka | Opis | Skutek | Priorytet | Rekomendacja |
| --- | --- | --- | --- | --- | --- | --- | --- |
| R1 | Baza danych | public/adminer.php | Zabezpieczenia | Adminer widoczny publicznie | Nieuprawniony dostęp do danych | KRYTYCZNE | Usunąć plik z repozytorium |
| R2 | Finanse | LedgerService::post | Idempotencja | Brak klucza dla manualnych wpłat | Podwójne księgowanie środków | ŚREDNIE | Wymusić klucz idempotencji |
| R3 | AI | AiFoundationService::settings | Koszty | Brak twardego limitu $ w API | Nieprzewidziane koszty OpenAI | ŚREDNIE | Dodać monitorowanie kosztów |
| R4 | System | public/index.php | Utrzymanie | Zbyt duże kontrolery | Utrudnione testowanie i rozwój | NISKIE | Refaktoryzacja do mniejszych klas |


---

# Dyspozycje dla JUNI


---

<!-- ŹRÓDŁO: DYSPOZYCJA_DLA_JUNI_PELNY_AUDYT_REPO_ZRODLO_SLOWA.md -->


# ŹRÓDŁO SŁOWA — dyspozycja dla JUNI: pełny przegląd kodu, funkcji i dokumentacji

## 0. Cel pracy

Celem nie jest przebudowa systemu ani refaktor „przy okazji”. Celem jest **pełna inspekcja istniejącego repozytorium ŹRÓDŁO SŁOWA** i przygotowanie dwóch kompletnych dokumentacji:

1. **Dokumentacja techniczna kodu** — jak działa system od strony plików, klas, metod, tras, bazy danych, usług, formularzy, zabezpieczeń, przepływów danych i integracji.
2. **Dokumentacja opisowa / funkcjonalna systemu** — jakie moduły istnieją, po co są, jaką pełnią rolę, kto ich używa, jakie są stany, uprawnienia, przepływy pracy i zależności między rolami.

JUNI ma przeprowadzić **audyt funkcja po funkcji**, nie tylko ogólny opis. Każda metoda publiczna, prywatna i pomocnicza ma zostać odnaleziona, przypisana do modułu, sprawdzona pod kątem roli, wejść, wyjść, zależności, ryzyk i faktycznego użycia.

---

## 1. Stan repo po wstępnym rozpoznaniu

Repo jest aplikacją PHP bez klasycznego frameworka, z własnym routerem, kontrolerami, serwisami, widokami, migracjami SQL i helperami w `bootstrap.php`.

Wstępne rozpoznanie paczki wykazało:

- ok. **183 pliki PHP poza vendor**,
- ok. **57 plików SQL**,
- ok. **23 pliki JSON**,
- ok. **23 kontrolery** w `app/Controllers`,
- ok. **45 serwisów** w `app/Services`,
- ok. **7 plików core** w `app/Core`,
- ok. **113 klas**,
- ok. **1496 funkcji/metod** wykrytych w repo, w tym:
  - ok. **181 metod kontrolerów**,
  - ok. **516 metod serwisów**,
  - ok. **67 funkcji/metod core**,
  - funkcje pomocnicze w skryptach, widokach i plikach publicznych.

Wstępne sprawdzenie składni `php -l` dla `app`, `public`, `scripts` nie wykazało błędów składniowych.

---

## 2. Twardy kontrakt pracy dla JUNI

JUNI ma pracować w trybie inspekcji, nie implementacji.

### Nie wolno

- nie przebudowywać architektury,
- nie usuwać istniejących modułów,
- nie zmieniać nazw ról, stanów, tras ani tabel,
- nie poprawiać kodu bez osobnej zgody,
- nie mieszać audytu z refaktorem,
- nie generować ogólnikowej dokumentacji bez sprawdzenia plików,
- nie pomijać funkcji prywatnych i helperów,
- nie zakładać, że dana funkcja działa tylko po nazwie — trzeba sprawdzić ciało funkcji i jej wywołania.

### Wolno

- czytać cały kod,
- tworzyć mapy funkcji, klas i tras,
- robić raporty `.md`, `.csv`, `.json`,
- uruchamiać testy statyczne i bezpieczne skrypty diagnostyczne,
- sprawdzać zależności między plikami,
- wskazywać luki, długi techniczne i ryzyka,
- proponować poprawki, ale w osobnej sekcji „rekomendacje”, bez wdrażania.

---

## 3. Kolejność pełnego audytu

## ETAP 1 — Inwentaryzacja repozytorium

JUNI ma wykonać pełną mapę repo:

1. Lista katalogów i ich rola:
   - `app/Controllers`
   - `app/Services`
   - `app/Core`
   - `app/Models`
   - `app/Payments`
   - `config`
   - `database/migrations`
   - `database/seeds`
   - `public`
   - `resources/lang`
   - `scripts`
   - `views`
   - `storage`
   - `docs`

2. Lista plików istotnych dla działania systemu.
3. Lista plików pomocniczych, testowych i diagnostycznych.
4. Lista plików potencjalnie niebezpiecznych lub produkcyjnie wrażliwych, np.:
   - `.env`,
   - `public/adminer.php`,
   - skrypty resetujące bazę,
   - skrypty importujące dane,
   - webhooki płatności,
   - endpointy admina.

Wynik etapu:

- `AUDYT_01_MAPA_REPO.md`
- `AUDYT_01_LISTA_PLIKOW.csv`

---

## ETAP 2 — Mapa wejść systemu

JUNI ma przeanalizować `public/index.php` i router.

Trzeba zrobić pełną tabelę tras:

| Metoda HTTP | URL | Kontroler | Metoda | Rola użytkownika | CSRF | Moduł | Ryzyko |
|---|---|---|---|---|---|---|---|

Do sprawdzenia:

- wszystkie `GET`,
- wszystkie `POST`,
- dostęp publiczny,
- dostęp autora,
- dostęp czytelnika,
- dostęp admina,
- dostęp redakcji,
- dostęp wydawcy,
- dostęp korektora,
- webhooki,
- operacje finansowe,
- operacje uploadu,
- operacje AI,
- operacje migracyjne.

Wynik etapu:

- `AUDYT_02_MAPA_TRAS.md`
- `AUDYT_02_MAPA_TRAS.csv`

---

## ETAP 3 — Kontrolery: audyt metoda po metodzie

JUNI ma przejść każdy kontroler i każdą metodę.

Kontrolery do pełnego sprawdzenia:

- `AccountController.php`
- `AccountSecurityController.php`
- `ActivityController.php`
- `AdminArticleTranslationController.php`
- `AdminController.php`
- `AiAdminController.php`
- `ArticleController.php`
- `AuthController.php`
- `AuthorController.php`
- `BaseController.php`
- `CampaignController.php`
- `DonationController.php`
- `FinanceController.php`
- `HomeController.php`
- `MigrationController.php`
- `PaymentWebhookController.php`
- `ReaderController.php`
- `SitemapController.php`
- `StripeWebhookController.php`
- `SurveyController.php`
- `WalletController.php`
- `WalletTopupController.php`
- `WalletTransferController.php`

Dla każdej metody opisać:

| Plik | Klasa | Metoda | URL / wywołanie | Wejście | Wyjście | Widok | Serwisy | Uprawnienia | Ryzyka | Status |
|---|---|---|---|---|---|---|---|---|---|---|

Minimalny zakres analizy metody:

1. co metoda robi,
2. kto ją może wywołać,
3. czy ma `requireAuth`, `requireAdmin`, `requireAdminOrRoles` albo inną blokadę,
4. jakie dane bierze z `$_GET`, `$_POST`, `$_FILES`, `$_SERVER`, sesji,
5. czy waliduje dane,
6. czy używa CSRF,
7. czy zapisuje do bazy,
8. czy zmienia stan użytkownika, artykułu, płatności, portfela, tłumaczeń lub baneru,
9. czy zwraca HTML, JSON, redirect albo status HTTP,
10. czy może powodować konflikt języka, statusu, roli lub pieniędzy,
11. czy ma widoczną lukę bezpieczeństwa,
12. czy ma ryzyko dublowania funkcji z innym miejscem.

Wynik etapu:

- `AUDYT_03_KONTROLERY.md`
- `AUDYT_03_KONTROLERY.csv`

---

## ETAP 4 — Serwisy: audyt funkcja po funkcji

JUNI ma przejść każdy serwis z `app/Services`.

Najważniejsze grupy serwisów:

### Artykuły i redakcja

- `ArticleService.php`
- `ArticleTranslationService.php`
- `ArticleSeoService.php`
- `ArticleEconomyService.php`
- `CategoryService.php`
- `MainBannerService.php`

### AI i tłumaczenia

- `AiFoundationService.php`
- `OpenAiClient.php`
- `TranslationAiService.php`
- `TranslationPromptBuilder.php`
- `PublicTranslationService.php`
- `PublicLanguageService.php`
- `PublicSiteResolver.php`
- `SeoSlugService.php`

### Użytkownicy, role i bezpieczeństwo

- `AuthService.php`
- `AuthSecurityService.php`
- `UserService.php`
- `UserDeletionService.php`
- `RoleService.php`
- `FraudGuardService.php`
- `SlowoSnajperService.php`

### Portfel, płatności i ekonomia

- `WalletService.php`
- `WalletTopupService.php`
- `WalletTopupPackageService.php`
- `WalletTransferService.php`
- `LedgerService.php`
- `TalentService.php`
- `PayoutService.php`
- `PayoutMethodService.php`
- `PaymentService.php`
- `PaymentOrderService.php`
- `PaymentGatewayEventService.php`
- `PaymentRuntimeConfigService.php`
- `CurrencyRateService.php`
- `EconomyMapService.php`

### Kampanie, ankiety, aktywność

- `ActivityService.php`
- `ActivityUiHelper.php`
- `CampaignService.php`
- `SurveyService.php`
- `DonationService.php`
- `SupportService.php`

### Instalacja, import, upload, legacy

- `InstallService.php`
- `LegacyImportService.php`
- `UploadService.php`
- `MailService.php`

Dla każdej metody serwisu opisać:

| Plik | Klasa | Metoda | Kto wywołuje | Dane wejściowe | Dane wyjściowe | Tabele SQL | Efekty uboczne | Ryzyka | Test ręczny |
|---|---|---|---|---|---|---|---|---|---|

Szczególnie sprawdzić:

- transakcje portfela,
- transfer Talent → PLN,
- dopisywanie bonusów,
- wypłaty,
- płatności Stripe,
- webhooki,
- walidację kwot,
- walidację języka,
- tłumaczenia AI,
- statusy artykułów,
- korektę,
- baner główny,
- SEO slug,
- upload obrazków,
- usuwanie użytkownika,
- role redakcyjne,
- SŁOWO SNAJPER / anti-fraud.

Wynik etapu:

- `AUDYT_04_SERWISY.md`
- `AUDYT_04_SERWISY.csv`

---

## ETAP 5 — Core systemu

JUNI ma sprawdzić pliki:

- `app/Core/App.php`
- `app/Core/bootstrap.php`
- `app/Core/Database.php`
- `app/Core/Router.php`
- `app/Core/Session.php`
- `app/Core/SlowoSnajperConfig.php`
- `app/Core/View.php`

Do opisania:

1. boot aplikacji,
2. autoload,
3. env,
4. sesja,
5. router,
6. CSRF,
7. helper `e()`,
8. helper `t()`,
9. język publiczny,
10. normalizacja URI,
11. SEO rewrite,
12. generowanie URL,
13. dostęp do bazy,
14. render widoków,
15. obsługa błędów.

Wynik etapu:

- `AUDYT_05_CORE.md`

---

## ETAP 6 — Baza danych i migracje

JUNI ma przejść wszystkie migracje w `database/migrations` od `001` do najnowszych oraz migracje `999`.

Dla każdej migracji opisać:

| Migracja | Tworzy tabele | Dodaje kolumny | Dodaje indeksy | Zmienia dane | Ryzyko powtórnego uruchomienia | Moduł |
|---|---|---|---|---|---|---|

Szczególnie sprawdzić:

- czy są zdublowane numery migracji, np. `031`, `032`,
- czy migracje są idempotentne,
- czy są bezpieczne dla istniejącej bazy,
- czy migracje `999` nie kolidują z nowszymi,
- czy kod zakłada kolumny, których migracje nie tworzą,
- czy widoki i serwisy używają aktualnych nazw kolumn,
- czy istnieją tabele finansowe i statusowe wymagane przez serwisy.

Wynik etapu:

- `AUDYT_06_BAZA_DANYCH.md`
- `AUDYT_06_TABELA_MIGRACJI.csv`

---

## ETAP 7 — Widoki i formularze

JUNI ma przejść katalog `views`.

Dla każdego widoku opisać:

| Widok | Moduł | Kontroler | Formularze POST | CSRF | Dane wyświetlane | Dane edytowane | Ryzyka XSS / brak escape |
|---|---|---|---|---|---|---|---|

Szczególnie sprawdzić:

- `views/layouts/main.php`,
- panel admina,
- panel autora,
- panel korektora,
- panel wydawcy / redakcji,
- portfel,
- doładowania,
- wypłaty,
- ustawienia konta,
- avatar,
- baner główny,
- tłumaczenia artykułów,
- przełącznik językowy,
- formularze AJAX.

Wynik etapu:

- `AUDYT_07_WIDOKI_FORMULARZE.md`

---

## ETAP 8 — Języki, tłumaczenia i SEO

JUNI ma osobno sprawdzić całą warstwę językową, bo to jest w repo obszar wrażliwy.

Pliki do sprawdzenia:

- `config/languages.php`
- `config/sites.php`
- `config/sites.json`
- `config/seo_languages.json`
- `resources/lang/public.json`
- `PublicLanguageService.php`
- `PublicSiteResolver.php`
- `PublicTranslationService.php`
- `ArticleTranslationService.php`
- `TranslationAiService.php`
- `TranslationPromptBuilder.php`
- `AdminArticleTranslationController.php`
- `ArticleSeoService.php`
- `SeoSlugService.php`
- `views/partials/language_switcher.php`
- `views/partials/article_language_switcher.php`

Do opisania:

1. skąd system bierze język bieżący,
2. co oznacza `_lang`,
3. co oznacza `interface_language`,
4. kiedy język zapisuje się użytkownikowi,
5. jak działają prefiksy `/pl`, `/de`, itd.,
6. jak działa domena/język,
7. jak działa fallback,
8. jak powstaje slug tłumaczenia,
9. jak działa tłumaczenie AI,
10. gdzie można ręcznie edytować tłumaczenie,
11. jak działa sitemap i canonical,
12. gdzie może pojawić się problem kodowania UTF-8.

Wynik etapu:

- `AUDYT_08_JEZYKI_TLUMACZENIA_SEO.md`

---

## ETAP 9 — Artykuły, redakcja, korekta, wydawca

JUNI ma zbudować pełny opis cyklu życia artykułu.

Obowiązkowo uwzględnić zasadę stanów redakcyjnych:

- `submitted` — tekst przyszedł od autora,
- `review` — Redaktor Główny pracuje nad tekstem / analizuje / podejmuje decyzję,
- `approved` — Redakcja Główna zaakceptowała tekst i przekazuje dalej,
- `rejected` — Redakcja odrzuca lub cofa,
- `archived` — Wydawca lub Moderator może zarchiwizować.

Redaktor Główny obsługuje ciąg:

`submitted → review → approved`

Po `approved` artykuł ma pojawić się u Wydawcy i Moderatora do dalszej obsługi.

Korektor ma widzieć artykuły jak Wydawca, ale może zmieniać tylko:

- lead,
- treść właściwą.

Nie może zmieniać:

- tytułu,
- zdjęcia,
- statusów publikacyjnych,
- danych finansowych,
- kategorii, jeśli nie ma do tego osobnej roli.

Po zmianie korektorskiej ma być informacja dla Wydawcy i Autora: **KOREKTA**.

Wynik etapu:

- `AUDYT_09_ARTYKULY_REDAKCJA_KOREKTA.md`

---

## ETAP 10 — Baner Główny

JUNI ma przeanalizować cały moduł Baneru Głównego.

Obowiązkowo uwzględnić zasadę projektową:

Baner Główny nie ma mieć ręcznych stałych pól dla wszystkich języków naraz. Ma działać jak artykuły:

1. główna wersja źródłowa,
2. przycisk „Tłumacz baner główny”,
3. AI generuje tłumaczenia,
4. każda wersja językowa może być potem ręcznie edytowana,
5. formularz ma być redakcyjny, prosty i zgodny ze stylem ŹRÓDŁA SŁOWA,
6. tło graficzne ma być skalowane responsywnie i widoczne również przy małej szerokości ekranu.

Do sprawdzenia:

- `MainBannerService.php`,
- `AdminController::mainBanner`,
- `AdminController::updateMainBanner`,
- `AdminController::translateMainBannerAi`,
- widok `views/admin/main_banner.php`,
- migracje `045`, `046`, `048`, `049`, `050`, `051`,
- kodowanie tłumaczeń,
- zapisywanie obrazka/tła,
- publiczne wyświetlanie na stronie głównej.

Wynik etapu:

- `AUDYT_10_BANER_GLOWNY.md`

---

## ETAP 11 — Bezpieczeństwo

JUNI ma zrobić osobny audyt bezpieczeństwa.

Obszary obowiązkowe:

1. CSRF — czy każdy POST przechodzi przez `verify_csrf()`.
2. XSS — czy dane w widokach są escapowane przez `e()`.
3. SQL injection — czy zapytania są parametryzowane.
4. Upload — czy pliki są walidowane typem, rozmiarem i ścieżką.
5. Sesja — login, logout, 2FA, reset hasła.
6. Role — czy metody admina mają właściwe ograniczenia.
7. Webhooki — Stripe i ręczne płatności.
8. Portfel — czy użytkownik nie może dopisać sobie pieniędzy.
9. Transfer Talent → PLN — czy jest walidacja limitów, prowizji i stanu.
10. Usuwanie użytkownika — czy nie zostawia śmieci w bazie.
11. Adminer — czy `public/adminer.php` nie jest ryzykiem produkcyjnym.
12. Skrypty CLI — czy nie są dostępne z WWW.
13. `.env` — czy nie jest wystawiony publicznie.
14. Logi — czy nie trzymają danych wrażliwych.

Wynik etapu:

- `AUDYT_11_BEZPIECZENSTWO.md`
- `AUDYT_11_RYZYKA.csv`

---

## ETAP 12 — Płatności, portfele, Talent, prowizje

JUNI ma osobno opisać logikę pieniędzy, bo to jest krytyczny obszar.

Do sprawdzenia:

- PLN wallet,
- Talent wallet,
- transfer Talent → PLN,
- prowizja systemu,
- doładowania,
- Stripe,
- przelewy24 / plugin płatności, jeśli przewidziany,
- wypłaty,
- ledger,
- bonusy za aktywność,
- bonusy za czytanie,
- płatności reklamowe,
- ankiety,
- PPV,
- live,
- kampanie sponsorowane.

Dla każdej operacji finansowej opisać:

| Operacja | Kto uruchamia | Tabela źródłowa | Tabela zapisu | Walidacja | Blokada nadużyć | Ryzyko | Test |
|---|---|---|---|---|---|---|---|

Wynik etapu:

- `AUDYT_12_FINANSE_PORTFEL_TALENT.md`

---

## ETAP 13 — AI i OpenAI

JUNI ma opisać integrację AI bez generowania nowych funkcji.

Do sprawdzenia:

- konfiguracja w `config/ai.php`,
- panel admina AI,
- OpenAI key,
- test połączenia,
- tworzenie planu,
- tłumaczenia artykułów,
- tłumaczenia baneru,
- prompt builder,
- format JSON odpowiedzi,
- walidacja odpowiedzi AI,
- logowanie zdarzeń AI,
- fallback przy błędzie.

Wynik etapu:

- `AUDYT_13_AI_OPENAI.md`

---

## ETAP 14 — Skrypty diagnostyczne i utrzymaniowe

JUNI ma przejść `scripts`.

Dla każdego skryptu opisać:

| Skrypt | Cel | Czy CLI | Czy bezpieczny | Czy modyfikuje bazę | Kiedy używać | Ryzyko |
|---|---|---|---|---|---|---|

Szczególnie sprawdzić:

- reset bazy,
- import legacy,
- migracje ręczne,
- raporty finansowe,
- naprawy kodowania,
- SEO backfill,
- currency rates,
- testy językowe,
- check proofreader panel,
- remove clockwork.

Wynik etapu:

- `AUDYT_14_SKRYPTY.md`

---

## ETAP 15 — Testy ręczne scenariuszowe

JUNI ma przygotować checklistę testów ręcznych.

Minimalne scenariusze:

### Publiczne

- wejście na stronę główną,
- zmiana języka,
- wejście na artykuł,
- przejście po slugach SEO,
- sitemap,
- baner główny,
- responsywność.

### Konto

- rejestracja,
- logowanie,
- logout,
- reset hasła,
- 2FA,
- zmiana języka interfejsu,
- avatar.

### Autor

- utworzenie artykułu,
- edycja artykułu,
- upload zdjęcia,
- submit do redakcji,
- blokada po submit.

### Redakcja Główna

- `submitted`,
- `review`,
- `approved`,
- `rejected`.

### Wydawca / Moderator

- obsługa zatwierdzonego artykułu,
- publikacja,
- archiwizacja,
- tłumaczenia,
- SEO.

### Korektor

- widzi artykuł,
- zmienia tylko lead i treść,
- nie może zmienić tytułu i zdjęcia,
- generuje status/informację KOREKTA.

### Portfel

- doładowanie,
- transfer Talent → PLN,
- wypłata,
- historia,
- bonusy,
- odrzucenie operacji nieuprawnionej.

### Admin

- role,
- użytkownicy,
- płatności,
- AI,
- baner,
- kategorie,
- migracje.

Wynik etapu:

- `AUDYT_15_TESTY_RECZNE.md`

---

## 4. Dwie końcowe dokumentacje wymagane od JUNI

Po audycie JUNI ma przygotować dwa główne dokumenty końcowe.

## A. Dokumentacja techniczna

Plik:

`ZRODLO_SLOWA_DOKUMENTACJA_TECHNICZNA_PELNA.md`

Struktura obowiązkowa:

1. Architektura repozytorium.
2. Punkt wejścia aplikacji.
3. Router i mapa tras.
4. Core / bootstrap / sesja / CSRF.
5. Kontrolery.
6. Serwisy.
7. Modele i płatności.
8. Baza danych i migracje.
9. Widoki i formularze.
10. Języki, domeny, tłumaczenia, SEO.
11. AI i OpenAI.
12. Portfel, Talent, płatności, ledger.
13. Role i uprawnienia.
14. Artykuły i workflow redakcyjny.
15. Baner Główny.
16. SŁOWO SNAJPER / anti-fraud.
17. Uploady i media.
18. Skrypty utrzymaniowe.
19. Zależności i konfiguracja.
20. Miejsca ryzyka technicznego.
21. Rekomendacje napraw — tylko lista, bez wdrożenia.

## B. Dokumentacja opisowa / funkcjonalna

Plik:

`ZRODLO_SLOWA_DOKUMENTACJA_OPISOWA_ZASADY_SYSTEMU.md`

Struktura obowiązkowa:

1. Czym jest ŹRÓDŁO SŁOWA.
2. Role użytkowników.
3. Panel publiczny.
4. Panel autora.
5. Redakcja Główna.
6. Korektor.
7. Wydawca.
8. Moderator.
9. Administrator.
10. Artykuł — pełny cykl życia.
11. Tłumaczenia artykułów.
12. Baner Główny.
13. Portfel użytkownika.
14. Talent i PLN.
15. Bonusy za aktywność.
16. Kampanie reklamowe.
17. Ankiety i sondy.
18. Płatności i wypłaty.
19. AI w systemie.
20. SEO i wersje językowe.
21. Zasady bezpieczeństwa.
22. Co działa teraz.
23. Co wymaga naprawy.
24. Co wymaga dalszej decyzji projektowej.

---

## 5. Format raportowania luk

Każda luka ma mieć jeden wpis:

| ID | Moduł | Plik | Funkcja | Typ ryzyka | Opis | Skutek | Priorytet | Rekomendacja | Czy wymaga decyzji właściciela |
|---|---|---|---|---|---|---|---|---|---|

Priorytety:

- `KRYTYCZNE` — pieniądze, bezpieczeństwo, utrata danych, publiczny dostęp do admina.
- `WYSOKIE` — role, statusy artykułów, języki, publikacja, AI zapisujące błędne dane.
- `ŚREDNIE` — niespójność UI, brak walidacji pomocniczej, problem workflow.
- `NISKIE` — kosmetyka, nazewnictwo, porządek dokumentacji.

---

## 6. Format raportowania funkcji

Każda funkcja/metoda powinna mieć minimalny opis:

```md
### Plik: app/Services/ExampleService.php
### Klasa: ExampleService
### Metoda: exampleMethod()

Rola:
- ...

Wywoływana przez:
- ...

Dane wejściowe:
- ...

Dane wyjściowe:
- ...

Tabele / pliki:
- ...

Efekty uboczne:
- ...

Ryzyka:
- ...

Test ręczny:
- ...

Status audytu:
- OK / DO SPRAWDZENIA / RYZYKO / NIEUŻYWANA
```

---

## 7. Najważniejsze moduły do szczególnego pilnowania

1. **Język publiczny** — po ostatnich problemach nie wolno pomylić `_lang` z `interface_language`.
2. **Tłumaczenia niemieckie i kodowanie UTF-8** — sprawdzić, gdzie może dojść do podmiany albo zepsucia znaków.
3. **Baner Główny** — ma działać jak artykuły, z AI i ręczną edycją wersji językowych.
4. **Workflow artykułu** — Redakcja Główna: `submitted → review → approved`; Wydawca/Moderator po `approved`; Korektor tylko lead i treść.
5. **Portfel i Talent** — żadna operacja finansowa nie może być tylko po stronie formularza.
6. **Webhook Stripe** — musi być odporny na fałszywe żądania.
7. **Adminer** — sprawdzić, czy nie powinien być usunięty/ukryty produkcyjnie.
8. **Skrypty resetujące/importujące** — sprawdzić, czy nie są dostępne z publicznego WWW.
9. **Migracje zdublowane numerami** — sprawdzić ich kolejność i bezpieczeństwo.
10. **CSRF i XSS** — każdy formularz i AJAX musi mieć zabezpieczenie.

---

## 8. Oczekiwany wynik końcowy od JUNI

JUNI ma oddać paczkę dokumentacyjną:

```text
/docs/audyt/
  AUDYT_01_MAPA_REPO.md
  AUDYT_01_LISTA_PLIKOW.csv
  AUDYT_02_MAPA_TRAS.md
  AUDYT_02_MAPA_TRAS.csv
  AUDYT_03_KONTROLERY.md
  AUDYT_03_KONTROLERY.csv
  AUDYT_04_SERWISY.md
  AUDYT_04_SERWISY.csv
  AUDYT_05_CORE.md
  AUDYT_06_BAZA_DANYCH.md
  AUDYT_06_TABELA_MIGRACJI.csv
  AUDYT_07_WIDOKI_FORMULARZE.md
  AUDYT_08_JEZYKI_TLUMACZENIA_SEO.md
  AUDYT_09_ARTYKULY_REDAKCJA_KOREKTA.md
  AUDYT_10_BANER_GLOWNY.md
  AUDYT_11_BEZPIECZENSTWO.md
  AUDYT_11_RYZYKA.csv
  AUDYT_12_FINANSE_PORTFEL_TALENT.md
  AUDYT_13_AI_OPENAI.md
  AUDYT_14_SKRYPTY.md
  AUDYT_15_TESTY_RECZNE.md
  ZRODLO_SLOWA_DOKUMENTACJA_TECHNICZNA_PELNA.md
  ZRODLO_SLOWA_DOKUMENTACJA_OPISOWA_ZASADY_SYSTEMU.md
```

---

## 9. Czy JUNI da radę?

Tak, ale tylko jeśli praca zostanie wykonana etapami i bez mieszania audytu z naprawami.

To repo jest już duże: ma setki metod, finanse, role, AI, tłumaczenia, SEO, portfele, webhooki, migracje i panele redakcyjne. Jednorazowe „przejrzyj repo” skończy się ogólnikami. Poprawna metoda to pełna inwentaryzacja, potem kontrolery, potem serwisy, potem baza, potem widoki, potem bezpieczeństwo i dopiero na końcu dwie dokumentacje.

Najlepszy tryb pracy:

1. Najpierw JUNI tworzy mapę repo i mapę tras.
2. Potem robi audyt kontrolerów.
3. Potem audyt serwisów.
4. Potem audyt bazy i widoków.
5. Potem audyt bezpieczeństwa.
6. Dopiero na końcu generuje dwie pełne dokumentacje.

Nie należy zaczynać od dokumentacji końcowej, bo wtedy powstanie opis pozorny. Najpierw musi powstać mapa faktów.


---

<!-- ŹRÓDŁO: DYSPOZYCJA_DLA_JUNI_AUDYT_REPO_I_JEDNA_BAZA.md -->


# ŹRÓDŁO SŁOWA — PEŁNA DYSPOZYCJA DLA JUNI

## Cel główny

JUNI ma wykonać pełny, spokojny i kontrolowany przegląd repozytorium projektu **ŹRÓDŁO SŁOWA** oraz przygotować projekt do dalszej pracy według jednej prostej zasady:

> **Jedna aplikacja, jedna baza danych, jedna aktualna kopia bazy w katalogu `database`.**

Nie chodzi o szybkie poprawki. Chodzi o pełną inspekcję kodu, funkcji, skryptów, widoków, tras, usług, bazy danych i przepływów systemowych.

JUNI ma działać etapami, bez refaktoru przy okazji, bez przebudowy architektury i bez zmieniania działania modułów, jeśli nie jest to wyraźnie wskazane.

---

# ZASADY NADRZĘDNE

## 1. Nie niszczyć obecnego działania systemu

JUNI nie może zakładać, że coś jest zbędne tylko dlatego, że wygląda na stare.

Przed każdą zmianą trzeba ustalić:

- gdzie dany plik jest używany,
- czy jest podpięty w trasach,
- czy jest wywoływany przez kontroler,
- czy zależy od niego widok,
- czy używa go instalator,
- czy korzysta z niego panel admina, autora, redakcji, wydawcy, moderatora, korektora albo moduł publiczny.

## 2. Bez refaktoru przy okazji

Zakaz:

- zmiany nazw klas bez potrzeby,
- zmiany nazw tabel bez zgody,
- zmiany nazw kolumn bez zgody,
- przebudowy routera,
- przebudowy paneli,
- zmiany logiki ról,
- zmiany wyglądu bez dyspozycji,
- dodawania nowych frameworków,
- tworzenia nowego systemu migracji,
- poprawiania „przy okazji” rzeczy, które nie są w zakresie.

## 3. Najpierw audyt, potem decyzje

JUNI ma najpierw przygotować pełną mapę repo i raport. Dopiero potem można wdrażać zmiany porządkowe.

## 4. Obecna baza jest bazą wynikową

Projekt jest w budowie. Nie interesuje nas historia kolejnych migracji.

Obecna aktywna baza aplikacji to:

```text
zrodlo_slowa
```

Nie chcemy wielu równoległych nazw baz.

Nie chcemy aktywnych migracji historycznych jako mechanizmu budowy systemu.

Nie chcemy aktywnego legacy jako drugiej bazy projektu.

---

# ETAP 0 — KOPIA BEZPIECZEŃSTWA

Przed jakąkolwiek zmianą JUNI ma wykonać albo wymusić wykonanie kopii:

## 0.1. Kopia repo

Zachować nietkniętą kopię aktualnego repo.

Przykład:

```text
repo_backup_przed_audytem.zip
```

## 0.2. Kopia obecnej bazy MySQL

W Laragonie / terminalu Windows wykonać:

```bat
mysqldump -u root --default-character-set=utf8mb4 zrodlo_slowa > database\zrodlo_slowa.sql
```

Ten plik ma być kopią obecnej bazy wynikowej projektu.

Docelowo w katalogu `database` ma istnieć tylko:

```text
database/zrodlo_slowa.sql
```

---

# ETAP 1 — MAPA REPOZYTORIUM

JUNI ma przygotować pełną mapę repo.

## 1.1. Katalogi

Opisać role katalogów:

- `app/`
- `app/Controllers/`
- `app/Services/`
- `app/Core/`
- `app/Models/`, jeśli istnieje
- `config/`
- `public/`
- `views/`
- `scripts/`
- `database/`
- `docs/`
- `assets/`, jeśli istnieje
- inne katalogi obecne w repo

## 1.2. Punkty wejścia

Ustalić i opisać:

- główny punkt wejścia HTTP,
- router,
- konfigurację aplikacji,
- konfigurację bazy,
- mechanizm sesji,
- mechanizm autoryzacji,
- mechanizm ról,
- mechanizm renderowania widoków.

## 1.3. Wynik etapu

Plik:

```text
docs/AUDYT_REPO_MAPA_TECHNICZNA.md
```

Ma zawierać:

- drzewo katalogów,
- opis roli każdego istotnego katalogu,
- listę głównych plików startowych,
- listę zależności między warstwami.

---

# ETAP 2 — MAPA TRAS I PANELI

JUNI ma przejrzeć wszystkie trasy aplikacji.

## 2.1. Trasy publiczne

Sprawdzić:

- strona główna,
- lista artykułów,
- podgląd artykułu,
- wersje językowe,
- logowanie,
- rejestracja,
- reset hasła, jeśli istnieje,
- publiczne SEO,
- sitemap, jeśli istnieje,
- RSS lub feed, jeśli istnieje.

## 2.2. Panele użytkowników

Sprawdzić osobno:

- panel autora,
- panel redakcji,
- panel redaktora głównego,
- panel wydawcy,
- panel moderatora,
- panel korektora,
- panel admina,
- portfel użytkownika,
- historia punktów / Talentów / bonusów.

## 2.3. Mapa tras

Dla każdej trasy opisać:

- URL,
- metodę HTTP,
- kontroler,
- metodę kontrolera,
- widok,
- wymagane role,
- tabele bazy używane po drodze,
- ryzyka bezpieczeństwa.

## 2.4. Wynik etapu

Plik:

```text
docs/AUDYT_MAPA_TRAS_I_PANELI.md
```

---

# ETAP 3 — AUDYT KONTROLERÓW

JUNI ma przejrzeć każdy kontroler metoda po metodzie.

Dla każdej metody trzeba opisać:

- co robi,
- kto może ją wywołać,
- czy sprawdza logowanie,
- czy sprawdza rolę użytkownika,
- jakie dane przyjmuje z requestu,
- czy waliduje dane,
- czy zapisuje do bazy,
- czy odczytuje z bazy,
- czy używa CSRF,
- czy może mieć lukę XSS,
- czy może mieć lukę SQL injection,
- czy może pozwolić użytkownikowi edytować cudze dane,
- jaki widok renderuje,
- jakie serwisy wywołuje.

## Wynik etapu

Plik:

```text
docs/AUDYT_KONTROLERY_METODA_PO_METODZIE.md
```

---

# ETAP 4 — AUDYT SERWISÓW

JUNI ma przejrzeć wszystkie serwisy w `app/Services`.

Dla każdej klasy i funkcji opisać:

- nazwa klasy,
- nazwa metody,
- rola metody,
- wejścia,
- wyjścia,
- tabele bazy,
- kontrolery, które jej używają,
- ryzyka,
- zależności,
- czy metoda jest martwa,
- czy metoda jest krytyczna.

Szczególnie dokładnie sprawdzić:

- artykuły,
- workflow redakcyjny,
- tłumaczenia AI,
- SEO,
- baner główny,
- portfel,
- bonusy,
- płatności,
- role użytkowników,
- instalator,
- konfigurację bazy,
- upload plików,
- obrazki artykułów.

## Wynik etapu

Plik:

```text
docs/AUDYT_SERWISY_FUNKCJA_PO_FUNKCJI.md
```

---

# ETAP 5 — AUDYT BAZY DANYCH

To jest osobny, bardzo ważny etap.

## 5.1. Jedna aktywna baza

Ustalić i potwierdzić, że jedyna aktywna baza aplikacji to:

```text
zrodlo_slowa
```

W repo nie ma być aktywnej drugiej bazy aplikacji.

## 5.2. Legacy nie interesuje projektu

Wszelkie stare wpisy typu:

```text
LEGACY_DB_NAME=bookplmslowo
LEGACY_DB_HOST
LEGACY_DB_USER
LEGACY_DB_PASS
LEGACY_DB_PREFIX
```

traktować jako ślad po imporcie, nie jako aktywną część systemu.

JUNI ma sprawdzić, czy coś z legacy jest jeszcze realnie używane. Jeżeli nie jest używane, przygotować usunięcie albo przeniesienie poza aktywny kod.

Nie robić tego na ślepo — najpierw sprawdzić odwołania.

## 5.3. Katalog database

Docelowy stan katalogu `database`:

```text
database/zrodlo_slowa.sql
```

Czyli w aktywnym katalogu `database` ma być tylko jedna kopia obecnej bazy wynikowej.

Nie chcemy aktywnie trzymać:

```text
database/migrations/
database/seeds/
database/legacy/
database/backup/
```

chyba że JUNI wykaże, że obecny kod aplikacji realnie ich wymaga. Jeżeli wymaga, najpierw raport, potem decyzja.

## 5.4. Migracje

Projekt jest w budowie. Nie potrzebujemy historii 001–057 jako aktywnego mechanizmu.

Zasada:

```text
Nie historia migracji jest źródłem prawdy.
Źródłem prawdy jest obecna baza wynikowa zrodlo_slowa oraz jej kopia database/zrodlo_slowa.sql.
```

JUNI ma sprawdzić, czy instalator, reset albo inne skrypty nie zakładają istnienia `database/migrations/*.sql`.

Jeżeli zakładają, nie usuwać bez poprawienia przepływu.

## 5.5. Kopia bazy w repo

JUNI ma dodać albo potwierdzić mechanizm tworzenia kopii:

```bat
mysqldump -u root --default-character-set=utf8mb4 zrodlo_slowa > database\zrodlo_slowa.sql
```

Można dodać skrypt pomocniczy, np.:

```text
ZROB_KOPIE_BAZY_DO_DATABASE.bat
```

ale sam katalog `database` po wykonaniu porządku ma mieć tylko plik SQL bazy.

Skrypty pomocnicze mogą być w katalogu głównym albo `scripts/`, nie w `database`.

## 5.6. Raport bazy

JUNI ma przygotować:

```text
docs/JEDNA_BAZA_DANYCH_ZRODLO_SLOWA.md
```

Raport ma zawierać:

- potwierdzenie jednej aktywnej bazy,
- nazwę bazy: `zrodlo_slowa`,
- lokalizację kopii w repo: `database/zrodlo_slowa.sql`,
- listę tabel,
- opis roli każdej tabeli,
- najważniejsze kolumny,
- relacje między tabelami,
- informację, czy legacy jest martwe,
- informację, czy migracje są jeszcze aktywnie używane,
- instrukcję aktualizacji kopii SQL.

---

# ETAP 6 — AUDYT WIDOKÓW I FORMULARZY

JUNI ma sprawdzić wszystkie widoki.

Dla każdego widoku opisać:

- gdzie jest używany,
- jaki kontroler go renderuje,
- jakie zmienne otrzymuje,
- czy wypisuje dane przez escape,
- czy formularze mają CSRF,
- czy pola formularza odpowiadają bazie,
- czy role widzą tylko to, co powinny,
- czy UI nie zawiera starych lub martwych przycisków.

Szczególnie sprawdzić:

- formularz artykułu,
- panel autora,
- panel redakcji,
- panel wydawcy,
- panel korektora,
- panel admina,
- formularz baneru głównego,
- widoki tłumaczeń,
- widoki portfela i bonusów.

## Wynik etapu

Plik:

```text
docs/AUDYT_WIDOKI_I_FORMULARZE.md
```

---

# ETAP 7 — AUDYT WORKFLOW ARTYKUŁÓW

JUNI ma dokładnie sprawdzić cały przebieg artykułu.

Obowiązująca zasada stanów redakcyjnych:

```text
submitted → review → approved
```

Redaktor Główny widzi i obsługuje:

- `submitted`,
- `review`,
- `approved`,
- `rejected`.

Po `approved` artykuł ma pojawić się u Wydawcy i Moderatora.

Wydawca i Moderator mogą nadać także:

- `archived`.

Przy autorze jako status artykułu mają być widoczne cztery stany Redakcji Głównej:

- `submitted` — tekst przyszedł od autora,
- `review` — redaktor pracuje nad tekstem / analizuje / podejmuje decyzję,
- `approved` — redakcja zaakceptowała tekst i przekazuje dalej,
- `rejected` — redakcja odrzuca lub cofa.

JUNI ma sprawdzić, czy kod, baza, panele i widoki są z tym zgodne.

## Wynik etapu

Plik:

```text
docs/AUDYT_WORKFLOW_ARTYKULOW.md
```

---

# ETAP 8 — AUDYT TŁUMACZEŃ I SEO

JUNI ma sprawdzić mechanizm tłumaczeń:

- artykułów,
- tytułów,
- leadów,
- treści,
- slugów,
- tagów SEO,
- meta description,
- news tags dla Google,
- wersji językowych publicznych.

## Baner główny

Baner główny ma działać podobnie jak artykuły:

- jedna główna wersja źródłowa,
- przycisk „Tłumacz baner główny”,
- AI generuje wersje językowe,
- późniejsza ręczna edycja każdej wersji językowej,
- formularz redakcyjny, nie techniczny,
- nie wszystkie języki naraz w ciasnych polach.

JUNI ma sprawdzić, czy obecny kod to spełnia albo gdzie są braki.

## Wynik etapu

Plik:

```text
docs/AUDYT_TLUMACZENIA_AI_SEO_BANER.md
```

---

# ETAP 9 — AUDYT PORTFELA, TALENTÓW, BONUSÓW I FINANSÓW

JUNI ma sprawdzić:

- portfel użytkownika,
- Talent / punkty,
- portfel złotówkowy, jeśli występuje,
- przelewy między portfelami,
- prowizje systemu,
- bonusy za aktywność,
- historię bonusów,
- opisy transakcji,
- panel admina finansów,
- raporty finansowe,
- bezpieczeństwo operacji finansowych.

Szczególnie sprawdzić, czy użytkownik nie może:

- dopisać sobie środków,
- edytować cudzych transakcji,
- wykonać przelewu bez autoryzacji,
- obejść walidacji,
- manipulować kwotami przez formularz.

## Wynik etapu

Plik:

```text
docs/AUDYT_PORTFEL_TALENTY_FINANSE.md
```

---

# ETAP 10 — AUDYT BEZPIECZEŃSTWA

JUNI ma przygotować osobny raport bezpieczeństwa.

Sprawdzić:

- logowanie,
- hashowanie haseł,
- sesje,
- CSRF,
- XSS,
- SQL injection,
- upload plików,
- uprawnienia ról,
- IDOR, czyli dostęp do cudzych zasobów po ID,
- endpointy admina,
- endpointy wydawcy,
- endpointy autora,
- endpointy portfela,
- konfigurację `.env`,
- czy sekrety nie są wypisywane publicznie,
- czy błędy aplikacji nie pokazują danych technicznych użytkownikowi.

## Wynik etapu

Plik:

```text
docs/AUDYT_BEZPIECZENSTWO.md
```

---

# ETAP 11 — AUDYT MARTWEGO KODU

JUNI ma wskazać:

- klasy nieużywane,
- metody nieużywane,
- widoki nieużywane,
- trasy nieużywane,
- skrypty historyczne,
- stare importy,
- pozostałości legacy,
- stare migracje,
- stare raporty,
- pliki, które można usunąć dopiero po potwierdzeniu.

Nie kasować automatycznie bez raportu.

## Wynik etapu

Plik:

```text
docs/AUDYT_MARTWY_KOD_I_SMIECI.md
```

---

# ETAP 12 — PORZĄDEK W DATABASE

Dopiero po audycie JUNI ma wykonać porządek w bazie repo, zgodnie z zasadą użytkownika:

> W katalogu `database` ma być tylko kopia obecnej bazy. Tyle.

## Docelowy stan

```text
database/zrodlo_slowa.sql
```

## Co usunąć z aktywnego `database`, jeżeli nie jest już wymagane

- `database/migrations/`
- `database/seeds/`
- `database/legacy/`
- `database/backup/`
- inne stare pliki SQL niebędące aktualną kopią bazy.

## Ważne

Jeżeli kod instalatora albo resetu bazy wymaga migracji, JUNI ma najpierw poprawić te skrypty albo opisać konflikt.

Nie wolno zostawić systemu w stanie, w którym:

- kod oczekuje migracji,
- a migracje zostały usunięte.

## Wynik etapu

- `database/zrodlo_slowa.sql`
- raport:

```text
docs/PORZADEK_DATABASE_JEDEN_PLIK_SQL.md
```

---

# ETAP 13 — DOKUMENTACJA TECHNICZNA

Po pełnym audycie JUNI ma przygotować dużą dokumentację techniczną.

Plik:

```text
docs/DOKUMENTACJA_TECHNICZNA_ZRODLO_SLOWA.md
```

Ma zawierać:

- architekturę aplikacji,
- strukturę katalogów,
- opis routera,
- opis kontrolerów,
- opis serwisów,
- opis bazy danych,
- opis ról,
- opis paneli,
- opis workflow artykułów,
- opis tłumaczeń,
- opis SEO,
- opis portfela,
- opis bonusów,
- opis baneru głównego,
- opis instalacji lokalnej,
- opis konfiguracji `.env`,
- opis aktualizacji kopii bazy,
- listę krytycznych miejsc w kodzie.

---

# ETAP 14 — DOKUMENTACJA OPISOWA / FUNKCJONALNA

JUNI ma przygotować też dokumentację opisową dla człowieka nietechnicznego.

Plik:

```text
docs/DOKUMENTACJA_OPISOWA_ZASADY_DZIALANIA.md
```

Ma opisywać:

- czym jest system,
- jakie są role użytkowników,
- co robi autor,
- co robi redakcja,
- co robi redaktor główny,
- co robi wydawca,
- co robi moderator,
- co robi korektor,
- co robi admin,
- jak działa artykuł od wysłania do publikacji,
- jak działają tłumaczenia,
- jak działa baner główny,
- jak działa portfel,
- jak działają bonusy,
- jak działa SEO,
- jakie są najważniejsze zasady systemu.

---

# ETAP 15 — RAPORT KOŃCOWY JUNI

Na końcu JUNI ma przygotować jeden raport zbiorczy:

```text
docs/RAPORT_KONCOWY_AUDYT_REPO_ZRODLO_SLOWA.md
```

Raport ma zawierać:

- co zostało sprawdzone,
- ile jest kontrolerów,
- ile jest serwisów,
- ile jest widoków,
- ile jest tras,
- ile jest tabel,
- jakie są główne ryzyka,
- co wymaga poprawy natychmiast,
- co można poprawić później,
- co jest martwe,
- co zostało usunięte,
- co zostało zostawione,
- czy `database` ma już tylko `zrodlo_slowa.sql`,
- czy aplikacja nadal działa po zmianach.

---

# TESTY OBOWIĄZKOWE PO ZMIANACH

JUNI ma sprawdzić minimum:

## 1. Składnia PHP

Dla plików PHP w:

- `app/`
- `public/`
- `scripts/`

## 2. Test działania aplikacji

Sprawdzić ręcznie:

- strona główna,
- logowanie,
- panel autora,
- panel redakcji,
- panel redaktora głównego,
- panel wydawcy,
- panel moderatora,
- panel korektora,
- panel admina,
- artykuł publiczny,
- tłumaczenia,
- baner główny,
- portfel,
- bonusy.

## 3. Test bazy

Sprawdzić:

```bat
php scripts\install.php --check
```

Jeżeli ten skrypt wymaga migracji, które mają zostać usunięte, JUNI ma najpierw poprawić jego logikę albo opisać konflikt.

## 4. Test kopii bazy

Sprawdzić, czy istnieje:

```text
database/zrodlo_slowa.sql
```

I czy jest to realna kopia obecnej bazy `zrodlo_slowa`, a nie pusty plik ani sztucznie złożony schemat.

---

# KOMENDY DLA UŻYTKOWNIKA — LARAGON / WINDOWS

Nie podawać użytkownikowi komendy `tar -xf` dla Laragona.

Dla Laragona używać prostych komend Windows / CMD.

## Kopia bazy do projektu

```bat
mysqldump -u root --default-character-set=utf8mb4 zrodlo_slowa > database\zrodlo_slowa.sql
```

## Kontrola instalacji

```bat
php scripts\install.php --check
```

## Kontrola statusu git

```bat
git status
```

---

# ZAKAZ KOŃCOWY

JUNI nie może zrobić paczki, która wygląda na uporządkowaną, ale po cichu zmienia logikę aplikacji.

Jeżeli celem etapu jest tylko:

```text
w database ma być jedna kopia bazy
```

to kod aplikacji ma zostać bez zmian, chyba że JUNI jasno wykaże, że bez minimalnej zmiany system się rozsypie.

Każda zmiana poza `database` musi być osobno uzasadniona w raporcie.

---

# KRÓTKIE PODSUMOWANIE DLA JUNI

Masz wykonać pełny audyt repo ŹRÓDŁO SŁOWA.

Masz sprawdzić każdą funkcję, klasę, kontroler, serwis, widok, trasę i skrypt.

Masz przygotować dwie główne dokumentacje:

1. techniczną,
2. opisową / funkcjonalną.

Masz też uporządkować bazę według zasady:

```text
jedna aktywna baza: zrodlo_slowa
jedna kopia w repo: database/zrodlo_slowa.sql
w database nie trzymamy aktywnych migracji, seedów, legacy ani archiwów
```

Ale nie wolno niczego usuwać na ślepo.

Najpierw sprawdź zależności. Potem raport. Potem zmiana.
