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
