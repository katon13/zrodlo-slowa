import com.android.build.api.artifact.SingleArtifact
import java.net.URI

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
}

val releaseApiBaseUrl = providers.environmentVariable("DORS3_RELEASE_API_BASE_URL").orElse("https://CHANGE_ME/")
val debugApiBaseUrl = providers.gradleProperty("DORS3_DEBUG_API_BASE_URL")
    .orElse(providers.environmentVariable("DORS3_DEBUG_API_BASE_URL"))
    .orElse("http://10.0.2.2:8080/")
val releaseAdminHost = providers.environmentVariable("DORS3_RELEASE_ADMIN_HOST").orElse("admin-3dors.przyklad-domeny.pl")
val releaseAuthorHost = providers.environmentVariable("DORS3_RELEASE_AUTHOR_HOST").orElse("author-3dors.przyklad-domeny.pl")
val adminReleaseKeystorePath = providers.environmentVariable("DORS3_ADMIN_RELEASE_KEYSTORE_PATH")
val adminReleaseKeyAlias = providers.environmentVariable("DORS3_ADMIN_RELEASE_KEY_ALIAS")
val adminReleaseStorePassword = providers.environmentVariable("DORS3_ADMIN_RELEASE_STORE_PASSWORD")
val adminReleaseKeyPassword = providers.environmentVariable("DORS3_ADMIN_RELEASE_KEY_PASSWORD")
val authorReleaseKeystorePath = providers.environmentVariable("DORS3_AUTHOR_RELEASE_KEYSTORE_PATH")
val authorReleaseKeyAlias = providers.environmentVariable("DORS3_AUTHOR_RELEASE_KEY_ALIAS")
val authorReleaseStorePassword = providers.environmentVariable("DORS3_AUTHOR_RELEASE_STORE_PASSWORD")
val authorReleaseKeyPassword = providers.environmentVariable("DORS3_AUTHOR_RELEASE_KEY_PASSWORD")
val hasAdminReleaseSigning = adminReleaseKeystorePath.isPresent && adminReleaseKeyAlias.isPresent &&
    adminReleaseStorePassword.isPresent && adminReleaseKeyPassword.isPresent
val hasAuthorReleaseSigning = authorReleaseKeystorePath.isPresent && authorReleaseKeyAlias.isPresent &&
    authorReleaseStorePassword.isPresent && authorReleaseKeyPassword.isPresent

abstract class ValidateDors3ReleaseConfiguration : DefaultTask() {
    @get:Input
    abstract val apiBaseUrl: Property<String>

    @get:Input
    abstract val adminHost: Property<String>

    @get:Input
    abstract val authorHost: Property<String>

    @get:Input
    abstract val adminSigningConfigured: Property<Boolean>

    @get:Input
    abstract val authorSigningConfigured: Property<Boolean>

    @get:Input
    abstract val adminKeyAlias: Property<String>

    @get:Input
    abstract val authorKeyAlias: Property<String>

    @get:Optional
    @get:InputFile
    @get:PathSensitive(PathSensitivity.NONE)
    abstract val adminKeystoreFile: RegularFileProperty

    @get:Optional
    @get:InputFile
    @get:PathSensitive(PathSensitivity.NONE)
    abstract val authorKeystoreFile: RegularFileProperty

    @TaskAction
    fun validateConfiguration() {
        val placeholders = listOf(apiBaseUrl.get(), adminHost.get(), authorHost.get())
            .any { it.contains("CHANGE_ME", ignoreCase = true) || it.contains("przyklad-domeny", ignoreCase = true) }
        if (placeholders) {
            throw GradleException("Release 3DORS zablokowany: ustaw prawdziwy API URL oraz hosty Admin/Author.")
        }
        if (
            !adminSigningConfigured.get() || !authorSigningConfigured.get() ||
            !adminKeystoreFile.isPresent || !authorKeystoreFile.isPresent ||
            !adminKeystoreFile.get().asFile.isFile || !authorKeystoreFile.get().asFile.isFile
        ) {
            throw GradleException("Release 3DORS zablokowany: skonfiguruj niezależne keystore, aliasy i hasła dla Admin oraz Author.")
        }
        val sameFile = adminKeystoreFile.get().asFile.canonicalFile == authorKeystoreFile.get().asFile.canonicalFile
        val sameAlias = adminKeyAlias.get() == authorKeyAlias.get()
        if (sameFile && sameAlias) {
            throw GradleException("Release 3DORS zablokowany: Admin i Author nie mogą używać tej samej pary keystore/alias.")
        }
    }
}

