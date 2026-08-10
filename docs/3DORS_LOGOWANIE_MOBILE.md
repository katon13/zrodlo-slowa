# Logowanie z 3DORS Mobile

## Tryby

- `disabled` — zachowanie istniejącego logowania bez zmian.
- `test` — mobilne potwierdzenie jest używane tylko po ręcznym włączeniu flagi i gdy konto ma aktywne urządzenie; niedostępność komponentu nie blokuje dotychczasowego logowania.
- `required` — brak urządzenia, błąd bazy lub brak decyzji blokuje logowanie; nie ma cichego fallbacku. Ten tryb nie został włączony.

Po poprawnym haśle i istniejących czynnikach 2FA backend wybiera wariant na podstawie zweryfikowanej roli sesji. Tworzy krótkie żądanie `auth.login`, wiąże je z hashem sesji przeglądarki i przekierowuje na ekran oczekiwania. Telefon pobiera dane z backendu, pokazuje konto, rolę, środowisko i urządzenie inicjujące, następnie wymaga biometrii/PIN i podpisuje approve albo reject.

Przeglądarka odpytuje status żądania związany z własną sesją. Po `approved` do `AuthenticationContext` dopisywany jest czynnik `mobile_admin` albo `mobile_author`. Reject, TTL lub niewłaściwa sesja nie kończą logowania.

Android odpytuje aktywne żądanie maksymalnie przez 60 sekund na wejściu do aplikacji. Dalsze odświeżanie jest świadome; nie ma nieskończonego odpytywania w tle ani push w tej wersji.

Rekomendowana kolejność włączenia: migracja, jedno konto testowe, jedno urządzenie, `DORS3_MOBILE_ENABLED=true`, właściwa flaga aplikacji, `DORS3_MOBILE_MODE=test`; dopiero po pełnym E2E można osobną decyzją rozważyć `required`.
