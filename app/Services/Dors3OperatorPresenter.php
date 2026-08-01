<?php
declare(strict_types=1);

namespace App\Services;

/** Czytelne dla operatora nazwy stanów i zdarzeń zapisywanych przez 3DORS. */
final class Dors3OperatorPresenter
{
    /** @var array<string,string> */
    private const ACTIONS = [
        'security.login.blocked' => 'Zablokowano próbę logowania',
        'security.login.locked' => 'Czasowo zablokowano logowanie',
        'security.login.password_failed' => 'Nieudana próba logowania',
        'security.login.success' => 'Prawidłowe logowanie administratora',
        'security.login.new_context' => 'Logowanie z nowego miejsca lub urządzenia',
        'security.admin_session.started' => 'Rozpoczęto bezpieczną sesję administratora',
        'security.admin_session.max_expired' => 'Sesja administratora wygasła',
        'security.admin_session.idle_locked' => 'Panel zablokowano po bezczynności',
        'security.admin_session.unlocked' => 'Ponownie potwierdzono sesję administratora',
        'security.admin_session.ended' => 'Zakończono sesję administratora',
        'security.step_up.started' => 'Rozpoczęto potwierdzenie ważnej operacji',
        'security.step_up.rejected' => 'Odrzucono potwierdzenie ważnej operacji',
        'security.step_up.failed' => 'Nie udało się potwierdzić ważnej operacji',
        'security.step_up.approved' => 'Potwierdzono ważną operację',
        'security.step_up.blocked' => 'Zablokowano ważną operację',
        'security.recovery_codes.generated' => 'Utworzono nowy zestaw kodów awaryjnych',
        'security.recovery_codes.confirmed' => 'Potwierdzono zapisanie kodów awaryjnych',
        'security.recovery.executed' => 'Wykonano awaryjne odzyskanie dostępu',
        'security.recovery.failed' => 'Nieudana próba odzyskania dostępu',
        'recovery_codes.generate' => 'Wydanie nowego zestawu kodów awaryjnych',
        'recovery_codes.confirm' => 'Potwierdzenie zapisania kodów awaryjnych',
        'earnings.rules.update' => 'Zmiana zasad programu Talent',
        'site_settings.update' => 'Zmiana ustawień serwisu',
        'security.snajper_settings.update' => 'Zmiana ustawień ochrony Snajper',
        'payment.manual_mark_paid' => 'Ręczne oznaczenie płatności jako opłaconej',
        'payment_settings.update' => 'Zmiana ustawień płatności',
        'wallet_transfer.approve' => 'Zatwierdzenie transferu środków',
        'wallet_transfer.reject' => 'Odrzucenie transferu środków',
        'financial_approval.execute' => 'Wykonanie zatwierdzonej operacji finansowej',
        'financial_approval.reject' => 'Odrzucenie operacji finansowej',
        'ai.settings.update' => 'Zmiana ustawień narzędzi AI',
        'payout.status_request' => 'Zmiana statusu wypłaty',
        'user.status.update' => 'Zmiana statusu użytkownika',
        'user.anonymize' => 'Anonimizacja użytkownika',
        'user.hard_clean' => 'Usunięcie danych użytkownika',
        'user.primary_role.update' => 'Zmiana głównej roli użytkownika',
        'author.approve' => 'Zatwierdzenie autora',
        'user.operational_permissions.update' => 'Zmiana uprawnień operacyjnych',
        'user.editorial_roles.update' => 'Zmiana ról redakcyjnych',
        'user.totp.disable' => 'Wyłączenie dodatkowego uwierzytelnienia użytkownika',
        'wallet.manual_talent_reward.request' => 'Ręczna nagroda programu Talent',
    ];

    /** @var array<string,string> */
    private const RESOURCES = [
        'user' => 'Konto użytkownika',
        'session' => 'Sesja administratora',
        'settings_group' => 'Ustawienia',
        'recovery_code_batch' => 'Kody awaryjne',
        'payment' => 'Płatność',
        'wallet' => 'Portfel',
        'wallet_transfer' => 'Transfer środków',
        'financial_approval' => 'Zgoda finansowa',
        'payout' => 'Wypłata',
    ];

    /** @var array<string,string> */
    private const REASONS = [
        'new_ip_and_device' => 'Nowy adres sieciowy i nowe urządzenie',
        'new_ip' => 'Nowy adres sieciowy',
        'new_device' => 'Nowe urządzenie lub przeglądarka',
        'admin_login_lock_active' => 'Aktywna czasowa blokada logowania',
        'password_invalid' => 'Nieprawidłowe hasło administratora',
        'approval_expired' => 'Potwierdzenie operacji wygasło',
        'approval_mismatch' => 'Potwierdzenie dotyczyło innej operacji',
        'invalid_or_unconfirmed_recovery_code' => 'Kod awaryjny jest nieprawidłowy lub nie został wcześniej potwierdzony',
    ];

