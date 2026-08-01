# AUDYT 13 — AI i OpenAI

## 1. Konfiguracja AI
System jest przygotowany do szerokiej integracji z modelami językowymi, z domyślnym wsparciem dla OpenAI. Konfiguracja znajduje się w `config/ai.php` oraz jest nadpisywalna z poziomu bazy danych (`settings`).
- **Klucz API**: Przechowywany w `.env` (`OPENAI_API_KEY`).
- **Model**: Domyślnie `gpt-4.1-mini` lub nowszy, konfigurowalny oddzielnie dla tłumaczeń i audytu.
- **Parametry**: Możliwość ustawienia `temperature`, `max_tokens` oraz limitów dziennych zapytań.

## 2. Architektura procesów AI
Zadania AI nie są wykonywane synchronicznie w głównym wątku żądania (co mogłoby powodować timeouty), lecz są rejestrowane jako "zadania" (`ai_jobs`):
1. **Planowanie**: Administrator lub system tworzy rekord zadania.
2. **Kolejkowanie**: Zadanie otrzymuje status `planned` lub `queued`.
3. **Wykonanie**: Po wywołaniu API OpenAI, status zmienia się na `running`, a po otrzymaniu poprawnego wyniku na `completed`.
4. **Logowanie**: Każdy etap i ewentualne błędy są zapisywane w `ai_job_events`.

## 3. Moduł Tłumaczeń AI
Najbardziej zaawansowana część integracji AI, obsługująca wielojęzyczność artykułów i baneru:
- **TranslationPromptBuilder**: Buduje złożone prompty systemowe i użytkownika, przekazując kontekst kulturowy, religijny i redakcyjny (zasada: "nie poprawiaj autora ideologicznie").
- **Structured Output**: System wymusza na AI zwracanie wyników w formacie JSON zgodnym ze schematem bazy danych, co pozwala na automatyczne wypełnianie pól tytułu, leadu i treści.
- **Review Workflow**: Tłumaczenia AI trafiają najpierw do statusu `ai_draft`, wymagając zatwierdzenia przez ludzkiego edytora.

## 4. Narzędzia administracyjne
Dedykowany panel `admin/ai` pozwala na:
- Monitorowanie budżetu AI i liczby zużytych tokenów.
- Testowanie połączenia z OpenAI.
- Globalną zmianę "dyspozycji dla tłumacza" (instrukcji redakcyjnych dla AI).
- Przegląd historii wszystkich wywołań API.

## 5. Ryzyka i rekomendacje
1. **Koszty API**: System nie posiada twardego limitu "kill-switch" na poziomie kosztów dolarowych w OpenAI, polega jedynie na limitach liczby zadań. Warto dodać monitorowanie kosztów w czasie rzeczywistym.
2. **Błędy formatowania**: Mimo stosowania `Structured Output`, modele AI czasem zwracają błędny JSON przy bardzo długich tekstach. `AiFoundationService` powinien posiadać mechanizm automatycznej ponownej próby (retry) lub naprawy JSON.
3. **Prywatność**: Dane artykułów przesyłane są do zewnętrznego dostawcy (OpenAI). Należy upewnić się, że polityka prywatności serwisu informuje o tym użytkowników/autorów.
