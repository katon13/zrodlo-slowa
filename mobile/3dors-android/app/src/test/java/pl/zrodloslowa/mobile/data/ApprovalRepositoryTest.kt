package pl.zrodloslowa.mobile.data

import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import pl.zrodloslowa.mobile.testutil.FakeBiometricSigner
import pl.zrodloslowa.mobile.testutil.FakeDors3ApiService
import pl.zrodloslowa.mobile.testutil.FakeKeystoreFactory
import pl.zrodloslowa.mobile.testutil.InMemoryCredentialStore
import pl.zrodloslowa.mobile.testutil.emptyResponseWithCode
import pl.zrodloslowa.mobile.testutil.errorResponseWithBody
import pl.zrodloslowa.mobile.testutil.sampleApprovalRequestDetails

/**
 * Testy naprawy błędów z dyspozycji: spójny alias klucza między approve/reject,
 * brak fallbacku credential_public_id, wielopunktowe TTL, pełna walidacja
 * żądań (również auto-wykrytych) i brak logowania sekretów.
 */
class ApprovalRepositoryTest {

    private fun registeredCredentialStore(
        devicePublicId: String = "device-1",
        credentialPublicId: String? = "cred-1",
        apiToken: String? = "test-api-token",
        apiTokenExpiresAt: Long? = 4_102_444_800L,
        keyAlias: String? = "alias-xyz",
        deviceStatus: String? = "active",
    ) = InMemoryCredentialStore().apply {
        this.devicePublicId = devicePublicId
        this.credentialPublicId = credentialPublicId
        this.apiToken = apiToken
        this.apiTokenExpiresAt = apiTokenExpiresAt
        this.keyAlias = keyAlias
        this.deviceStatus = deviceStatus
    }

    private fun repository(
        apiService: FakeDors3ApiService = FakeDors3ApiService(),
        credentialStore: Dors3CredentialStore = registeredCredentialStore(),
        keystoreFactory: FakeKeystoreFactory = FakeKeystoreFactory(),
        biometricSigner: FakeBiometricSigner = FakeBiometricSigner(),
        now: () -> Long = { 1000L },
        expectedOriginHost: String? = "3dors.przyklad-domeny.pl",
    ) = ApprovalRepository(
        apiService = apiService,
        credentialStore = credentialStore,
        keystoreFactory = keystoreFactory.factory,
        biometricSignerFactory = { biometricSigner },
        nowEpochSecondsProvider = now,
        expectedOriginHost = expectedOriginHost,
    )

    @Test
    fun `approve uzywa dokladnie tego samego aliasu co byl ustalony przy rejestracji`() = runTest {
        val credentialStore = registeredCredentialStore(keyAlias = "alias-z-rejestracji")
        val keystoreFactory = FakeKeystoreFactory()
        val repo = repository(credentialStore = credentialStore, keystoreFactory = keystoreFactory)
        val details = sampleApprovalRequestDetails(expiresAt = 2000L)

        val result = repo.approveOrReject(null, details, approve = true)

        assertTrue(result.isSuccess)
        assertEquals(listOf("alias-z-rejestracji"), keystoreFactory.requestedAliases)
    }

    @Test
    fun `reject uzywa tego samego aliasu co approve`() = runTest {
        val credentialStore = registeredCredentialStore(keyAlias = "alias-stabilny")
        val keystoreFactory = FakeKeystoreFactory()
        val repo = repository(credentialStore = credentialStore, keystoreFactory = keystoreFactory)

        repo.approveOrReject(null, sampleApprovalRequestDetails(requestId = "req-a", expiresAt = 2000L), approve = true)
        repo.approveOrReject(null, sampleApprovalRequestDetails(requestId = "req-b", expiresAt = 2000L), approve = false)

        assertEquals(listOf("alias-stabilny", "alias-stabilny"), keystoreFactory.requestedAliases)
    }