    /** @var array<string,array{label:string,class:string}> */
    private const RESULTS = [
        'success' => ['label' => 'Wykonano', 'class' => 'success'],
        'approved' => ['label' => 'Zatwierdzono', 'class' => 'success'],
        'warning' => ['label' => 'Sprawdź', 'class' => 'warning'],
        'failure' => ['label' => 'Niepowodzenie', 'class' => 'danger'],
        'blocked' => ['label' => 'Zablokowano', 'class' => 'danger'],
        'rejected' => ['label' => 'Odrzucono', 'class' => 'danger'],
    ];

    /** @var array<string,array{label:string,class:string}> */
    private const RISKS = [
        'low' => ['label' => 'Niskie', 'class' => 'neutral'],
        'medium' => ['label' => 'Standardowe', 'class' => 'warning'],
        'high' => ['label' => 'Wysokie', 'class' => 'danger'],
        'critical' => ['label' => 'Krytyczne', 'class' => 'danger'],
    ];

    /** @var array<string,string> */
    private const GATE_LABELS = [
        'primary_key_tested' => 'Podstawowy klucz został dodany i sprawdzony',
        'backup_key_tested' => 'Zapasowy klucz został dodany i sprawdzony',
        'ten_recovery_codes' => 'Utworzono komplet 10 kodów awaryjnych',
        'recovery_codes_confirmed' => 'Potwierdzono bezpieczne zapisanie kodów awaryjnych',
        'recovery_cli_tested' => 'Sprawdzono procedurę awaryjnego odzyskania dostępu',
        'postgres_backup_completed' => 'Wykonano i sprawdzono kopię bazy danych',
        'cross_instance_tested' => 'Sprawdzono działanie na obu instancjach aplikacji',
        'replay_tested' => 'Sprawdzono ochronę przed ponownym użyciem potwierdzenia',
        'bad_origin_tested' => 'Sprawdzono odrzucanie obcego adresu serwisu',
        'explicit_user_approval' => 'Właściciel wyraził osobną zgodę na wymaganie klucza',
    ];

    /** @param array<string,mixed> $event @return array<string,mixed> */
    public static function event(array $event): array
    {
        $action = (string)($event['action'] ?? '');
        $resource = (string)($event['resource_type'] ?? '');
        $resourceId = (string)($event['resource_id'] ?? '');
        $reason = (string)($event['reason'] ?? '');
        $result = (string)($event['result'] ?? '');
        $risk = (string)($event['risk_level'] ?? '');
        $resourceLabel = self::RESOURCES[$resource] ?? ($resource !== '' ? self::humanize($resource) : 'Panel administracyjny');
        if ($resource !== 'session' && $resourceId !== '' && ctype_digit($resourceId)) {
            $resourceLabel .= ' #' . $resourceId;
        }

        return $event + [
            'action_label' => self::ACTIONS[$action] ?? self::humanize($action),
            'resource_label' => $resourceLabel,
            'reason_label' => $reason !== '' ? (self::REASONS[$reason] ?? self::humanize($reason)) : 'Brak dodatkowych uwag',
            'result_label' => self::RESULTS[$result]['label'] ?? self::humanize($result),
            'result_class' => self::RESULTS[$result]['class'] ?? 'neutral',
            'risk_label' => self::RISKS[$risk]['label'] ?? self::humanize($risk),
            'risk_class' => self::RISKS[$risk]['class'] ?? 'neutral',
        ];
    }

    /** @param array<string,bool> $gate @return list<array{key:string,label:string,passed:bool}> */
    public static function readiness(array $gate): array
    {
        $items = [];
        foreach ($gate as $key => $passed) {
            $items[] = [
                'key' => (string)$key,
                'label' => self::GATE_LABELS[(string)$key] ?? self::humanize((string)$key),
                'passed' => (bool)$passed,
            ];
        }
        return $items;
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            'prepare' => 'Ochrona hasłem — przygotowanie do kluczy',
            'test' => 'Testowanie kluczy bez wymuszania',
            'required' => 'Klucze wymagane',
            default => self::humanize($mode),
        };
    }

    public static function confirmationLabel(string $method): string
    {
        return match ($method) {
            'password' => 'Hasło administratora',
            'fido2' => 'Fizyczny klucz bezpieczeństwa',
            default => self::humanize($method),
        };
    }

    public static function credentialRoleLabel(string $role): string
    {
        return match ($role) {
            'primary' => 'Podstawowy',
            'backup' => 'Zapasowy',
            default => self::humanize($role),
        };
    }

    public static function credentialStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Aktywny',
            'revoked' => 'Wycofany',
            'disabled' => 'Wyłączony',
            default => self::humanize($status),
        };
    }

    private static function humanize(string $value): string
    {
        $value = preg_replace('/^(security|admin)\./', '', trim($value)) ?? trim($value);
        $value = trim((string)preg_replace('/[._-]+/', ' ', $value));
        return $value === '' ? 'Nie określono' : ucfirst($value);
    }
}
