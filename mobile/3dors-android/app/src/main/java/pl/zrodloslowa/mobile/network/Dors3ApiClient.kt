package pl.zrodloslowa.mobile.network

import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import pl.zrodloslowa.mobile.BuildConfig
import pl.zrodloslowa.mobile.config.EnvironmentConfig
import retrofit2.Retrofit
import retrofit2.converter.moshi.MoshiConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Fabryka klienta API 3DORS. Adres bazowy jest konfigurowalny w czasie działania
 * (tryb emulatora domyślnie, a dla telefonu fizycznego URL podany przy buildzie
 * i tunel `adb reverse` — patrz [pl.zrodloslowa.mobile.config.EnvironmentConfig]).
 */
object Dors3ApiClient {

    /**
     * Czysta, testowalna reguła bezpieczeństwa: w buildzie release POŁĄCZENIE
     * MUSI zostać zablokowane, jeśli adres API jest wciąż niewypełnionym
     * placeholderem (dyspozycja, pkt "Release safety"). W debug placeholdery są
     * dopuszczalne (np. lokalny adres testowy).
     */
    fun shouldBlockConnection(isDebugBuild: Boolean, baseUrl: String): Boolean =
        !isDebugBuild && EnvironmentConfig.isPlaceholderValue(baseUrl)

    /**
     * Czysty, testowalny wybór poziomu logowania HTTP (dyspozycja, pkt "Logi
     * HTTP"): BODY jest ZABRONIONY w każdym buildzie — w debug dopuszczalny
     * jest wyłącznie BASIC (metoda, URL, kod odpowiedzi), w release NONE.
     */
    fun loggingLevelFor(isDebugBuild: Boolean): HttpLoggingInterceptor.Level =
        if (isDebugBuild) HttpLoggingInterceptor.Level.BASIC else HttpLoggingInterceptor.Level.NONE

    fun create(baseUrl: String): Dors3ApiService {
        // Release safety (dyspozycja, pkt "Release safety"): build release NIE
        // MOŻE działać z niewypełnionym placeholderem adresu API
        // (np. "https://CHANGE_ME/"). Zamiast po cichu łączyć się z nieistniejącym
        // hostem, bezpiecznie blokujemy każde połączenie sieciowe.
        if (shouldBlockConnection(BuildConfig.DEBUG, baseUrl)) {
            throw IllegalStateException(
                "Release build ma nieprawidłowy adres API (placeholder: \"$baseUrl\") — połączenia sieciowe są zablokowane",
            )
        }

        val moshi = Moshi.Builder()
            .add(KotlinJsonAdapterFactory())
            .build()

        // Logi HTTP (dyspozycja, pkt "Logi HTTP"): BODY logging jest ZABRONIONY —
        // nigdy nie loguje się challenge, podpisów, tokenów, danych wypłat ani
        // pełnych payloadów. W debug dopuszczalny jest wyłącznie poziom BASIC
        // (metoda, URL, kod odpowiedzi, długość treści) — bez nagłówków i body.
        val loggingInterceptor = HttpLoggingInterceptor().apply {
            level = loggingLevelFor(BuildConfig.DEBUG)
        }

        val okHttpClient = OkHttpClient.Builder()
            .addInterceptor(loggingInterceptor)
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(15, TimeUnit.SECONDS)
            .writeTimeout(15, TimeUnit.SECONDS)
            .build()

        val normalizedBaseUrl = if (baseUrl.endsWith("/")) baseUrl else "$baseUrl/"

        val retrofit = Retrofit.Builder()
            .baseUrl(normalizedBaseUrl)
            .client(okHttpClient)
            .addConverterFactory(MoshiConverterFactory.create(moshi))
            .build()

        return retrofit.create(Dors3ApiService::class.java)
    }
}
