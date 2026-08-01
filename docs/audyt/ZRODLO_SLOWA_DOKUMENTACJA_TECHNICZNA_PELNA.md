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
