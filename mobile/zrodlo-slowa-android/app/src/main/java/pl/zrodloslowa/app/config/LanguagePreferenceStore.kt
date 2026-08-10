package pl.zrodloslowa.app.config

import android.content.Context

/**
 * ETAP 8 (uzupełnienie): trwały zapis ostatniego jawnego wyboru języka
 * użytkownika w aplikacji (pkt 7.2 p.1 i 7.3 dyspozycji: "zapisać wybór
 * lokalnie"). Przechowuje wyłącznie kod jednej z sześciu wersji z
 * [SiteConfig] — nigdy nie tworzy nowej wersji językowej i nie zastępuje
 * wyniku serwera jako źródła prawdy dla treści serwisu.
 */
object LanguagePreferenceStore {

    private const val PREFS_NAME = "zrodlo_slowa_language_prefs"
    private const val KEY_MANUAL_LANGUAGE = "manual_language_code"

    fun getManualLanguage(context: Context): String? {
        val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        return prefs.getString(KEY_MANUAL_LANGUAGE, null)
    }

    fun setManualLanguage(context: Context, languageCode: String) {
        val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        prefs.edit().putString(KEY_MANUAL_LANGUAGE, languageCode).apply()
    }

    /**
     * Czyści jawny wybór użytkownika — powrót do trybu "Automatycznie"
     * (język systemowy Android, z fallbackiem do PL) zamiast zapisanego
     * na trwałe wyboru.
     */
    fun clearManualLanguage(context: Context) {
        val prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        prefs.edit().remove(KEY_MANUAL_LANGUAGE).apply()
    }
}
