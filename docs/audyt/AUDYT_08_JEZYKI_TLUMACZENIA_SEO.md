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
