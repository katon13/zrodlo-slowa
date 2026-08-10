package pl.zrodloslowa.app.webview

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

/**
 * Źródło prawdy dla `FLAG_SECURE` (doprecyzowanie dyspozycji pkt 4.2).
 *
 * Wcześniejsza wersja zgadywała "poufność" ekranu na podstawie SŁÓW
 * KLUCZOWYCH w adresie URL (`wallet`, `login`, `author` itd.) — to była
 * lokalna heurystyka mobilna, duplikująca wiedzę o strukturze serwisu WWW,
 * której aplikacja nie powinna posiadać (serwer decyduje o treści i
 * dostępie, telefon ma tylko chronić ekran). Zgodnie z doprecyzowaniem
 * dyspozycji, ochrona ekranu wynika WYŁĄCZNIE z dwóch, jawnych sygnałów:
 *
 * 1. [authFlowActive] — użytkownik znajduje się w trakcie procesu logowania,
 *    weryfikacji dwuetapowej (2FA) lub resetu hasła ([pl.zrodloslowa.app.ui.auth.LoginScreen]).
 *    To ekrany, na których wpisuje się dane uwierzytelniające, więc mają być
 *    chronione, ZANIM serwer w ogóle potwierdzi zalogowanie.
 * 2. [sessionConfirmed] — serwer POTWIERDZIŁ (rzeczywistym żądaniem HTTP do
 *    chronionej trasy, patrz [pl.zrodloslowa.app.session.WebSessionManager.verifySession])
 *    aktywną sesję zalogowanego użytkownika — nigdy na podstawie samej
 *    obecności cookie.
 *
 * [pl.zrodloslowa.app.ui.auth.AuthGate] aktualizuje [sessionConfirmed]: przy
 * PIERWSZYM potwierdzeniu ustawia `true`; podczas KOLEJNEJ weryfikacji
 * (ON_RESUME, `logoutEpoch`) NIE zdejmuje ochrony w trakcie oczekiwania na
 * odpowiedź serwera — dopiero jawna odpowiedź "niezalogowany" (serwer
 * przekierował do logowania) ustawia `false`. Żaden callback JavaScript ze
 * strony WWW nie jest tu używany — to wyłącznie natywny stan aplikacji.
 */
object SessionSecurityState {

    /** Trwa proces logowania/2FA/resetu hasła ([pl.zrodloslowa.app.ui.auth.LoginScreen] jest aktywny). */
    var authFlowActive: Boolean by mutableStateOf(false)

    /**
     * Serwer potwierdził aktywną sesję zalogowanego użytkownika. `false`
     * oznacza jawne potwierdzenie przez serwer braku sesji (niezalogowany
     * lub wylogowany) — NIGDY chwilowy stan "w trakcie weryfikacji".
     */
    var sessionConfirmed: Boolean by mutableStateOf(false)

    /** Czy ekran ma być chroniony `FLAG_SECURE` — patrz dokumentacja klasy. */
    val isProtectionActive: Boolean
        get() = authFlowActive || sessionConfirmed
}
