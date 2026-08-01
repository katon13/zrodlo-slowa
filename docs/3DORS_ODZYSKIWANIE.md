# 3DORS — odzyskiwanie

## Kody odzyskiwania

Panel 3DORS potrafi wygenerować dokładnie 10 losowych kodów. W bazie trafiają wyłącznie ich hashe z pepperem. Jawne kody są pokazane tylko raz i nie są generowane automatycznie podczas wdrożenia.

Nowy zestaw unieważnia poprzednie aktywne zestawy. Po bezpiecznym zapisaniu offline administrator potwierdza komplet w panelu. Użycie jednego potwierdzonego kodu jest atomowe, jednorazowe i unieważnia pozostałe kody zestawu.

Nie przechowuj kodów na tym samym serwerze, w repozytorium, screenshotach panelu ani w menedżerze haseł dostępnym wyłącznie przez odzyskiwane konto.

## Kontrolowane CLI

CLI nie ma trasy HTTP. Przed rzeczywistym odzyskiwaniem zatrzymaj procesy aplikacji, wykonaj backup i przygotuj lokalny plik zawierający jeden kod. Plik nie może znajdować się w repozytorium; na Linuksie ustaw uprawnienia `0600`.

Pełny tekst potwierdzenia dla administratora o ID `4`:

```text
ODZYSKUJE ADMINA 4 I UNIEWAZNIAM KLUCZE 3DORS
```

Przykład uruchomienia wewnątrz kontrolowanego kontenera lub hosta z konfiguracją bazy:

```powershell
php scripts/security_recover_admin.php --admin-id=4 --token-file="C:\secure\dors3-code.txt" --confirm="ODZYSKUJE ADMINA 4 I UNIEWAZNIAM KLUCZE 3DORS" --reason="Kontrolowane odzyskanie po utracie obu kluczy"
```

Kod nie jest argumentem procesu i nie trafia do historii poleceń. Po powodzeniu natychmiast usuń bezpiecznie plik roboczy.

## Skutek procedury

- wybrany kod zostaje zużyty, a pozostałe unieważnione;
- credentiale administratora zostają odwołane;
- sesje administratora zostają usunięte;
- `session_version` wzrasta;
- `required` może zostać obniżony wyłącznie do `test`, nigdy do niechronionego `off`;
- hasło administratora nie jest zmieniane;
- powstaje zdarzenie krytyczne i raport JSON w `storage/security-recovery` z uprawnieniami `0600`.

Jeśli odzyskiwanie zmieniło bazę z `required` na `test`, przed ponownym uruchomieniem należy zgodnie ustawić środowisko na `test`. Niespójność trybu zatrzyma aplikację fail-closed.

W obecnym trybie `prepare` nie uruchamiaj CLI bez realnej potrzeby. Wdrożenie nie utworzyło żadnego kodu i nie zmieniło konta administratora.

