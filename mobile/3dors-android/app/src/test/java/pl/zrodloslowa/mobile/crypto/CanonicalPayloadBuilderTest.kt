package pl.zrodloslowa.mobile.crypto

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Test
import pl.zrodloslowa.mobile.model.ApprovalRequestDetails

/**
 * Testy kanonicznego payloadu podpisu (pkt 5.4, 8.3, 8.5 dyspozycji): "Zmiana
 * KTÓREGOKOLWIEK pola powoduje odrzucenie" — sprawdzamy, że zmiana dowolnego
 * pola wejściowego (w tym decyzji approve/reject) zmienia wynikowy ciąg do
 * podpisu, oraz że kanonizacja jest wersjonowana.
 */
class CanonicalPayloadBuilderTest {

    private fun baseDetails() = ApprovalRequestDetails(
        requestId = "req-1",
        publicId = "pub-1",
        purpose = "login",
        service = "Źródło Słowa",
        environment = "LOKALNE",
        account = "jan.kowalski",
        person = "Jan Kowalski",
        role = "dziennikarz",
        organization = "Źródło Słowa",
        initiatingDevice = "Komputer biurowy",
        actionType = null,
        displayFields = emptyMap(),
        challenge = "challenge-abc",
        actionFingerprint = "fingerprint-1",
        browserSessionHash = "session-hash",
        issuedAt = 1000L,
        expiresAt = 1060L,
        nonce = "nonce-xyz",
        serverOrigin = "https://zrodlo-slowa.test",
    )

    private fun build(
        details: ApprovalRequestDetails = baseDetails(),
        userId: String = "user-1",
        organizationId: String = "org-1",
        purpose: String = "login",
        decision: String = "approve",
        credentialPublicId: String = "device-1",
    ): String = CanonicalPayloadBuilder.build(
        details = details,
        userId = userId,
        organizationId = organizationId,
        purpose = purpose,
        decision = decision,
        credentialPublicId = credentialPublicId,
    )

    @Test
    fun `buduje kanoniczny payload w ustalonej kolejnosci pol`() {
        val details = baseDetails()
        val payload = build(details = details)

        val expected = listOf(
            "payload_version=1",
            "decision=approve",
            "purpose=login",
            "req-1",
            "challenge-abc",
            "user-1",
            "org-1",
            "dziennikarz",
            "https://zrodlo-slowa.test",
            "LOKALNE",
            "session-hash",
            "fingerprint-1",
            "1000",
            "1060",
            "nonce-xyz",
            "device-1",
        ).joinToString("\n")

        assertEquals(expected, payload)
    }

    @Test
    fun `zmiana decyzji z approve na reject zmienia payload`() {
        val original = build(decision = "approve")
        val tampered = build(decision = "reject")

        assertNotEquals(original, tampered)
    }

    @Test
    fun `zmiana challenge zmienia payload`() {
        val details = baseDetails()
        val original = build(details = details)
        val tampered = build(details = details.copy(challenge = "inny-challenge"))

        assertNotEquals(original, tampered)
    }

    @Test
    fun `zmiana action_fingerprint zmienia payload`() {
        val details = baseDetails()
        val original = build(details = details)
        val tampered = build(details = details.copy(actionFingerprint = "inny-fingerprint"))

        assertNotEquals(original, tampered)
    }

    @Test
    fun `zmiana kwoty operacji reprezentowanej w polach wyswietlanych nie wplywa na payload bez zmiany fingerprintu`() {
        // Payload podpisu opiera się o challenge/action_fingerprint z serwera,
        // a nie o displayFields — dlatego test dokumentuje, że backend MUSI
        // wygenerować nowy action_fingerprint przy zmianie operacji (pkt 6.1 krok 2-3).
        val details = baseDetails()
        val withDifferentDisplay = details.copy(displayFields = mapOf("Kwota" to "999,00 PLN"))

        val payloadA = build(details = details)
        val payloadB = build(details = withDifferentDisplay)

        assertEquals(payloadA, payloadB)
    }

    @Test
    fun `zmiana user_id zmienia payload`() {
        val original = build(userId = "user-1")
        val tampered = build(userId = "user-2")

        assertNotEquals(original, tampered)
    }

    @Test
    fun `zmiana organizacji zmienia payload`() {
        val original = build(organizationId = "org-1")
        val tampered = build(organizationId = "org-2")

        assertNotEquals(original, tampered)
    }

    @Test
    fun `zmiana credential_public_id zmienia payload`() {
        val original = build(credentialPublicId = "device-1")
        val tampered = build(credentialPublicId = "device-2")

        assertNotEquals(original, tampered)
    }

    @Test
    fun `zmiana purpose z login na operation zmienia payload`() {
        val loginPayload = build(purpose = "login")
        val operationPayload = build(purpose = "operation")

        assertNotEquals(loginPayload, operationPayload)
    }

    @Test
    fun `zmiana expires_at zmienia payload (ochrona przed przedluzeniem waznosci)`() {
        val details = baseDetails()
        val original = build(details = details)
        val tampered = build(details = details.copy(expiresAt = details.expiresAt + 3600))

        assertNotEquals(original, tampered)
    }
}
