package pl.zrodloslowa.mobile.ui.enrollment

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import pl.zrodloslowa.mobile.data.EnrollmentRepository
import pl.zrodloslowa.mobile.model.EnrollmentQrPayload

/**
 * Stan i logika ekranu rejestracji urządzenia (pkt 4.2). Parsuje QR, tworzy
 * klucz w Keystore i prosi o potwierdzenie kodu porównawczego.
 */
class EnrollmentViewModel(
    private val repository: EnrollmentRepository,
    private val appVersion: String,
    private val deviceModel: String,
    private val osVersion: String,
) : ViewModel() {

    private val moshiAdapter = Moshi.Builder()
        .add(KotlinJsonAdapterFactory())
        .build()
        .adapter(EnrollmentQrPayload::class.java)

    private val _state = MutableStateFlow<EnrollmentUiState>(EnrollmentUiState.AwaitingQrScan)
    val state: StateFlow<EnrollmentUiState> = _state

    fun onQrScanned(rawQrContent: String) {
        val payload = try {
            moshiAdapter.fromJson(rawQrContent)
        } catch (exception: Exception) {
            null
        }

        if (payload == null) {
            _state.value = EnrollmentUiState.Error("Nieprawidłowy kod QR rejestracji")
            return
        }

        _state.value = EnrollmentUiState.ReviewingDetails(payload)
    }

    fun confirmRegistrationDetails(payload: EnrollmentQrPayload) {
        _state.value = EnrollmentUiState.CreatingKey
        viewModelScope.launch {
            val result = repository.completeEnrollment(payload, appVersion, deviceModel, osVersion)
            result.fold(
                onSuccess = { enrollment ->
                    _state.value = EnrollmentUiState.AwaitingPanelApproval(
                        devicePublicId = enrollment.devicePublicId,
                        comparisonCode = enrollment.comparisonCode,
                    )
                },
                onFailure = { throwable ->
                    _state.value = EnrollmentUiState.Error(throwable.message ?: "Błąd rejestracji")
                },
            )
        }
    }

    fun checkActivation(devicePublicId: String, comparisonCode: String) {
        viewModelScope.launch {
            val result = repository.activationStatus(devicePublicId)
            result.fold(
                onSuccess = { status ->
                    _state.value = if (status == "active") EnrollmentUiState.Completed else {
                        EnrollmentUiState.AwaitingPanelApproval(devicePublicId, comparisonCode, status)
                    }
                },
                onFailure = { throwable ->
                    _state.value = EnrollmentUiState.Error(throwable.message ?: "Błąd sprawdzania aktywacji")
                },
            )
        }
    }

    fun rejectComparisonCode(devicePublicId: String, comparisonCode: String) {
        viewModelScope.launch {
            repository.rejectEnrollment(devicePublicId, comparisonCode).fold(
                onSuccess = { _state.value = EnrollmentUiState.Error("Kod porównawczy niezgodny — rejestracja odrzucona") },
                onFailure = { throwable -> _state.value = EnrollmentUiState.Error(throwable.message ?: "Błąd odrzucenia rejestracji") },
            )
        }
    }
}

sealed interface EnrollmentUiState {
    data object AwaitingQrScan : EnrollmentUiState
    data class ReviewingDetails(val payload: EnrollmentQrPayload) : EnrollmentUiState
    data object CreatingKey : EnrollmentUiState
    data class AwaitingPanelApproval(
        val devicePublicId: String,
        val comparisonCode: String,
        val serverStatus: String = "pending",
    ) : EnrollmentUiState
    data object Completed : EnrollmentUiState
    data class Error(val message: String) : EnrollmentUiState
}
