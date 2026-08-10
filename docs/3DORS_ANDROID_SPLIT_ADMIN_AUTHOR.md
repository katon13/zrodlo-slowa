# 3DORS Android — rozdzielenie Admin i Author

Projekt pozostaje jednym repozytorium Gradle, ale tworzy dwa fizycznie różne produkty przez flavor dimension `applicationVariant`.

| Produkt | applicationId | źródło polityki | przeznaczenie |
|---|---|---|---|
| 3DORS Admin | `pl.zrodloslowa.dors3.admin` | `app/src/admin/.../VariantPolicy.kt` | wypłaty, role, bezpieczeństwo, urządzenia |
| 3DORS Author | `pl.zrodloslowa.dors3.author` | `app/src/author/.../VariantPolicy.kt` | artykuły, materiały poufne, własne dane wypłat |

Wspólne są wyłącznie protokół, Keystore, sieć, biometria, bezpieczne ekrany i modele neutralne domenowo. Klasa `VariantPolicy` istnieje osobno w każdym source secie; kod drugiego wariantu nie jest wejściem do kompilacji. Backend dodatkowo weryfikuje `application_variant` i allowlistę operacji.

## Komendy

```powershell
$env:JAVA_HOME='C:\Program Files\Android\Android Studio\jbr'
.\gradlew.bat testAdminDebugUnitTest testAuthorDebugUnitTest --max-workers=2
.\gradlew.bat assembleAdminRelease assembleAuthorRelease --max-workers=2
```

Artefakty developerskie (niepodpisane release):

- `app/build/outputs/apk/admin/release/app-admin-release-unsigned.apk`
- `app/build/outputs/apk/author/release/app-author-release-unsigned.apk`

## Kontrola APK

`apkanalyzer manifest application-id` zwrócił dwa różne identyfikatory. Dekompilacja klasy polityki zwróciła dla Author wyłącznie m.in. `article.submit`, `article.publish`, `confidential_material.access`, a dla Admin m.in. `payout.approve`, `role.change`, `security.settings.change`. W Author nie znaleziono adminowej polityki wypłat ani ustawień bezpieczeństwa.

Przed dystrybucją należy ponowić analizę po ustawieniu R8 oraz prawdziwych hostów:

```powershell
apkanalyzer.bat dex code --class pl.zrodloslowa.mobile.variant.VariantPolicy <apk>
apkanalyzer.bat manifest print <apk>
```

## Bezpieczeństwo buildów

- backup danych jest zabroniony w obu produktach;
- release blokuje placeholdery hosta/API;
- logowanie HTTP jest wyłączone w release;
- debugowy scheme nie jest elementem release;
- polling na ekranie głównym działa maksymalnie 60 sekund, później wymaga świadomego odświeżenia;
- wymagane są dwa niezależne keystore release i osobne Digital Asset Links.
