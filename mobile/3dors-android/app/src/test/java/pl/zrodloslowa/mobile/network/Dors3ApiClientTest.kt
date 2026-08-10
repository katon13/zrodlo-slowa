package pl.zrodloslowa.mobile.network

import okhttp3.logging.HttpLoggingInterceptor
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Testy reguł bezpieczeństwa klienta HTTP (dyspozycja, pkt "Release safety" i
 * "Logi HTTP"): release z placeholderem adresu API musi zostać zablokowany, a
 * logowanie BODY jest zabronione w każdym buildzie.
 */
class Dors3ApiClientTest {

    @Test
    fun `release z adresem CHANGE_ME jest blokowany`() {
        assertTrue(Dors3ApiClient.shouldBlockConnection(isDebugBuild = false, baseUrl = "https://CHANGE_ME/"))
    }

    @Test
    fun `release z prawidlowym adresem nie jest blokowany`() {
        assertFalse(Dors3ApiClient.shouldBlockConnection(isDebugBuild = false, baseUrl = "https://api.zrodloslowa.pl/"))
    }

    @Test
    fun `debug z placeholderem nie jest blokowany (dozwolony adres testowy)`() {
        assertFalse(Dors3ApiClient.shouldBlockConnection(isDebugBuild = true, baseUrl = "https://CHANGE_ME/"))
    }

    @Test
    fun `poziom logowania w debug to BASIC, nigdy BODY`() {
        assertEquals(HttpLoggingInterceptor.Level.BASIC, Dors3ApiClient.loggingLevelFor(isDebugBuild = true))
    }

    @Test
    fun `poziom logowania w release to NONE`() {
        assertEquals(HttpLoggingInterceptor.Level.NONE, Dors3ApiClient.loggingLevelFor(isDebugBuild = false))
    }
}
