package pl.zrodloslowa.mobile.ui.approval

import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import pl.zrodloslowa.mobile.data.ApprovalRepository
import pl.zrodloslowa.mobile.model.ApprovalRequestDetails

/**
 * Stan i logika ekranu logowania/operacji (pkt 5.2, 6.1). Pobiera dokładny opis
 * żądania z serwera i realizuje ZATWIERDŹ / ODRZUĆ przez [ApprovalRepository].
 */
class ApprovalViewModel(
    private val repository: ApprovalRepository,
) : ViewModel() {

    private val _state = MutableStateFlow<ApprovalUiState>(ApprovalUiState.Loading)
    val state: StateFlow<ApprovalUiState> = _state

    fun loadRequest(publicId: String) {
        _state.value = ApprovalUiState.Loading
        viewModelScope.launch {
            val result = repository.fetchRequest(publicId)
            result.fold(
                onSuccess = { details ->
                    val nowSeconds = System.currentTimeMillis() / 1000
                    _state.value = if (repository.isExpired(details, nowSeconds)) {
                        ApprovalUiState.Expired
                    } else {
                        ApprovalUiState.Ready(details)
                    }
                },
                onFailure = { throwable ->
                    _state.value = mapFailureToState(throwable)
                },
            )
        }
    }

    fun approve(activity: FragmentActivity, details: ApprovalRequestDetails) {
        decide(activity, details, approve = true)
    }

    fun reject(activity: FragmentActivity, details: ApprovalRequestDetails) {
        decide(activity, details, approve = false)
    }

    private fun decide(activity: FragmentActivity, details: ApprovalRequestDetails, approve: Boolean) {
        _state.value = ApprovalUiState.Submitting
        viewModelScope.launch {
            val result = repository.approveOrReject(
                activity = activity,
                details = details,
                approve = approve,
            )
            result.fold(
                onSuccess = {
                    _state.value = if (approve) ApprovalUiState.Approved else ApprovalUiState.Rejected
                },
                onFailure = { throwable ->
                    _state.value = mapFailureToState(throwable)
                },
            )
        }
    }

    /** Mapuje typowane błędy repozytorium na dedykowane stany ekranu (ETAP 5: "obsługa urządzenia zawieszonego i unieważnionego"). */
    private fun mapFailureToState(throwable: Throwable): ApprovalUiState = when (throwable) {
        is ApprovalRepository.DeviceSuspendedException -> ApprovalUiState.DeviceSuspended
        is ApprovalRepository.DeviceRevokedException -> ApprovalUiState.DeviceRevoked
        is ApprovalRepository.RequestAlreadyProcessedException -> ApprovalUiState.AlreadyProcessed
        is ApprovalRepository.RequestExpiredException -> ApprovalUiState.Expired
        is ApprovalRepository.MissingCredentialException -> ApprovalUiState.DeviceRevoked
        else -> ApprovalUiState.Error(throwable.message ?: "Błąd zatwierdzania")
    }
}

sealed interface ApprovalUiState {
    data object Loading : ApprovalUiState
    data class Ready(val details: ApprovalRequestDetails) : ApprovalUiState
    data object Submitting : ApprovalUiState
    data object Approved : ApprovalUiState
    data object Rejected : ApprovalUiState
    data object Expired : ApprovalUiState
    data object DeviceSuspended : ApprovalUiState
    data object DeviceRevoked : ApprovalUiState
    data object AlreadyProcessed : ApprovalUiState
    data class Error(val message: String) : ApprovalUiState
}
