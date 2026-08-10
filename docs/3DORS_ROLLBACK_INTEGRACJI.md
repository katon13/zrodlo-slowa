# Rollback integracji 3DORS Mobile

## Natychmiastowe wyłączenie

Najbezpieczniejszy rollback nie wymaga zmian schematu:

```text
DORS3_MOBILE_ENABLED=false
DORS3_MOBILE_MODE=disabled
DORS3_ADMIN_APP_ENABLED=false
DORS3_AUTHOR_APP_ENABLED=false
DORS3_ARTICLE_SUBMIT_APPROVAL=false
DORS3_ARTICLE_PUBLISH_APPROVAL=false
DORS3_PAYOUT_APPROVAL=false
DORS3_ADMIN_CRITICAL_APPROVAL=false
```

Po zmianie przeładować obie instancje aplikacji. Istniejące logowanie, password step-up, maker-checker, artykuły, FIDO2 foundation i zwykli użytkownicy wracają do wcześniejszej ścieżki. Jeśli kiedykolwiek uruchomiono `required`, pierwszym krokiem awaryjnym jest `required -> test`, a następnie wyłączenie poszczególnych flag; tryb required sam nie wykonuje fallbacku.

## Dane

Migracja jest addytywna. Nie usuwać tabel podczas incydentu: zachowują audyt i dowody podpisów. Oczekujące requesty można anulować kontrolowaną migracją/operacją po zarchiwizowaniu listy; nie wykonywać `down -v`, resetu bazy ani kasowania księgi. Tabele mobilne nie są źródłem sald.

## Klient

Wycofać dystrybucję obu APK osobno. Unieważnić odpowiedni release signing key tylko przy kompromitacji, usunąć/zmienić Digital Asset Links i oznaczyć urządzenia jako revoked/lost w panelu. Wyłączenie jednego wariantu nie wymaga wyłączenia drugiego.

## Weryfikacja rollbacku

- zwykłe logowanie działa bez challenge mobile;
- zapis/wysłanie artykułu działa zgodnie z poprzednimi flagami;
- wypłata przechodzi wyłącznie istniejącym password step-up + maker-checker;
- brak nowych `security_mobile_approval_requests`;
- audyt i historyczne podpisy pozostają czytelne;
- FIDO2 nie zmieniło swojego stanu.

Rollback kodu wykonać osobnym revertem po zebraniu stanu `git diff`; nie używać `git reset --hard` w brudnym workspace i nie usuwać danych użytkownika.
