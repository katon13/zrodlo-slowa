package pl.zrodloslowa.mobile.model

import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Testy (de)serializacji payloadu QR rejestracji (pkt 4.2 krok 4-6) — sprawdza,
 * że wszystkie pola wymagane do wyświetlenia użytkownikowi przed utworzeniem
 * klucza w Keystore są poprawnie odczytywane z JSON-a zakodowanego w kodzie QR.
 */
class Dors3ModelsTest {

    private val moshi = Moshi.Builder().add(KotlinJsonAdapterFactory()).build()

    @Test
    fun `parsuje payload QR rejestracji ze wszystkimi wymaganymi polami`() {
        val adapter = moshi.adapter(EnrollmentQrPayload::class.java)
        val json = """
            {
              "token": "tok-123",
              "enrollment_request_id": "enr-1",
              "service": "Źródło Słowa",
              "environment": "LOKALNE",
              "organization": "Źródło Słowa",
              "user_display_name": "Jan Kowalski",
              "account": "jan.kowalski",
              "role": "dziennikarz",
              "purpose": "enrollment",
              "protocol_version": 1,
              "expires_at": 1999999999
            }
        """.trimIndent()

        val payload = adapter.fromJson(json)

        assertEquals("tok-123", payload?.token)
        assertEquals("enr-1", payload?.enrollmentRequestId)
        assertEquals("Jan Kowalski", payload?.userDisplayName)
        assertEquals("dziennikarz", payload?.role)
        assertEquals(1, payload?.protocolVersion)
    }

    @Test
    fun `parsuje odpowiedz ukonczenia rejestracji z kodem porownawczym`() {
        val adapter = moshi.adapter(EnrollmentCompleteResponse::class.java)
        val json = """{"device_public_id": "dev-1", "credential_public_id": "cred-1", "comparison_code": "482913", "api_token": "secret-token", "api_token_expires_at": 1999999999}"""

        val response = adapter.fromJson(json)

        assertEquals("dev-1", response?.devicePublicId)
        assertEquals("cred-1", response?.credentialPublicId)
        assertEquals("482913", response?.comparisonCode)
        assertEquals("secret-token", response?.apiToken)
    }
}
