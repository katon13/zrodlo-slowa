# 3DORS — checklista pilota na telefonach fizycznych

Stan przed pilotem: integracja developerska jest przygotowana, lecz flagi 3DORS
pozostają wyłączone. Czytelnik nie instaluje 3DORS i nadal loguje się hasłem.
3DORS Author jest wyłącznie dla dziennikarza/autora z aktywnym uprawnieniem;
prawo do wypłaty jest osobnym statusem. 3DORS Admin jest dla administratora.

## 1. Przygotowanie połączenia developerskiego

Na każdym telefonie włącz debugowanie USB, zaakceptuj odcisk komputera i sprawdź:

```powershell
adb devices -l
adb reverse tcp:8080 tcp:8080
```

Zbuduj APK korzystające na telefonie z tunelu do lokalnego backendu:

```powershell
cd mobile\3dors-android
.\gradlew.bat clean assembleAdminDebug assembleAuthorDebug `
  -PDORS3_DEBUG_API_BASE_URL=http://127.0.0.1:8080/
```

Nie używaj na telefonie domyślnego `10.0.2.2`; ten adres działa wyłącznie w
emulatorze. Nie otwieraj developerskiego portu backendu w sieci Wi-Fi. Dla testu
bez USB należy przygotować osobny HTTPS i zaufany certyfikat.

## 2. Instalacja i identyfikacja

```powershell
adb install -r app\build\outputs\apk\admin\debug\app-admin-debug.apk
adb install -r app\build\outputs\apk\author\debug\app-author-debug.apk
```

Sprawdź dwie osobne aplikacje i finalne logo na launcherze:

- `pl.zrodloslowa.dors3.admin` — 3DORS Admin;
- `pl.zrodloslowa.dors3.author` — 3DORS Author.

Jeżeli istnieje stary pakiet `pl.zrodloslowa.mobile`, odinstaluj wyłącznie ten
pakiet testowy, aby nie pomylić starej ikony z aktualnymi wariantami.

## 3. Scenariusze obowiązkowe

1. Enrollment Admin: kod porównawczy, ponowne hasło administratora, zatwierdzenie
   w tej samej sesji panelu; telefon nie może sam się aktywować.
2. Enrollment Author: konto czytelnika ma być odrzucone; aktywny autor z prawem
   redakcyjnym ma przejść; `payout_enabled=0` nie może odebrać prawa redakcyjnego.
3. Approve/reject, wygaśnięcie TTL, podwójne kliknięcie i replay — operacja ma
   wykonać się najwyżej raz.
4. Cofnięcie roli autora i `can_write` należy sprawdzić ponownie bezpośrednio
   przed operacją redakcyjną. `wallet_enabled` i `payout_enabled` nie mogą jej
   blokować; operacje wypłat trafiają do 3DORS Admin.
5. Zawieszenie, oznaczenie jako utracone i revoke urządzenia mają natychmiast
   blokować kolejne decyzje.
6. Sprawdź czytelność, zmianę orientacji, powiększenie fontu oraz maskę ikony
   okrągłą i zaokrągloną na co najmniej dwóch wersjach Androida.

## 4. Czego ten pilot jeszcze nie zatwierdza

- produkcyjnych domen, TLS, App Links i `assetlinks.json`;
- produkcyjnych kluczy podpisu i ich rotacji;
- serwerowej Android Key Attestation;
- pełnej ceremonii FIDO2 ani fizycznych kluczy bezpieczeństwa;
- włączenia trybu `required` lub publikacji w sklepie.

FIDO2 i attestation pozostają jawnie niewłączone, dopóki nie zostaną wdrożone i
odebrane jako osobny etap. Pilot telefonu nie może być przedstawiany jako ich test.
