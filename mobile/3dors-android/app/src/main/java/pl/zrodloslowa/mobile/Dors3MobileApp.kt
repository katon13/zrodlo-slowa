package pl.zrodloslowa.mobile

import android.app.Application
import pl.zrodloslowa.mobile.config.EnvironmentConfig
import pl.zrodloslowa.mobile.data.ApprovalRepository
import pl.zrodloslowa.mobile.data.DeviceCredentialStore
import pl.zrodloslowa.mobile.data.EnrollmentRepository
import pl.zrodloslowa.mobile.network.Dors3ApiClient
import pl.zrodloslowa.mobile.network.Dors3ApiService

/**
 * Ręczny kontener zależności aplikacji (bez Hilt/Dagger — MVP). Odczytuje adres
 * API z override zapisanego lokalnie albo z konfiguracji buildu. Dla telefonu
 * fizycznego debug URL jest podawany właściwością Gradle przy kompilacji.
 */
class Dors3MobileApp : Application() {

    lateinit var credentialStore: DeviceCredentialStore
        private set

    lateinit var apiService: Dors3ApiService
        private set

    lateinit var enrollmentRepository: EnrollmentRepository
        private set

    lateinit var approvalRepository: ApprovalRepository
        private set

    override fun onCreate() {
        super.onCreate()
        credentialStore = DeviceCredentialStore(this)
        val baseUrl = credentialStore.apiBaseUrlOverride ?: EnvironmentConfig.DEFAULT_API_BASE_URL
        apiService = Dors3ApiClient.create(baseUrl)
        enrollmentRepository = EnrollmentRepository(apiService, credentialStore)
        // Oczekiwany host origin sesji webowej do walidacji pola server_origin
        // (dyspozycja, pkt "Walidacja żądań") — `null`, gdy nieskonfigurowany lub
        // wciąż jest przykładowym placeholderem (patrz EnvironmentConfig).
        approvalRepository = ApprovalRepository(
            apiService = apiService,
            credentialStore = credentialStore,
            expectedOriginHost = EnvironmentConfig.expectedServerOriginHost,
        )
    }
}
