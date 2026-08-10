import pl.zrodloslowa.build.ValidateReleaseConfigurationTask

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
}

// Adres API/WWW backendu Źródło Słowa dla wariantu debug (emulator -> host dev).
// Właściwe domeny produkcyjne (6 wersji językowych) są zdefiniowane w
// pl.zrodloslowa.app.config.SiteConfig — ten adres służy wyłącznie do lokalnych testów.
val debugWebBaseUrl = providers.gradleProperty("ZRODLOSLOWA_DEBUG_WEB_BASE_URL")
    .orElse(providers.environmentVariable("ZRODLOSLOWA_DEBUG_WEB_BASE_URL"))
    .orElse("http://10.0.2.2:8080/")

// Naprawa "dokładne hosty, nie wildcard/prefiks" (DYSPOZYCJA_NAPRAWCZA pkt 1):
// realny host App Link 3DORS Author/Admin jest ustawiany w backendzie per
// środowisko (`config/dors3.php` -> `DORS3_AUTHOR_APP_LINK_BASE_URL` /
// `DORS3_ADMIN_APP_LINK_BASE_URL`, w szablonie produkcyjnym `.env.production.example`
// wartość to jawne `CHANGE_ME` — NIE JEST znana statycznie z tego repozytorium).
// Zamiast zaszywać na sztywno zgadywaną domenę produkcyjną, host jest
// konfigurowalny z zewnątrz (analogicznie do [debugWebBaseUrl]); domyślne
// wartości pokrywają jawnie wskazane w dyspozycji nazwy testowe/dev
// (`dors3-author-dev`, `dors3-admin-dev`) — przed pilotem trzeba je
// podmienić na rzeczywiste hosty z `.env` danego środowiska.
val dors3AuthorHosts = providers.gradleProperty("ZRODLOSLOWA_DORS3_AUTHOR_HOSTS")
    .orElse(providers.environmentVariable("ZRODLOSLOWA_DORS3_AUTHOR_HOSTS"))
    .orElse("dors3-author-dev,author-3dors.zrodlo-slowa.pl")
val dors3AdminHosts = providers.gradleProperty("ZRODLOSLOWA_DORS3_ADMIN_HOSTS")
    .orElse(providers.environmentVariable("ZRODLOSLOWA_DORS3_ADMIN_HOSTS"))
    .orElse("dors3-admin-dev,admin-3dors.zrodlo-slowa.pl")

// Naprawa P0-1 z audytu ("Lokalna integracja z 3DORS Author nadal nie
// odpowiada istniejącemu kontraktowi"): rzeczywisty kontrakt debug
// (`mobile/3dors-android`, `Dors3DeepLink.kt`) używa custom scheme
// `dors3-author-dev://approve/{id}` / `dors3-admin-dev://approve/{id}`,
// a nie hosta linku HTTPS. Nazwy schematów są konfigurowalne analogicznie
// do hostów powyżej, z domyślnymi wartościami zgodnymi z dyspozycją.
val dors3AuthorDevScheme = providers.gradleProperty("ZRODLOSLOWA_DORS3_AUTHOR_DEV_SCHEME")
    .orElse(providers.environmentVariable("ZRODLOSLOWA_DORS3_AUTHOR_DEV_SCHEME"))
    .orElse("dors3-author-dev")
val dors3AdminDevScheme = providers.gradleProperty("ZRODLOSLOWA_DORS3_ADMIN_DEV_SCHEME")
    .orElse(providers.environmentVariable("ZRODLOSLOWA_DORS3_ADMIN_DEV_SCHEME"))
    .orElse("dors3-admin-dev")

// Dyspozycja pkt 4.3 ("sprawdzenie SHA-256 certyfikatu zainstalowanej
// aplikacji; osobny fingerprint debug i release"): rozpoznanie linku/schematu
// 3DORS Author (powyżej) NIE gwarantuje, że pod tą samą nazwą pakietu nie
// zainstalowano złośliwej aplikacji podpisanej innym kluczem — bez pinowania
// certyfikatu `PackageManager.resolveActivity`/`setPackage` ufa WYŁĄCZNIE
// nazwie pakietu. Wartość placeholder jest dopuszczalna tylko w debug
// (podpis debugowy 3DORS Author różni się między maszynami deweloperskimi).
val dors3AuthorCertSha256Debug = providers.gradleProperty("ZRODLOSLOWA_DORS3_AUTHOR_CERT_SHA256_DEBUG")
    .orElse(providers.environmentVariable("ZRODLOSLOWA_DORS3_AUTHOR_CERT_SHA256_DEBUG"))
    .orElse("DEBUG_UNPINNED")
val dors3AuthorCertSha256Release = providers.gradleProperty("ZRODLOSLOWA_DORS3_AUTHOR_CERT_SHA256_RELEASE")
    .orElse(providers.environmentVariable("ZRODLOSLOWA_DORS3_AUTHOR_CERT_SHA256_RELEASE"))
    .orElse("CHANGE_ME")

// Naprawa P0-2 z audytu ("Release może zostać zbudowany z błędnymi hostami
// 3DORS"): domyślne wartości (`dors3-author-dev`, `dors3-admin-dev` i
// przypuszczalna domena produkcyjna) wystarczały wcześniej do zbudowania
// wariantu `release`, mimo że rzeczywiste hosty produkcyjne 3DORS NIE są
// znane z tego repozytorium (patrz komentarz przy `dors3AuthorHosts`
// powyżej). Zadania budujące wariant `release` teraz jawnie failują,
// dopóki obie listy hostów nie zostaną nadpisane właściwościami
// `ZRODLOSLOWA_DORS3_AUTHOR_HOSTS` / `ZRODLOSLOWA_DORS3_ADMIN_HOSTS` na
// wartości, które NIE są domyślne i NIE zawierają oznaczenia `dev`.
val releaseDors3HostDefaults = setOf(
    "dors3-author-dev,author-3dors.zrodlo-slowa.pl",
    "dors3-admin-dev,admin-3dors.zrodlo-slowa.pl",
)