abstract class VerifyDors3ReleaseManifestHost : DefaultTask() {
    @get:InputFile
    @get:PathSensitive(PathSensitivity.NONE)
    abstract val mergedManifest: RegularFileProperty

    @get:Input
    abstract val expectedHost: Property<String>

    @get:Input
    abstract val variantName: Property<String>

    @TaskAction
    fun verifyManifest() {
        val manifest = mergedManifest.get().asFile.readText()
        val host = expectedHost.get()
        val literalHost = Regex("""android:host\s*=\s*[\"']${Regex.escape(host)}[\"']""")
        if (!literalHost.containsMatchIn(manifest)) {
            throw GradleException(
                "Merged manifest ${variantName.get()} nie zawiera skonfigurowanego hosta App Link: $host",
            )
        }
        if (
            manifest.contains("@string/dors3_app_link_host")
            || manifest.contains("przyklad-domeny", ignoreCase = true)
            || manifest.contains("CHANGE_ME", ignoreCase = true)
        ) {
            throw GradleException(
                "Merged manifest ${variantName.get()} nadal zawiera pośredni lub przykładowy host App Link.",
            )
        }
    }
}

android {
    namespace = "pl.zrodloslowa.mobile"
    compileSdk {
        version = release(37)
    }

    defaultConfig {
        minSdk = 29
        targetSdk = 37
        versionCode = 1
        versionName = "1.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        // Konfiguracja lokalna/emulator dla 3DORS Mobile (nadpisywana per build type)
        buildConfigField("String", "DORS3_API_BASE_URL", "\"http://10.0.2.2:8080/\"")
        buildConfigField("String", "DORS3_APP_LINK_HOST", "\"3dors.przyklad-domeny.pl\"")
        buildConfigField("boolean", "DORS3_DEBUG_DEEP_LINK_ENABLED", "true")
        buildConfigField("String", "DORS3_ENVIRONMENT", "\"LOKALNE\"")
    }

    signingConfigs {
        if (hasAdminReleaseSigning) {
            create("adminReleaseExternal") {
                storeFile = file(adminReleaseKeystorePath.get())
                storePassword = adminReleaseStorePassword.get()
                keyAlias = adminReleaseKeyAlias.get()
                keyPassword = adminReleaseKeyPassword.get()
            }
        }
        if (hasAuthorReleaseSigning) {
            create("authorReleaseExternal") {
                storeFile = file(authorReleaseKeystorePath.get())
                storePassword = authorReleaseStorePassword.get()
                keyAlias = authorReleaseKeyAlias.get()
                keyPassword = authorReleaseKeyPassword.get()
            }
        }
    }

    flavorDimensions += "applicationVariant"
    productFlavors {
        create("admin") {
            dimension = "applicationVariant"
            applicationId = "pl.zrodloslowa.dors3.admin"
            buildConfigField("String", "DORS3_APPLICATION_VARIANT", "\"admin\"")
            buildConfigField("String", "DORS3_DEBUG_LINK_SCHEME", "\"dors3-admin-dev\"")
            manifestPlaceholders["dors3DebugScheme"] = "dors3-admin-dev"
            val configuredHost = releaseAdminHost.get()
            manifestPlaceholders["dors3AppLinkHost"] = configuredHost
            buildConfigField("String", "DORS3_APP_LINK_HOST", "\"$configuredHost\"")
            signingConfig = signingConfigs.findByName("adminReleaseExternal")
        }
        create("author") {
            dimension = "applicationVariant"
            applicationId = "pl.zrodloslowa.dors3.author"
            buildConfigField("String", "DORS3_APPLICATION_VARIANT", "\"author\"")
            buildConfigField("String", "DORS3_DEBUG_LINK_SCHEME", "\"dors3-author-dev\"")
            manifestPlaceholders["dors3DebugScheme"] = "dors3-author-dev"
            val configuredHost = releaseAuthorHost.get()
            manifestPlaceholders["dors3AppLinkHost"] = configuredHost
            buildConfigField("String", "DORS3_APP_LINK_HOST", "\"$configuredHost\"")
            signingConfig = signingConfigs.findByName("authorReleaseExternal")
        }
    }

    buildTypes {
        debug {
            signingConfig = signingConfigs.getByName("debug")
            val configuredDebugApiBaseUrl = debugApiBaseUrl.get()
            val debugApiUri = URI(configuredDebugApiBaseUrl)
            val allowedLocalHttpHosts = setOf("10.0.2.2", "localhost", "127.0.0.1")
            val safeTransport = debugApiUri.scheme.equals("https", ignoreCase = true) ||
                (debugApiUri.scheme.equals("http", ignoreCase = true) && debugApiUri.host in allowedLocalHttpHosts)
            require(
                safeTransport && debugApiUri.rawQuery == null && debugApiUri.rawFragment == null &&
                    configuredDebugApiBaseUrl.endsWith("/"),
            ) {
                "DORS3_DEBUG_API_BASE_URL musi być adresem HTTPS albo lokalnym HTTP " +
                    "(10.0.2.2/localhost/127.0.0.1), bez query/fragment i zakończonym '/'."
            }
            val escapedDebugApiBaseUrl = configuredDebugApiBaseUrl
                .replace("\\", "\\\\")
                .replace("\"", "\\\"")
            buildConfigField("String", "DORS3_API_BASE_URL", "\"$escapedDebugApiBaseUrl\"")
            buildConfigField("String", "DORS3_ENVIRONMENT", "\"LOKALNE\"")
        }
        release {
            buildConfigField("String", "DORS3_API_BASE_URL", "\"${releaseApiBaseUrl.get()}\"")
            buildConfigField("String", "DORS3_ENVIRONMENT", "\"PRODUKCJA\"")
            buildConfigField("boolean", "DORS3_DEBUG_DEEP_LINK_ENABLED", "false")
            optimization {
                enable = true
            }
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

    testOptions {
        unitTests {
            isReturnDefaultValues = true
            isIncludeAndroidResources = true
        }
    }
}

androidComponents {
    onVariants(selector().withBuildType("release")) { variant ->
        val applicationVariant = variant.productFlavors
            .firstOrNull { it.first == "applicationVariant" }
            ?.second
            ?: error("Brak applicationVariant dla ${variant.name}.")
        val expectedHostProvider = when (applicationVariant) {
            "admin" -> releaseAdminHost
            "author" -> releaseAuthorHost
            else -> error("Nieobsługiwany applicationVariant: $applicationVariant")
        }
        val capitalizedVariant = variant.name.replaceFirstChar { character ->
            if (character.isLowerCase()) character.titlecase() else character.toString()
        }
        val verification = tasks.register<VerifyDors3ReleaseManifestHost>(
            "verify${capitalizedVariant}AppLinkManifestHost",
        ) {
            group = "verification"
            description = "Sprawdza host App Link w finalnym merged manifeście wariantu ${variant.name}."
            mergedManifest.set(variant.artifacts.get(SingleArtifact.MERGED_MANIFEST))
            expectedHost.set(expectedHostProvider)
            variantName.set(variant.name)
        }
        tasks.matching {
            it.name == "assemble$capitalizedVariant" || it.name == "bundle$capitalizedVariant"
        }.configureEach {
            dependsOn(verification)
        }
    }
}

val validateDors3ReleaseConfiguration = tasks.register<ValidateDors3ReleaseConfiguration>(
    "validateDors3ReleaseConfiguration",
) {
    apiBaseUrl.set(releaseApiBaseUrl)
    adminHost.set(releaseAdminHost)
    authorHost.set(releaseAuthorHost)
    adminSigningConfigured.set(hasAdminReleaseSigning)
    authorSigningConfigured.set(hasAuthorReleaseSigning)
    adminKeyAlias.set(adminReleaseKeyAlias.orElse(""))
    authorKeyAlias.set(authorReleaseKeyAlias.orElse(""))
    adminReleaseKeystorePath.orNull?.let { adminKeystoreFile.fileValue(file(it)) }
    authorReleaseKeystorePath.orNull?.let { authorKeystoreFile.fileValue(file(it)) }
}

tasks.configureEach {
    if (name.matches(Regex("(pre|assemble|bundle).+Release(Build)?", RegexOption.IGNORE_CASE))) {
        dependsOn(validateDors3ReleaseConfiguration)
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
    implementation(libs.androidx.navigation.compose)
    debugImplementation(libs.androidx.ui.tooling)
    debugImplementation(libs.androidx.ui.test.manifest)

    implementation(libs.androidx.biometric)
    implementation(libs.androidx.security.crypto)
    implementation(libs.androidx.fragment.ktx)

    implementation(libs.retrofit.core)
    implementation(libs.retrofit.moshi)
    implementation(libs.okhttp.core)
    implementation(libs.okhttp.logging)
    implementation(libs.moshi.kotlin)

    implementation(libs.kotlinx.coroutines.android)

    implementation(libs.androidx.camera.core)
    implementation(libs.androidx.camera.camera2)
    implementation(libs.androidx.camera.lifecycle)
    implementation(libs.androidx.camera.view)
    implementation(libs.mlkit.barcode.scanning)

    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)
    androidTestImplementation(platform(libs.androidx.compose.bom))
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.ui.test.junit4)
}
