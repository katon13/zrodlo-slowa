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