    @Test
    fun `brak credential_public_id konczy operacje kontrolowanym bledem bez fallbacku`() = runTest {
        val credentialStore = registeredCredentialStore(credentialPublicId = null)
        val repo = repository(credentialStore = credentialStore)

        val result = repo.approveOrReject(null, sampleApprovalRequestDetails(expiresAt = 2000L), approve = true)

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.MissingCredentialException)
    }

    @Test
    fun `brak key_alias konczy operacje kontrolowanym bledem`() = runTest {
        val credentialStore = registeredCredentialStore(keyAlias = null)
        val repo = repository(credentialStore = credentialStore)

        val result = repo.approveOrReject(null, sampleApprovalRequestDetails(expiresAt = 2000L), approve = true)

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.MissingCredentialException)
    }

    @Test
    fun `wygasle zadanie przed biometria nie jest podpisywane`() = runTest {
        val biometricSigner = FakeBiometricSigner()
        val repo = repository(now = { 5000L }, biometricSigner = biometricSigner)
        val details = sampleApprovalRequestDetails(expiresAt = 2000L)

        val result = repo.approveOrReject(null, details, approve = true)

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.RequestExpiredException)
        assertEquals("Biometria nie może zostać wywołana dla już wygasłego żądania", 0, biometricSigner.callCount)
    }

    @Test
    fun `wygasniecie tuz po biometrii przerywa podpis`() = runTest {
        var currentTime = 1000L
        val keystoreFactory = FakeKeystoreFactory()
        // Zegar "przeskakuje" za TTL dokładnie w trakcie biometrii — checkpoint
        // "po biometrii" musi to wykryć i nie dopuścić do podpisu.
        val biometricSigner = FakeBiometricSigner(beforeSign = { currentTime = 5000L })
        val repo = repository(
            now = { currentTime },
            keystoreFactory = keystoreFactory,
            biometricSigner = biometricSigner,
        )
        val details = sampleApprovalRequestDetails(expiresAt = 2000L)

        val result = repo.approveOrReject(null, details, approve = true)

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.RequestExpiredException)
        val keystore = keystoreFactory.instances.values.single()
        assertEquals("Podpis nie mógł powstać dla wygasłego żądania", 0, keystore.signCallCount)
    }

    @Test
    fun `zla wersja protokolu jest odrzucana`() = runTest {
        val apiService = FakeDors3ApiService()
        val repo = repository(apiService = apiService)
        val details = sampleApprovalRequestDetails(expiresAt = 2000L, protocolVersion = 99)

        val result = repo.approveOrReject(null, details, approve = true)

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.UnsupportedProtocolVersionException)
    }

    @Test
    fun `zle srodowisko zadania jest odrzucane`() = runTest {
        val repo = repository()
        val details = sampleApprovalRequestDetails(expiresAt = 2000L, environment = "PRODUKCJA")

        val result = repo.approveOrReject(null, details, approve = true)

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.InvalidRequestException)
    }

    @Test
    fun `zly origin zadania jest odrzucany`() = runTest {
        val repo = repository(expectedOriginHost = "prawidlowa-domena.example")
        val details = sampleApprovalRequestDetails(expiresAt = 2000L, serverOrigin = "https://falszywa-domena.example/abc")

        val result = repo.approveOrReject(null, details, approve = true)

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.InvalidRequestException)
    }

    @Test
    fun `brak nonce jest odrzucany`() = runTest {
        val repo = repository()
        val details = sampleApprovalRequestDetails(expiresAt = 2000L, nonce = "")

        val result = repo.approveOrReject(null, details, approve = true)

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.InvalidRequestException)
    }

    @Test
    fun `brak action_fingerprint dla operacji jest odrzucany`() = runTest {
        val repo = repository()
        val details = sampleApprovalRequestDetails(expiresAt = 2000L, purpose = "operation", actionFingerprint = null)

        val result = repo.approveOrReject(null, details, approve = true)

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.InvalidRequestException)
    }

    @Test
    fun `brak action_fingerprint dla logowania jest dopuszczalny`() = runTest {
        val repo = repository()
        val details = sampleApprovalRequestDetails(expiresAt = 2000L, purpose = "login", actionFingerprint = null)

        val result = repo.approveOrReject(null, details, approve = true)

        assertTrue(result.isSuccess)
    }

    @Test
    fun `powtorne przetworzenie tego samego zadania jest blokowane lokalnie (replay)`() = runTest {
        val credentialStore = registeredCredentialStore()
        val repo = repository(credentialStore = credentialStore)
        val details = sampleApprovalRequestDetails(requestId = "req-replay", expiresAt = 2000L)

        val first = repo.approveOrReject(null, details, approve = true)
        val second = repo.approveOrReject(null, details, approve = true)

        assertTrue(first.isSuccess)
        assertTrue(second.isFailure)
        assertTrue(second.exceptionOrNull() is ApprovalRepository.RequestAlreadyProcessedException)
    }

    @Test
    fun `urzadzenie zawieszone blokuje decyzje`() = runTest {
        val credentialStore = registeredCredentialStore(deviceStatus = "suspended")
        val repo = repository(credentialStore = credentialStore)

        val result = repo.approveOrReject(null, sampleApprovalRequestDetails(expiresAt = 2000L), approve = true)

        assertTrue(result.exceptionOrNull() is ApprovalRepository.DeviceSuspendedException)
    }

    @Test
    fun `findPendingRequest waliduje takze zadania wykryte automatycznie`() = runTest {
        val apiService = FakeDors3ApiService().apply {
            pendingRequestResponse = retrofit2.Response.success(sampleApprovalRequestDetails(protocolVersion = 99))
        }
        val repo = repository(apiService = apiService)

        val result = repo.findPendingRequest("device-1")

        assertTrue(result.isFailure)
        assertTrue(result.exceptionOrNull() is ApprovalRepository.UnsupportedProtocolVersionException)
    }

    @Test
    fun `findPendingRequest zwraca null gdy brak aktywnego zadania (204)`() = runTest {
        val apiService = FakeDors3ApiService().apply {
            pendingRequestResponse = emptyResponseWithCode(204)
        }
        val repo = repository(apiService = apiService)

        val result = repo.findPendingRequest("device-1")

        assertTrue(result.isSuccess)
        assertEquals(null, result.getOrNull())
    }

    @Test
    fun `blad device_revoked z serwera jest mapowany na typowany wyjatek`() = runTest {
        val apiService = FakeDors3ApiService().apply {
            approvalRequestResponse = errorResponseWithBody(409, """{"error":"device_revoked"}""")
        }
        val repo = repository(apiService = apiService)

        val result = repo.fetchRequest("public-1")

        assertTrue(result.exceptionOrNull() is ApprovalRepository.DeviceRevokedException)
    }

    @Test
    fun `decision request nigdy nie zawiera fallbackowego device_public_id jako credential_public_id`() = runTest {
        val apiService = FakeDors3ApiService()
        val credentialStore = registeredCredentialStore(devicePublicId = "device-1", credentialPublicId = "cred-1")
        val repo = repository(apiService = apiService, credentialStore = credentialStore)

        repo.approveOrReject(null, sampleApprovalRequestDetails(expiresAt = 2000L), approve = true)

        val sentRequest = apiService.lastDecisionRequest
        assertFalse(sentRequest?.credentialPublicId == sentRequest?.devicePublicId)
        assertEquals("cred-1", sentRequest?.credentialPublicId)
        assertEquals("device-1", sentRequest?.devicePublicId)
    }
}
