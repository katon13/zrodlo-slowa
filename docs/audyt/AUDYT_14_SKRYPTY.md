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
