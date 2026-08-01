# 3DORS — operacje krytyczne

## Zasada

Operacja krytyczna jest wykonywana dopiero po jednorazowym potwierdzeniu aktualnym hasłem aktora. Potwierdzenie obejmuje dokładny typ operacji, aktora, zasób, szczegóły finansowe lub odbiorcę, stan przed i po, request ID oraz wygaśnięcie. Nie istnieje ogólna flaga typu `fido_ok=true`.

## Operacje chronione w obecnym interfejsie

- żądanie zmiany statusu wypłaty;
- zatwierdzenie i odrzucenie transferu;
- wykonanie i odrzucenie finansowego maker-checker;
- ręczne oznaczenie płatności jako opłaconej;
- zmiana ustawień płatności;
- żądanie ręcznej nagrody/korekty punktowej;
- zmiana reguł zarobków i ustawień SNAJPERA;
- zmiana statusu konta, roli, ról redakcyjnych i uprawnień operacyjnych;
- zatwierdzenie autora;
- anonimizacja i twarde czyszczenie konta;
- administracyjne wyłączenie TOTP;
- zmiana ustawień strony;
- zmiana ustawień administracyjnych AI;
- wygenerowanie i potwierdzenie zapisania kodów odzyskiwania;
- odblokowanie bezczynnej sesji administratora.

Formularz bez pola `critical_password`, błędne hasło, zmiana kwoty/odbiorcy/zasobu, wygaśnięcie albo replay kończą się odmową i zdarzeniem audytowym.

## Operacje przyszłe

Obecna aplikacja nie udostępnia webowych operacji zmiany e-maila/hasła administratora, rejestracji i odwołania FIDO2, włączania `required`, eksportu danych wrażliwych ani odtwarzania backupu. Gdy powstaną, muszą użyć `CriticalOperationAuthorizerInterface`, pełnego fingerprintu i `session_version` tam, gdzie zmienia się tożsamość lub dostęp.

Backup i restore pozostają operacjami lokalnymi/CLI. Nie wolno wystawiać ich jako niezabezpieczonego endpointu HTTP.

## Integracja nowej operacji

1. Sprawdź szczegółowe uprawnienie aktora.
2. Pobierz i zamroź stan przed operacją.
3. Znormalizuj dokładny zamierzony stan po operacji.
4. Wywołaj authorizer przed mutacją.
5. Wykonaj mutację atomowo i idempotentnie.
6. Zapisz domenowy audyt operacji; audyt step-up nie zastępuje audytu finansowego.
7. Dodaj test zmiany kwoty/zasobu, wygaśnięcia, replay i braku hasła.

Nie wolno umieszczać hasła, kodu odzyskiwania, sekretu API ani pełnego credential ID w logach lub komunikatach błędów.

