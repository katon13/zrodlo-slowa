# Polityki 3DORS Mobile i FIDO2

Klucz USB pozostaje niezależnym czynnikiem. Integracja mobilna nie usuwa tabel WebAuthn, challenge, recovery codes, step-up authorizations ani istniejącego panelu FIDO2.

| Polityka | Znaczenie |
|---|---|
| `mobile` | wymagany poprawny podpis właściwego wariantu telefonu |
| `fido2` | wyłącznie FIDO2; endpoint mobilny odrzuca próbę |
| `mobile_or_fido2` | docelowo wystarczy jeden z czynników; ścieżka mobilna akceptuje mobile |
| `mobile_and_fido2` | wymagany mobile i niezużyta autoryzacja FIDO2 o tym samym użytkowniku i action fingerprint |

Polityki są zapisane w `security_mobile_operation_policies` i domyślnie mają `enforced=0`. Dla `mobile_and_fido2` serwis blokuje rekord matching FIDO2 `FOR UPDATE` i zużywa go w tej samej transakcji co podpis mobilny oraz operację. Brak lub replay FIDO2 kończy się fail-closed.

## Aktualny stan

Integracja bazodanowa i egzekwowanie policy są gotowe. Fizyczny WebAuthn/FIDO2 E2E nie został wykonany: istniejący `Fido2StepUpAuthorizer` projektu pozostaje kontrolowanym fundamentem/stubem do czasu dostarczenia klucza i kompletnej konfiguracji RP/origin. Nie należy ustawiać `enforced=1` dla `fido2` ani `mobile_and_fido2` przed tym odbiorem.

Odzyskanie po jednoczesnej utracie telefonu i klucza nie może korzystać z cichego fallbacku. Należy użyć istniejących recovery codes/procedury administracyjnej, unieważnić stare credentiale i przeprowadzić nowe enrollmenty.
