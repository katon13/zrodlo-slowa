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
- `platform_revenues`: Rejestr przychodów platformy (prowizje 30%).

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
