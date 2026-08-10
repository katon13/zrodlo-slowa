package pl.zrodloslowa.mobile.data

import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import pl.zrodloslowa.mobile.model.EnrollmentQrPayload
import pl.zrodloslowa.mobile.testutil.FakeDors3ApiService
import pl.zrodloslowa.mobile.testutil.FakeKeystoreFactory
import pl.zrodloslowa.mobile.testutil.InMemoryCredentialStore

/**
 * Testy naprawy błędu "Alias Android Keystore" i "Credential ID" z dyspozycji:
 * po rejestracji musi istnieć DOKŁADNIE JEDEN, trwały alias klucza, zapisany
 * przy credentialu — niezależny od `enrollment_request_id`/`device_public_id`.
 */
class EnrollmentRepositoryTest {

    private fun qrPayload(
        enrollmentRequestId: String = "enr-1",
        environment: String = "LOKALNE",
        protocolVersion: Int = 1,
        expiresAt: Long = 9_999_999_999L,
    ) = EnrollmentQrPayload(
        token = "tok-123",
        enrollmentRequestId = enrollmentRequestId,
        service = "Źródło Słowa",
        environment = environment,
        organization = "Źródło Słowa",
        userDisplayName = "Jan Kowalski",
        account = "jan.kowalski",
        role = "dziennikarz",
        purpose = "enrollment",
        protocolVersion = protocolVersion,
        expiresAt = expiresAt,
    )

    @Test
    fun `rejestracja tworzy klucz pod trwalym aliasem i zapisuje go w credential store`() = runTest {
        val apiService = FakeDors3ApiService()
        val credentialStore = InMemoryCredentialStore()
        val keystoreFactory = FakeKeystoreFactory()
        val repository = EnrollmentRepository(apiService, credentialStore, keystoreFactory.factory)

        val result = repository.completeEnrollment(
            qrPayload = qrPayload(),
            appVersion = "1.0",
            deviceModel = "Pixel",
            osVersion = "Android 15",
        )

        assertTrue(result.isSuccess)
        val savedAlias = credentialStore.keyAlias
        assertNotNull("keyAlias musi zostać zapisany po rejestracji", savedAlias)

        // Alias NIE MOŻE być tożsamy z enrollment_request_id ani device_public_id
        // (naprawa błędu: "Alias Android Keystore").
        assertFalse(savedAlias == "enr-1")
        assertFalse(savedAlias == credentialStore.devicePublicId)

        // Dokładnie jeden alias został poproszony podczas rejestracji.
        assertEquals(1, keystoreFactory.requestedAliases.size)
        assertEquals(savedAlias, keystoreFactory.requestedAliases.single())

        // device_public_id i credential_public_id pozostają rozdzielone.
        assertEquals("device-1", credentialStore.devicePublicId)
        assertEquals("cred-1", credentialStore.credentialPublicId)
        assertFalse(credentialStore.devicePublicId == credentialStore.credentialPublicId)
    }

    @Test
    fun `wygasly kod QR jest odrzucany przed utworzeniem klucza`() = runTest {
        val apiService = FakeDors3ApiService()
        val credentialStore = InMemoryCredentialStore()
        val keystoreFactory = FakeKeystoreFactory()
        val repository = EnrollmentRepository(apiService, credentialStore, keystoreFactory.factory)

        val result = repository.completeEnrollment(
            qrPayload = qrPayload(expiresAt = 1L),
            appVersion = "1.0",
            deviceModel = "Pixel",
            osVersion = "Android 15",
        )

        assertTrue(result.isFailure)
        assertTrue(keystoreFactory.requestedAliases.isEmpty())
        assertEquals(null, credentialStore.keyAlias)
    }

    @Test
    fun `bledna wersja protokolu QR jest odrzucana`() = runTest {
        val apiService = FakeDors3ApiService()
        val credentialStore = InMemoryCredentialStore()
        val keystoreFactory = FakeKeystoreFactory()
        val repository = EnrollmentRepository(apiService, credentialStore, keystoreFactory.factory)

        val result = repository.completeEnrollment(
            qrPayload = qrPayload(protocolVersion = 99),
            appVersion = "1.0",
            deviceModel = "Pixel",
            osVersion = "Android 15",
        )

        assertTrue(result.isFailure)
        assertTrue(keystoreFactory.requestedAliases.isEmpty())
    }

    @Test
    fun `bledne srodowisko QR jest odrzucane`() = runTest {
        val apiService = FakeDors3ApiService()
        val credentialStore = InMemoryCredentialStore()
        val keystoreFactory = FakeKeystoreFactory()
        val repository = EnrollmentRepository(apiService, credentialStore, keystoreFactory.factory)

        val result = repository.completeEnrollment(
            qrPayload = qrPayload(environment = "PRODUKCJA"),
            appVersion = "1.0",
            deviceModel = "Pixel",
            osVersion = "Android 15",
        )

        assertTrue(result.isFailure)
        assertTrue(keystoreFactory.requestedAliases.isEmpty())
    }

    @Test
    fun `telefon tylko odpytuje status a nie aktywuje enrollmentu`() = runTest {
        val apiService = FakeDors3ApiService()
        val credentialStore = InMemoryCredentialStore().apply {
            devicePublicId = "device-1"
            credentialPublicId = "cred-1"
            apiToken = "token"
            deviceStatus = "pending"
        }
        val repository = EnrollmentRepository(apiService, credentialStore, FakeKeystoreFactory().factory)

        val result = repository.activationStatus("device-1")

        assertTrue(result.isSuccess)
        assertEquals("active", result.getOrNull())
        assertEquals(null, apiService.lastEnrollmentConfirmRequest)
    }

    @Test
    fun `niezgodny kod wysyla jedynie odrzucenie i usuwa lokalny credential`() = runTest {
        val apiService = FakeDors3ApiService()
        val credentialStore = InMemoryCredentialStore().apply {
            devicePublicId = "device-1"
            credentialPublicId = "cred-1"
            apiToken = "token"
            keyAlias = "alias-1"
            deviceStatus = "pending"
        }
        val keystoreFactory = FakeKeystoreFactory()
        val repository = EnrollmentRepository(apiService, credentialStore, keystoreFactory.factory)

        val result = repository.rejectEnrollment("device-1", "482913")

        assertTrue(result.isSuccess)
        assertEquals(false, apiService.lastEnrollmentConfirmRequest?.confirmed)
        assertEquals(null, credentialStore.devicePublicId)
        assertTrue(keystoreFactory.instances.getValue("alias-1").deleted)
    }
}
