# Autoryzacja artykułów przez 3DORS Author

Mobilny podpis jest uruchamiany wyłącznie dla końcowej operacji `article.submit` lub `article.publish`. Tworzenie szkicu, edycja i autosave nie wywołują telefonu.

## Wysłanie do redakcji

Autor musi mieć aktywne konto, rolę `author`, `can_write=1`, brak blokady wysyłania oraz aktywne urządzenie Author. Backend tworzy operację odroczoną. Po approve ponownie sprawdza właściciela i status szkicu, przelicza fingerprint i wywołuje istniejący `ArticleService::submit` dokładnie raz. Reject pozostawia szkic bez zmiany.

## Publikacja

W tej wersji publikacja przez Author jest dostępna tylko właścicielowi zatwierdzonego tekstu, który ma także rolę `publisher` lub `chief_editor`. Po podpisie wykonywane jest przejście `approved -> published`. Uprawnienie i stan są ponownie sprawdzane serwerowo.

Fingerprint obejmuje ID artykułu i wersji, hash tytułu i treści, autora, organizację, widoczność, język, status docelowy, manifest załączników, manifest źródeł oraz czas wydania. Telefon pokazuje tytuł, autora, wersję, liczbę załączników, widoczność i skutek operacji.

Ochrona uzupełniająca: maks. 240 znaków tytułu, 2000 leadu, 500 000 treści, pojedynczy obraz główny do 5 MB, rate limit wysłania 10/10 min per konto i 30/10 min per IP (gdy Valkey jest dostępny), limit oczekujących żądań i deduplikacja per zasób. Istniejąca blokada autora/SNAJPER pozostaje niezależną warstwą decyzji.
