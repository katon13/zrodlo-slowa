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
