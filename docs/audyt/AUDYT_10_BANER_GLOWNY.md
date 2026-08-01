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
