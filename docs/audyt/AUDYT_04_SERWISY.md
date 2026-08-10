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
- **TalentService.php**: Przyznawanie punktów Talent i bonusów za aktywność (logowanie, czytanie, komentarze).
- **WalletTopupService.php**: Procesowanie doładowań (m.in. ze Stripe).
- **ArticleEconomyService.php**: Obsługa zakupu artykułów premium (wersjonowany podział 40/40/20).

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
