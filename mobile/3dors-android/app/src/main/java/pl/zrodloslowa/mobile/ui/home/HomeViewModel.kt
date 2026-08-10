package pl.zrodloslowa.mobile.ui.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import pl.zrodloslowa.mobile.data.ApprovalRepository
import pl.zrodloslowa.mobile.data.DeviceCredentialStore
import pl.zrodloslowa.mobile.model.ApprovalRequestDetails

/**
 * Ekran startowy 3DORS Mobile jest prostą aplikacją uwierzytelniającą, a nie
 * panelem zarządzania (Effective Issue). Domyślnie automatycznie wyszukuje
 * aktywne żądanie z pobliskiego urządzenia (ta sama sieć/deep link) i pokazuje
 * wyłącznie stan oczekiwania — bez menu, dashboardu ani kart "trzech drzwi".
 *
 * Zależności są funkcyjne (a nie konkretne klasy [ApprovalRepository]/
 * [DeviceCredentialStore]), aby ViewModel dało się przetestować bez realnego
 * kontekstu Androida czy sieci (patrz [HomeViewModelTest]).
 *
 * Polling (dyspozycja, pkt "Polling"): NIE odpytuje serwera w nieskończoność ze
 * stałym interwałem — używa narastającego backoffu ([BACKOFF_STEPS_MILLIS]:
 * 0,5 / 1 / 2 / 4 s, dalej utrzymywanego na 4 s) i KOŃCZY pętlę natychmiast po
 * znalezieniu aktywnego żądania (wynik) albo po anulowaniu (opuszczenie ekranu/
 * wyczyszczenie ViewModelu). Błąd sieci nie kończy pollingu — ekran startowy
 * nie ma przycisku ręcznego retry, więc telefon próbuje dalej z tym samym
 * backoffem, aż połączenie wróci albo znajdzie się żądanie.
 */
class HomeViewModel(
    private val getDevicePublicId: () -> String?,
    private val findPendingRequest: suspend (String) -> Result<ApprovalRequestDetails?>,
) : ViewModel() {

    constructor(
        repository: ApprovalRepository,
        credentialStore: DeviceCredentialStore,
    ) : this(
        getDevicePublicId = { credentialStore.devicePublicId },
        findPendingRequest = { devicePublicId -> repository.findPendingRequest(devicePublicId) },
    )

    private val _state = MutableStateFlow<HomeUiState>(HomeUiState.Idle)
    val state: StateFlow<HomeUiState> = _state

    private var pollingJob: Job? = null

    /** Uruchamia automatyczne wyszukiwanie żądania (pomijane, jeśli urządzenie nie jest zarejestrowane). */
    fun startWatching() {
        if (pollingJob != null) return

        val devicePublicId = getDevicePublicId()
        if (devicePublicId == null) {
            _state.value = HomeUiState.NotRegistered
            return
        }

        launchPolling(devicePublicId)
    }

    private fun launchPolling(devicePublicId: String) {
        pollingJob?.cancel()
        pollingJob = viewModelScope.launch {
            _state.value = HomeUiState.Searching
            var backoffStepIndex = 0
            while (isActive) {
                val result = findPendingRequest(devicePublicId)
                val requestFound = result.fold(
                    onSuccess = { details ->
                        if (details != null) {
                            _state.value = HomeUiState.RequestFound(details)
                            true
                        } else {
                            _state.value = HomeUiState.Searching
                            false
                        }
                    },
                    onFailure = { throwable ->
                        _state.value = HomeUiState.Error(throwable.message ?: "Błąd sprawdzania żądań")
                        false
                    },
                )
                // Zakończ pętlę natychmiast po znalezieniu wyniku (dyspozycja:
                // "zakończ po wyniku") — nawigacja do ekranu operacji nastąpi na
                // poziomie hosta nawigacji, obserwującego [state].
                if (requestFound) break

                val backoffMillis = BACKOFF_STEPS_MILLIS.getOrElse(backoffStepIndex) { BACKOFF_STEPS_MILLIS.last() }
                if (backoffStepIndex < BACKOFF_STEPS_MILLIS.lastIndex) backoffStepIndex++
                delay(backoffMillis)
            }
            pollingJob = null
        }
    }

    /** Wywoływane po zakończeniu obsługi żądania (zatwierdzenie/odrzucenie/wygaśnięcie) — wraca do oczekiwania. */
    fun resumeWatching() {
        val devicePublicId = getDevicePublicId() ?: return
        launchPolling(devicePublicId)
    }

    /** Anuluje bieżący polling (opuszczenie ekranu startowego) — dyspozycja: "zakończ po... anulowaniu". */
    fun stopWatching() {
        pollingJob?.cancel()
        pollingJob = null
    }

    override fun onCleared() {
        stopWatching()
        super.onCleared()
    }

    companion object {
        /** Backoff pollingu w ms: 0,5 / 1 / 2 / 4 s, dalej utrzymywany na 4 s (dyspozycja, pkt "Polling"). */
        val BACKOFF_STEPS_MILLIS = listOf(500L, 1000L, 2000L, 4000L)
    }
}

/** Typowane stany ekranu startowego (Effective Issue: pkt "typowane stany"). */
sealed interface HomeUiState {
    data object Idle : HomeUiState
    data object Searching : HomeUiState
    data class RequestFound(val details: ApprovalRequestDetails) : HomeUiState
    data object NotRegistered : HomeUiState
    data class Error(val message: String) : HomeUiState
}
