package pl.zrodloslowa.mobile.data

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Przechowuje WYŁĄCZNIE metadane urządzenia (public_id, alias klucza, poziom
 * ochrony) — nigdy klucz prywatny (pkt 7: "brak przechowywania klucza
 * prywatnego w SharedPreferences, pliku, bazie aplikacji, QR albo backupie").
 * Klucz prywatny fizycznie istnieje wyłącznie w Android Keystore.
 *
 * Dane trzymane są w [EncryptedSharedPreferences] jako dodatkowa warstwa
 * ochrony samych metadanych (np. device_public_id).
 */
class DeviceCredentialStore(context: Context) : Dors3CredentialStore {

    private val prefs by lazy {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        EncryptedSharedPreferences.create(
            context,
            PREFS_FILE_NAME,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    override var devicePublicId: String?
        get() = prefs.getString(KEY_DEVICE_PUBLIC_ID, null)
        set(value) = prefs.edit().putString(KEY_DEVICE_PUBLIC_ID, value).apply()

    /** Identyfikator konkretnego klucza/credentialu telefonu — odrębny od [devicePublicId]. */
    override var credentialPublicId: String?
        get() = prefs.getString(KEY_CREDENTIAL_PUBLIC_ID, null)
        set(value) = prefs.edit().putString(KEY_CREDENTIAL_PUBLIC_ID, value).apply()

    override var apiToken: String?
        get() = prefs.getString(KEY_API_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_API_TOKEN, value).apply()

    override var apiTokenExpiresAt: Long?
        get() = prefs.getLong(KEY_API_TOKEN_EXPIRES_AT, 0L).takeIf { it > 0L }
        set(value) = prefs.edit().putLong(KEY_API_TOKEN_EXPIRES_AT, value ?: 0L).apply()

    /**
     * Jeden, TRWAŁY alias klucza w Android Keystore, ustalony RAZ przy rejestracji
     * (patrz [pl.zrodloslowa.mobile.crypto.Dors3KeystoreManager]) i zapisany tutaj
     * bezpiecznie przy credentialu. Musi być używany identycznie przy approve i
     * reject — nigdy nie wolno wyszukiwać klucza po `enrollment_request_id` ani
     * `device_public_id` (naprawa błędu z dyspozycji: "Alias Android Keystore").
     */
    override var keyAlias: String?
        get() = prefs.getString(KEY_KEY_ALIAS, null)
        set(value) = prefs.edit().putString(KEY_KEY_ALIAS, value).apply()

    override var deviceStatus: String?
        get() = prefs.getString(KEY_DEVICE_STATUS, null)
        set(value) = prefs.edit().putString(KEY_DEVICE_STATUS, value).apply()

    override var apiBaseUrlOverride: String?
        get() = prefs.getString(KEY_API_BASE_URL_OVERRIDE, null)
        set(value) = prefs.edit().putString(KEY_API_BASE_URL_OVERRIDE, value).apply()

    override fun isRegistered(): Boolean = devicePublicId != null

    /**
     * Lokalna ochrona przed replay (dyspozycja, ETAP 3: "lokalna ochrona replay").
     * Backend jest ostatecznym arbitrem (atomowe zużycie decyzji), ale telefon
     * dodatkowo pamięta niedawno przetworzone żądania, żeby nie podpisać tej samej
     * decyzji drugi raz (np. po podwójnym otwarciu tego samego linku/QR).
     * Wpisy są przechowywane jako "request_id:expires_at" i porządkowane przy
     * każdym odczycie/zapisie na podstawie własnego expires_at żądania.
     */
    override fun isRequestConsumedLocally(requestId: String, nowEpochSeconds: Long): Boolean {
        return readConsumedRequests(nowEpochSeconds).containsKey(requestId)
    }

    override fun markRequestConsumedLocally(requestId: String, expiresAtEpochSeconds: Long, nowEpochSeconds: Long) {
        val consumed = readConsumedRequests(nowEpochSeconds).toMutableMap()
        consumed[requestId] = expiresAtEpochSeconds
        writeConsumedRequests(consumed)
    }

    private fun readConsumedRequests(nowEpochSeconds: Long): Map<String, Long> {
        val raw = prefs.getString(KEY_CONSUMED_REQUESTS, null).orEmpty()
        if (raw.isBlank()) return emptyMap()
        return raw.split(";")
            .mapNotNull { entry ->
                val parts = entry.split(":")
                if (parts.size != 2) return@mapNotNull null
                val expiresAt = parts[1].toLongOrNull() ?: return@mapNotNull null
                parts[0] to expiresAt
            }
            // Usuwamy wpisy, których własny TTL już minął — nie ma sensu pamiętać ich w nieskończoność.
            .filter { (_, expiresAt) -> expiresAt >= nowEpochSeconds }
            .toMap()
    }

    private fun writeConsumedRequests(consumed: Map<String, Long>) {
        val serialized = consumed.entries.joinToString(";") { (id, expiresAt) -> "$id:$expiresAt" }
        prefs.edit().putString(KEY_CONSUMED_REQUESTS, serialized).apply()
    }

    override fun clear() {
        prefs.edit().clear().apply()
    }

    companion object {
        private const val PREFS_FILE_NAME = "dors3_mobile_secure_prefs"
        private const val KEY_DEVICE_PUBLIC_ID = "device_public_id"
        private const val KEY_CREDENTIAL_PUBLIC_ID = "credential_public_id"
        private const val KEY_API_TOKEN = "api_token"
        private const val KEY_API_TOKEN_EXPIRES_AT = "api_token_expires_at"
        private const val KEY_KEY_ALIAS = "key_alias"
        private const val KEY_DEVICE_STATUS = "device_status"
        private const val KEY_API_BASE_URL_OVERRIDE = "api_base_url_override"
        private const val KEY_CONSUMED_REQUESTS = "consumed_requests"
    }
}