// Naprawa niezgodności z Configuration Cache: cała lista problemów jest
// wyliczona raz, w czasie konfiguracji, i przekazana jako `@Input` do
// typowanego zadania z `buildSrc`, bez referencji do funkcji ani obiektu
// skryptu budowania.
val resolvedAuthorHosts = dors3AuthorHosts.get()
val resolvedAdminHosts = dors3AdminHosts.get()
val resolvedAuthorCertSha256Release = dors3AuthorCertSha256Release.get()
val dors3HostProblems: List<String> = buildList {
    if (resolvedAuthorHosts in releaseDors3HostDefaults || resolvedAuthorHosts.isBlank() || resolvedAuthorHosts.contains("dev", ignoreCase = true)) {
        add("ZRODLOSLOWA_DORS3_AUTHOR_HOSTS='$resolvedAuthorHosts' nie jest zweryfikowanym hostem produkcyjnym")
    }
    if (resolvedAdminHosts in releaseDors3HostDefaults || resolvedAdminHosts.isBlank() || resolvedAdminHosts.contains("dev", ignoreCase = true)) {
        add("ZRODLOSLOWA_DORS3_ADMIN_HOSTS='$resolvedAdminHosts' nie jest zweryfikowanym hostem produkcyjnym")
    }
    // Dyspozycja pkt 4.3: bez zweryfikowanego fingerprintu SHA-256 certyfikatu
    // release 3DORS Author, wariant release tej aplikacji ufałby WYŁĄCZNIE
    // nazwie pakietu (patrz komentarz przy `dors3AuthorCertSha256Release` powyżej).
    if (resolvedAuthorCertSha256Release.isBlank() || resolvedAuthorCertSha256Release == "CHANGE_ME") {
        add("ZRODLOSLOWA_DORS3_AUTHOR_CERT_SHA256_RELEASE nie jest zweryfikowanym fingerprintem SHA-256 3DORS Author")
    }
}

android {
    namespace = "pl.zrodloslowa.app"
    compileSdk {
        version = release(37)
    }

    defaultConfig {
        applicationId = "pl.zrodloslowa.app"
        minSdk = 26
        targetSdk = 37
        versionCode = 1
        versionName = "1.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        buildConfigField("String", "DEBUG_WEB_BASE_URL", "\"${debugWebBaseUrl.get()}\"")
        buildConfigField("String", "DORS3_AUTHOR_HOSTS", "\"${dors3AuthorHosts.get()}\"")
        buildConfigField("String", "DORS3_ADMIN_HOSTS", "\"${dors3AdminHosts.get()}\"")
        buildConfigField("String", "DORS3_AUTHOR_DEV_SCHEME", "\"${dors3AuthorDevScheme.get()}\"")
        buildConfigField("String", "DORS3_ADMIN_DEV_SCHEME", "\"${dors3AdminDevScheme.get()}\"")
        buildConfigField("String", "DORS3_AUTHOR_CERT_SHA256_DEBUG", "\"${dors3AuthorCertSha256Debug.get()}\"")
        buildConfigField("String", "DORS3_AUTHOR_CERT_SHA256_RELEASE", "\"${dors3AuthorCertSha256Release.get()}\"")
    }

    buildTypes {
        debug {
            // W debug pozwalamy dodatkowo na lokalny adres deweloperski (patrz
            // debug/res/xml/network_security_config.xml — analogicznie do 3dors-android).
        }
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }

    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

// Guard jest częścią grafu rzeczywistego wariantu release. Nie analizuje
// tekstu polecenia Gradle: również zadanie uruchomione pośrednio przechodzi
// przez lifecycle `pre<ReleaseVariant>Build` i zależną od niego walidację.
val validateReleaseDors3Configuration = tasks.register<ValidateReleaseConfigurationTask>("validateReleaseDors3Configuration") {
    group = "verification"
    description = "Weryfikuje produkcyjne hosty i fingerprint 3DORS dla wariantu release."
    problems.set(dors3HostProblems)
}

androidComponents {
    onVariants(selector().withBuildType("release")) { variant ->
        val variantName = variant.name.replaceFirstChar { character ->
            if (character.isLowerCase()) character.titlecase() else character.toString()
        }
        tasks.matching { it.name == "pre${variantName}Build" }.configureEach {
            dependsOn(validateReleaseDors3Configuration)
        }
    }
}

dependencies {
    implementation(libs.androidx.appcompat)
    implementation(libs.androidx.core.ktx)
    implementation(libs.material)

    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.viewmodel.ktx)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.ui)
    implementation(libs.androidx.ui.graphics)
    implementation(libs.androidx.ui.tooling.preview)
    implementation(libs.androidx.material3)
    implementation(libs.androidx.material.icons.extended)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.androidx.webkit)
    implementation(libs.androidx.core.splashscreen)
    debugImplementation(libs.androidx.ui.tooling)
    debugImplementation(libs.androidx.ui.test.manifest)

    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.play.install.referrer)

    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)
    testImplementation(libs.json)
    androidTestImplementation(platform(libs.androidx.compose.bom))
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.ui.test.junit4)
}
