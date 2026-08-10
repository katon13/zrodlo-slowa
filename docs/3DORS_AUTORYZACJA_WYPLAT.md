# Autoryzacja wypłat przez 3DORS Admin

Integracja nie omija istniejącej księgi ani maker-checker. Administrator najpierw przechodzi istniejący critical password step-up. Gdy ręcznie włączone są flagi mobilne i `payout_approval`, zamiast natychmiastowej zmiany tworzona jest odroczona operacja dla 3DORS Admin.

Telefon pokazuje odbiorcę, zamaskowany rachunek, kwotę, walutę, prowizję, sumę, stan przed/po i identyfikator wypłaty. Fingerprint obejmuje ID wypłaty i odbiorcy, hash pełnego rachunku, kwotę minor, walutę, opłatę, sumę, oba statusy i czas wydania. Zmiana dowolnego pola po utworzeniu żądania powoduje `fingerprint_mismatch`.

Po poprawnym approve backend ponownie odczytuje i blokuje wypłatę, przelicza fingerprint, a następnie tworzy istniejące żądanie `FinancialService::requestApproval`. Oznaczenie operacji odroczonej jako wykonanej i zużycie podpisu są w tej samej transakcji. Drugi podpis lub drugi klik nie wykonuje operacji ponownie. Reject anuluje operację bez zmiany wypłaty.

W testach tej zmiany nie modyfikowano sald ani księgi; pieniądze środowiska developerskiego nie zostały użyte do wykonywania payoutów. Przed testem E2E należy utworzyć osobną testową wypłatę i potwierdzić wpisy maker-checker oraz ledger po obu decyzjach.
