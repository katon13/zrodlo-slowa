# AUDYT 09 — Artykuły, redakcja, korekta, wydawca

## 1. Cykl życia artykułu (Stany)
Artykuł w systemie przechodzi przez następujące statusy:
1. **draft**: Szkic autora, widoczny tylko dla niego i administracji.
2. **submitted**: Tekst przesłany do redakcji, zablokowany do edycji dla autora.
3. **review**: Tekst w trakcie analizy przez Redaktora Głównego.
4. **approved**: Zaakceptowany merytorycznie, przekazany do Wydawcy/Korektora.
5. **rejected**: Odrzucony przez redakcję (wraca do autora jako szkic).
6. **published**: Opublikowany publicznie na stronie.
7. **archived**: Wycofany z publikacji, dostępny w archiwum.

## 2. Role redakcyjne i uprawnienia

### Redaktor Główny (`chief_editor`)
- Odpowiada za pierwszy etap selekcji.
- Obsługuje przejście: `submitted → review → approved` lub `rejected`.
- Ma pełny wgląd w nowo nadesłane materiały.

### Wydawca (`publisher`)
- Zarządza artykułami ze statusem `approved`.
- Decyduje o: kolejności wyświetlania (`display_order`), wadze redakcyjnej (`editorial_weight`), wyróżnieniu (`is_featured`) i ostatecznym momencie publikacji (`published_at`).
- Może archiwizować teksty.

### Moderator (`moderator`)
- Skupia się na aspektach biznesowych artykułu.
- Ustawia: tryb dostępu (`access_mode`: free/paid), cenę (`price_minor`), status premium (`is_premium`).
- Decyduje o dopuszczeniu tekstu do tłumaczenia AI.

### Korektor (`proofreader`)
- Widzi artykuły tak jak Wydawca.
- **Uprawnienia ograniczone**: może edytować wyłącznie `lead` i `body`.
- Nie może zmieniać tytułu, zdjęcia, danych finansowych ani statusu publikacji.
- Po zapisaniu zmian przez Korektora, system generuje zdarzenie `proofread_saved` (informacja **KOREKTA** dla Wydawcy i Autora).

## 3. Workflow tłumaczeń
1. Artykuł źródłowy (zazwyczaj PL) zostaje zatwierdzony.
2. Moderator/Edytor zleca tłumaczenie AI (OpenAI).
3. Powstaje rekord w `article_translations` ze statusem `ai_draft`.
4. Edytor przegląda tłumaczenie, nanosi poprawki i zmienia status na `approved` (w ramach wersji językowej).
5. Publiczna widoczność wersji językowej następuje automatycznie, gdy artykuł główny ma status `published` i dana wersja jest oznaczona jako gotowa.

## 4. Ryzyka i rekomendacje
1. **Blokada edycji**: Autor traci możliwość poprawy tekstu po `submit`. Warto rozważyć mechanizm "prośby o wycofanie" lub automatycznego odblokowania przy statusie `rejected`.
2. **Korekta a Tłumaczenia**: Zmiany wprowadzone przez Korektora w tekście źródłowym nie aktualizują automatycznie już istniejących tłumaczeń. Wymaga to manualnej interwencji lub ponownego zlecenia AI.
3. **Powiadomienia**: System posiada kolejkę maili, ale warto upewnić się, że każde przejście stanu (szczególnie `approved` i `rejected`) generuje powiadomienie dla autora.
