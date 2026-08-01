# Audyt bezpieczeństwa przed wdrożeniem 3DORS

Data audytu: 2026-08-01  
Zakres: wyłącznie `X:\zrodlo-slowa`  
Planowany tryb: `prepare`  
FIDO2: wyłączone do czasu zakupu i jawnej decyzji właściciela

## Decyzja architektoniczna

Pomysł 3DORS jest zasadny w obecnej konfiguracji i rekomenduję jego wdrożenie etapowe.
Największą wartością nie jest sam przyszły klucz USB, lecz rozdzielenie ochrony na trzy niezależne warstwy:

1. potwierdzenie tożsamości administratora;
2. osobna, jednorazowa zgoda na dokładnie opisaną operację krytyczną;
3. ustrukturyzowany ślad oraz kontrolowane odzyskiwanie.

Obecna aplikacja ma już wystarczające fundamenty, aby wdrożyć `prepare` addytywnie, bez przebudowy finansów, księgi, workerów ani ścieżek zwykłych użytkowników. Model `prepare -> test -> required` istotnie ogranicza ryzyko zablokowania jedynego administratora.

Nie rekomenduję kupowania ani aktywowania klucza przed ukończeniem `prepare`, testów dwóch instancji i recovery CLI. Klucz ma wzmacniać gotowy mechanizm step-up, a nie być pierwszym elementem tego mechanizmu.

## Korekty przyjęte do założeń

- Logowanie administratora pozostaje dostępne przez nazwę `katon` **lub** e-mail, zgodnie z istniejącą obsługą obu identyfikatorów.
- Kody odzyskiwania nie będą generowane ani ujawniane automatycznie. Administrator wygeneruje je świadomie, po ponownym podaniu hasła; system pokaże je tylko raz i zapisze wyłącznie hashe.
- Biblioteka WebAuthn zostanie przypięta w `composer.lock`, ale rejestracja i weryfikacja klucza pozostaną niedostępne, dopóki `DORS3_FIDO2_ENABLED=false`.
- Nie powstanie atrapa FIDO2 ani Fizycznej Bramy Zgody zwracająca pozytywny wynik.
- Testy 3DORS nie mogą modyfikować portfela administratora ani korzystać z trwałych, stałych kluczy idempotencji we współdzielonej bazie deweloperskiej.
- Przejście do `required` nie będzie dostępne w obecnym etapie. Samo ustawienie pojedynczej zmiennej środowiskowej nie może omijać bramki gotowości.

## Stan zastany

### Mocne fundamenty

| Obszar | Stan |
|---|---|
| Sesje | Współdzielone między `app-1` i `app-2` przez Valkey, z fallbackiem PostgreSQL |
| Unieważnianie sesji | Pole `users.session_version` jest sprawdzane przy każdym uruchomieniu aplikacji |
| Identyfikator logowania | `AuthService` obsługuje nazwę logowania i e-mail |
| Hasła | Hashowanie przez `password_hash`, z obsługą `PASSWORD_PEPPER` |
| Limity logowania | Istnieje limit per identyfikator i per IP, z Valkey albo bazą danych |
| TOTP | Istniejące 2FA pozostaje zachowane; sekret korzysta z wymiennego szyfrowania |
| Role | Istnieje katalog szczegółowych uprawnień, nie tylko podział admin/użytkownik |
| CSRF | Żądania POST są domyślnie chronione |
| Audyt HTTP | Administracyjne i finansowe POST-y tworzą wpis audytowy oraz log JSON |
| Infrastruktura | Dwie instancje aplikacji, Valkey i PostgreSQL są odpowiednie dla współdzielonych challenge'y |

### Luki wymagające `prepare`

1. Obecny `CriticalActionGuard` sprawdza jedynie czas ostatniego silnego uwierzytelnienia. Nie wiąże zgody z typem operacji, zasobem, odbiorcą, kwotą ani stanem danych.
2. Brakuje jednorazowego `action_fingerprint` i trwałej informacji o zużyciu autoryzacji.
3. Administrator nie ma oddzielnego limitu bezczynności 15 minut i maksymalnego czasu sesji 8 godzin.
4. Audyt `admin_audit_logs` ma ogólny JSON, ale brakuje tabeli z wymaganymi, wyszukiwalnymi polami zdarzenia bezpieczeństwa.
5. Nie istnieją modele credentiali WebAuthn, challenge'y, autoryzacji step-up, ustawień 3DORS ani kodów odzyskiwania.
6. Nie istnieje lokalna, kontrolowana procedura odzyskania administratora.
7. Krytyczne akcje finansowe i uprawnieniowe wymagają roli i CSRF, ale nie wymagają ponownego hasła związanego z konkretną operacją.
8. Brakuje panelu pokazującego stan trzech drzwi i bramkę przyszłego `required`.

