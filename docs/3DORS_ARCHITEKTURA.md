# 3DORS — architektura

## Decyzja dla obecnego etapu

3DORS działa w trybie `prepare`. Logowanie administratora pozostaje oparte na loginie lub e-mailu i haśle, a każda podłączona operacja krytyczna wymaga ponownego podania aktualnego hasła. FIDO2 i WebAuthn są przygotowane technicznie, ale pozostają wyłączone. Nie istnieje automatyczne przejście do `test` ani `required`.

Rozwiązanie nie zmienia portfeli, sald, transakcji ani łańcucha księgi finansowej.

## Trzy drzwi

1. **Tożsamość** — logowanie, limit prób administratora, kontrola sesji i jej wersji.
2. **Skarbiec** — jednorazowe potwierdzenie związane z dokładną operacją. W `prepare` dostawcą jest hasło; przyszłe implementacje to FIDO2 i Fizyczna Brama Zgody.
3. **Ślad i odtworzenie** — zdarzenia w PostgreSQL i logach JSON, kody odzyskiwania oraz kontrolowane CLI.

## Tryby

| Tryb | Logowanie | Operacja krytyczna | Stan FIDO2 |
|---|---|---|---|
| `prepare` | hasło, istniejące TOTP jeśli przypisane | świeże hasło | wyłączone |
| `test` | hasło | testowo hasło/FIDO2 | do wdrożenia po zakupie klucza |
| `required` | hasło + FIDO2 | świeże FIDO2 | dopiero po pełnej bramce i jawnej zgodzie |

Kod obecnego etapu obsługuje produkcyjnie tylko `prepare`. Klasy `Fido2StepUpAuthorizer` i `PhysicalApprovalAuthorizer` celowo odmawiają działania. Dzięki wspólnemu interfejsowi późniejsze podłączenie dostawcy nie wymaga przebudowy kontrolerów operacji krytycznych.

## Przepływ step-up

1. Backend normalizuje operację, aktora, zasób, odbiorcę/kwotę/walutę, stan przed i po.
2. `ActionFingerprint` dodaje request ID i czas wygaśnięcia, kanonizuje dane i liczy SHA-256.
3. Powstaje wpis `security_step_up_authorizations` ważny 300 sekund.
4. `PasswordStepUpAuthorizer` sprawdza bieżący hash hasła aktora.
5. Autoryzacja jest atomowo oznaczana jako zużyta. Zmiana kontekstu, wygaśnięcie i replay są odrzucane.
6. Wynik trafia do `security_events` i logu JSON. Dopiero zatwierdzony wynik pozwala kontrolerowi wykonać operację.

Pięć błędnych haseł step-up w ciągu 15 minut blokuje kolejne próby dla aktora. Logowanie administratora ma osobny licznik: po pięciu błędach 15 minut blokady, a kolejne serie zwiększają czas maksymalnie do 6 godzin.

## Sesja administratora

- blokada panelu po 15 minutach bezczynności;
- pełne zakończenie sesji po 8 godzinach;
- odblokowanie wymaga action-bound password step-up;
- zwykłe sesje użytkowników nie otrzymały tych limitów;
- sesje są współdzielone przez `app-1` i `app-2` w Valkey.

## Dane i audyt

Migracja `20260801_009_3dors_prepare.sql` dodaje:

- `security_settings`;
- `webauthn_credentials`;
- `webauthn_challenges`;
- `security_recovery_codes`;
- `security_step_up_authorizations`;
- `security_events`;
- `security_admin_login_locks`.

Zdarzenie bezpieczeństwa zawiera m.in. event ID, aktora, operację, zasób, request/correlation/instance ID, IP, user-agent, poziom uwierzytelnienia, stan przed i po, wynik, powód i ryzyko. Identyfikatory sesji są wyświetlane wyłącznie jako skróty.

## Panel

Panel `Administracja → Bezpieczeństwo — 3DORS` pokazuje tryb, trzy drzwi, konfigurację WebAuthn, sesje, stan kodów, bramkę przyszłego `required` oraz ostatnie zdarzenia i alarm. Kontrolki aktywacji FIDO2 są nieaktywne w `prepare`; nie ma endpointu rejestracji klucza ani przycisku wymuszającego FIDO2.

