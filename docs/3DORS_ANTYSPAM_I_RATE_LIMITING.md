# 3DORS — antyspam i rate limiting

Podpis telefonu ogranicza przejętą sesję i automatyczne wykonanie końcowej operacji, ale nie zastępuje WAF, reverse proxy, limitów przepustowości ani ochrony DDoS.

## Limity mobilne w PostgreSQL

| Zakres | Limit |
|---|---|
| start enrollmentu | 6 / 5 min per administrator |
| complete enrollmentu | 20 / 5 min per IP |
| utworzenie approval | 20 / min per konto i wariant |
| decyzja | 30 / min per device (lub IP bez device) |
| heartbeat | 30 / min per device |
| pending | konfigurowalny, domyślnie 10 per konto |

Licznik jest wspólny dla instancji. Wygasłe pending są sprzątane przed nowym żądaniem. Unikalny indeks pozwala na jedną oczekującą operację danego typu i zasobu per użytkownik/wariant. Identyczny retry zwraca istniejący request (`deduplicated=true`), a zmieniony fingerprint przy aktywnym request kończy się konfliktem.

## Operacje redakcyjne

- 10 wysłań / 10 min per konto i 30 / 10 min per IP przez wspólny limiter Valkey;
- limit tytułu 240, leadu 2000 i treści 500 000 znaków;
- pojedynczy obraz główny, do 5 MB, tylko sprawdzone MIME JPG/PNG/WEBP i konwersja do WEBP;
- istniejąca ręczna blokada wysyłania autora i SNAJPER/antyfraud pozostają aktywne;
- content/attachment/source hashes w fingerprint wykrywają zmianę między prośbą a podpisem;
- ciężka analiza, indeksowanie i skanowanie pozostają zadaniami workerów; autosave nie tworzy approval.

Limity aplikacyjne należy uzupełnić limitami Nginx/Apache/LB per trasę. Awaria Valkey nie może odciąć użytkowników, ale mobilne krytyczne limity DB nadal działają; alert operacyjny powinien wtedy wymusić naprawę Valkey.
