# DOKUMENTACJA ODBIORU — ŹRÓDŁO SŁOWA MOBILE (ETAP 9)

Data: 2026-08-04
Moduł: `mobile/zrodlo-slowa-android` (pakiet `pl.zrodloslowa.app`), całkowicie oddzielny od `mobile/3dors-android`.

> Zgodnie z dyspozycją: ETAP 9 = testy, APK, dokumentacja → **ODBIÓR**. Poniżej zbiorcze podsumowanie wszystkich etapów (0–9).

---

## 1. ZAKRES WYKONANY (ETAPY 0–8)

| Etap | Zakres | Status |
|---|---|---|
| 0 | Audyt zależności istniejącego serwisu `X:\zrodlo-slowa` | WYKONANE |
| 1 | Architektura powłoki, stałe dolne menu (5 zakładek), nawigacja | WYKONANE |
| 2 | `SecureWebView`, `WebSessionManager`, logowanie, allowlista 6 domen | WYKONANE |
| 3 | Ekran Główna i Artykuły (WebView, kolorowe zdjęcia z serwera) | WYKONANE |
| 4 | Ekran Portfel (WebView, `/wallet`) | WYKONANE |
| 5 | Powiadomienia (natywna lista, API `/api/earnings/notifications*`) | WYKONANE |
| 6 | Konto / Panel autora (WebView, `/account/settings`) | WYKONANE |
| 7 | Integracja z 3DORS Author (`Dors3AuthorLauncher`, `Dors3ResultHandler`) | WYKONANE |
| 8 | Języki: TT (wyłącznie backend), PL/EN + DE/FR/IT/ES | WYKONANE |
| 9 | Testy, APK, dokumentacja odbioru | **NINIEJSZY DOKUMENT** |

Szczegółowy opis zmian każdego etapu: `docs/AUDYT_ZALEZNOSCI_ZRODLO_SLOWA_MOBILE.md`, pkt 9 (plan implementacji z adnotacjami WYKONANE).

---

## 2. TESTY AUTOMATYCZNE (JVM unit testy)

Uruchomione poleceniem: `gradlew clean testDebugUnitTest assembleDebug` — **BUILD SUCCESSFUL**, bez błędów, bez niepowodzeń testów (tylko nieblokujące ostrzeżenia deprecation, opisane w pkt 4).

Pokrycie testami jednostkowymi (czysta logika, bez zależności od Androida/WebView):

| Plik testowy | Co weryfikuje |
|---|---|
| `navigation/AppDestinationTest.kt` | kolejność 5 zakładek, rozpoznawanie trasy, fallback do „Główna” |
| `config/SiteConfigTest.kt` | 6 wersji językowych/domenowych zgodnych z `config/sites.json` |
| `webview/WebViewAllowlistTest.kt` | allowlista hostów WebView (6 domen produkcyjnych + debug) |
| `webview/WebUrlResolverTest.kt` | wyznaczanie adresu bazowego per język + debug override |
| `dors3/Dors3AuthorLauncherTest.kt` | rozpoznawanie linku zatwierdzenia 3DORS Author (`/3dors/approve/{id}`) |
| `config/AppLanguageManagerTest.kt` | rozpoznawanie języka systemowego z fallbackiem do PL |

Łącznie 6 klas testowych, wszystkie zielone.

---

## 3. TESTY MANUALNE — LISTA KONTROLNA (do wykonania przez odbiorcę na urządzeniu/emulatorze)

- [ ] Uruchomienie aplikacji — widoczne stałe dolne menu (Główna, Artykuły, Portfel, Powiadomienia, Konto), styl ciemny + czerwony akcent.
- [ ] Ekran Główna i Artykuły ładują się z serwisu (kolorowe zdjęcia widoczne, bez zniekształceń).
- [ ] Próba wejścia w Portfel/Konto bez zalogowania → ekran logowania (`/login`) zamiast treści.
- [ ] Poprawne logowanie w WebView → dostęp do Portfela i Konta odblokowany.
- [ ] Portfel: widoczne saldo/kurs TT renderowane przez serwer (WebView), doładowanie/transfer/wypłata otwierają się poprawnie.
- [ ] Powiadomienia: lista ładuje się z `/api/earnings/notifications`, przycisk „Oznacz wszystkie jako przeczytane” działa (wywołuje `ack`).
- [ ] Konto: `/account/settings` ładuje się poprawnie; dla konta z `can_write=1` widoczny link do Panelu autora.
- [ ] Panel autora → link zatwierdzenia 3DORS otwiera zainstalowaną aplikację 3DORS Author (lub przeglądarkę, jeśli niezainstalowana); po powrocie WebView się odświeża.
- [ ] Zmiana języka systemowego urządzenia (np. na niemiecki) → menu i etykiety zmieniają się na odpowiednią wersję językową, aplikacja ładuje właściwą domenę.
- [ ] Link spoza allowlisty (np. z artykułu) otwiera się w przeglądarce zewnętrznej, nie w aplikacji.
- [ ] Błąd certyfikatu TLS (test na środowisku z przechwyconym ruchem) → WebView przerywa ładowanie, nie kontynuuje (`proceed()` nigdy nie jest wywoływane).

---

## 4. ZNANE OGRANICZENIA (udokumentowane, nie obchodzone)

1. **Sesja cross-domenowa**: każda wersja językowa to osobna domena (`config/sites.json`); zmiana języka może wymagać ponownego logowania — nie zaimplementowano (i celowo nie obejdzie się) żadnego mechanizmu kopiowania cookie między domenami.
2. **Natywny wskaźnik Kursu Słowa (TT)**: brak dedykowanego endpointu JSON w backendzie; kurs jest widoczny wyłącznie w renderowanym WebView (`tt_rate_label` liczony przez `PaymentRuntimeConfigService`), zgodnie z audytem ETAPU 0.
3. **Powiadomienia push**: brak potwierdzonej integracji FCM w backendzie — nie zaimplementowano żadnej symulacji push.
4. Ostrzeżenia kompilatora (niebblokujące): `LocalLifecycleOwner` (deprecated, do migracji na `androidx.lifecycle.compose` w przyszłości), `Icons.Filled.Article` (zalecana wersja `AutoMirrored`), deprecated konstruktor `Locale(String, String)` w teście — żadne z nich nie wpływa na działanie aplikacji.

---

## 5. ARTEFAKT — APK DEBUG

Zbudowano: `app/build/outputs/apk/debug/app-debug.apk` (polecenie: `gradlew assembleDebug`, BUILD SUCCESSFUL).

Uwaga: jest to build **debug** (podpisany kluczem debug, z `network_security_config.xml` dopuszczającym cleartext wyłącznie dla `10.0.2.2`/`localhost`/`127.0.0.1` do celów developerskich). Do dystrybucji produkcyjnej wymagane jest osobne wygenerowanie podpisanego builda `release` (poza zakresem tej dyspozycji — brak keystore produkcyjnego w tym przebiegu).

---

## 6. STATUS KOŃCOWY

Wszystkie 9 etapów z dyspozycji zostały zrealizowane wyłącznie w nowym, samodzielnym module `mobile/zrodlo-slowa-android`, bez jakiejkolwiek zmiany w istniejącym backendzie (`X:\zrodlo-slowa`) ani w istniejącej aplikacji `mobile/3dors-android`. Testy jednostkowe przechodzą, APK debug buduje się poprawnie.

**ODBIÓR: gotowe do przekazania do akceptacji użytkownika.**