## Stan uruchomionej konfiguracji przed zmianami

- administratorzy: `1`;
- administrator docelowy: ID `4`, login `katon`;
- `session_version`: `2`;
- TOTP administratora: wyłączone;
- tabele 3DORS: `0/6`;
- aplikacja: PHP `8.3.30`;
- `app-1`, `app-2`, proxy, PostgreSQL, Valkey i workery: uruchomione;
- publiczny port aplikacji: `8080`;
- PostgreSQL: `5433`;
- Valkey: `6380`;
- Mailpit: `8025`;
- MinIO Console: `19001`;
- Laragon i jego porty nie są częścią wdrożenia.

### Migawka finansowa do kontroli regresji

| Wartość | Stan przed 3DORS |
|---|---:|
| Portfele | 1 |
| Punkty `katon` | 129362 |
| Środki główne dostępne | 0 |
| Środki główne zarezerwowane | 0 |
| Środki SŁOWO dostępne | 0 |
| Środki SŁOWO zarezerwowane | 0 |
| Liczba pozycji księgi w chwili migawki | 1 |

Wdrożenie 3DORS nie może zmienić żadnej z wartości sald. Liczba technicznych/testowych rekordów innych tabel może się zmieniać wyłącznie w odizolowanych testach.

## Uwaga o obecnym zestawie testów

Bazowy przebieg PHPUnit wykonał 108 testów. Jeden istniejący test `DurableJobQueueTest::testSchedulerAndMailKeysAreIdempotent` nie jest powtarzalny, ponieważ używa stałego slotu `2026-08-01 12:34:45` i koliduje z rekordem pozostawionym przez wcześniejszy przebieg. Nie jest to regresja 3DORS, ale test musi otrzymać unikalny identyfikator przed końcowym testem regresji.

Saldo administratora po przebiegu pozostało zgodne z migawką: 129362 punktów i wszystkie salda pieniężne równe zero.

## Zakres wdrożenia przed zakupem klucza

1. Addytywna migracja sześciu wymaganych tabel oraz pomocniczego stanu blokad administracyjnych.
2. Konfiguracja `DORS3_MODE=prepare`, z twardym zakazem przypadkowego `required`.
3. Administrator-specific rate limiting i ustrukturyzowane zdarzenia bezpieczeństwa.
4. Polityka sesji administracyjnej: 15 minut bezczynności, 8 godzin maksymalnie.
5. Jednorazowy, związany z operacją password step-up z TTL 5 minut.
6. Ochrona obecnych operacji finansowych, uprawnieniowych i bezpieczeństwa.
7. Panel „Bezpieczeństwo — 3DORS”.
8. Generowanie i potwierdzanie kodów odzyskiwania po step-up.
9. Lokalny recovery CLI bez publicznego odpowiednika HTTP.
10. Przypięta biblioteka WebAuthn oraz nieaktywne adaptery FIDO2/FBZ.
11. Testy bez klucza, test dwóch instancji i kontrola niezmienności sald.

## Elementy celowo niewdrażane teraz

- aktywacja lub rejestracja FIDO2;
- tryb `test` albo `required`;
- logowanie bezhasłowe;
- Fizyczna Brama Zgody;
- WAF, SIEM, KMS, HSM i zewnętrzny Secret Manager;
- zmiany finansów, księgi, zasad zarabiania i workerów;
- automatyczne generowanie lub wysyłanie kodów odzyskiwania.

## Rollback

- migracje są wyłącznie addytywne;
- poprzednia wersja aplikacji ignoruje nowe tabele;
- domyślne `DORS3_FIDO2_ENABLED=false` i `DORS3_FIDO2_REQUIRED=false` nie blokują logowania;
- wyłączenie kodu `prepare` nie zmienia hasha hasła, starego TOTP, sald ani księgi;
- przed każdą zmianą trybu wymagane będą audyt, powód i unieważnienie starych sesji;
- `required` nie jest osiągalne w obecnym wdrożeniu.

## Wniosek

3DORS pasuje do obecnej architektury i jest uzasadnionym kolejnym krokiem. Szczególnie trafna jest decyzja, aby najpierw wdrożyć operacyjny `prepare`, a dopiero później kupić i zarejestrować dwa klucze. Dzięki temu przyszła instalacja klucza będzie wymianą autora drugich drzwi z hasła na FIDO2, a nie ryzykowną przebudową logowania i finansów.
