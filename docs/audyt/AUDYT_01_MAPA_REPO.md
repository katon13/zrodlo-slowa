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
