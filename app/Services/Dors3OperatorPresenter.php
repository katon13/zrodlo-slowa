<?php
declare(strict_types=1);

namespace App\Services;

/** Czytelne nazwy zdarzeń 3DORS pobierane ze wspólnego katalogu UI. */
final class Dors3OperatorPresenter
{
    /** @param array<string,mixed> $event @return array<string,mixed> */
    public static function event(array $event, ?string $language = null): array
    {
        $action = (string)($event['action'] ?? '');
        $resource = (string)($event['resource_type'] ?? '');
        $resourceId = (string)($event['resource_id'] ?? '');
        $reason = (string)($event['reason'] ?? '');
        $result = (string)($event['result'] ?? '');
        $risk = (string)($event['risk_level'] ?? '');
        $resourceLabel = $resource !== ''
            ? Dors3UiText::option('resources', $resource, $language)
            : Dors3UiText::get('common.admin_panel', [], $language);
        if ($resource !== 'session' && $resourceId !== '' && ctype_digit($resourceId)) {
            $resourceLabel .= ' #' . $resourceId;
        }

        return $event + [
            'action_label' => Dors3UiText::option('events.actions', $action, $language),
            'resource_label' => $resourceLabel,
            'reason_label' => $reason !== ''
                ? Dors3UiText::option('events.reasons', $reason, $language)
                : Dors3UiText::get('common.no_notes', [], $language),
            'result_label' => Dors3UiText::option('events.results', $result, $language),
            'result_class' => self::resultClass($result),
            'risk_label' => Dors3UiText::option('events.risks', $risk, $language),
            'risk_class' => self::riskClass($risk),
        ];
    }

    /** @param array<string,mixed> $alert @return array<string,mixed> */
    public static function alert(array $alert, ?string $language = null): array
    {
        $technicalAction = (string)($alert['action'] ?? '');
        $logicalAction = trim((string)($alert['operation_action'] ?? '')) ?: $technicalAction;
        $presented = self::event(array_replace($alert, ['action' => $logicalAction]), $language);
        $presented['technical_action'] = $technicalAction;
        $presented['actor'] = trim((string)($presented['actor'] ?? ''))
            ?: Dors3UiText::get('sentinel.system_actor', [], $language);
        $presented['alert_reason_label'] = Dors3UiText::get(
            'sentinel.attention_' . self::attentionReason($alert),
            [],
            $language,
        );
        $presented['confirmation_label'] = Dors3UiText::get(
            'sentinel.confirmation_' . self::confirmation($alert),
            [],
            $language,
        );
        return $presented;
    }

    /** @param array<string,bool> $gate @return list<array{key:string,label:string,passed:bool}> */
    public static function readiness(array $gate): array
    {
        $items = [];
        foreach ($gate as $key => $passed) {
            $items[] = [
                'key' => (string)$key,
                'label' => Dors3UiText::option('events.gates', (string)$key),
                'passed' => (bool)$passed,
            ];
        }
        return $items;
    }

    public static function modeLabel(string $mode): string
    {
        return Dors3UiText::option('events.modes', $mode);
    }

    public static function confirmationLabel(string $method): string
    {
        return Dors3UiText::option('events.confirmations', $method);
    }

    public static function credentialRoleLabel(string $role): string
    {
        return Dors3UiText::option('events.credential_roles', $role);
    }

    public static function credentialStatusLabel(string $status): string
    {
        return Dors3UiText::option('events.credential_statuses', $status);
    }

    private static function resultClass(string $result): string
    {
        return match ($result) {
            'success', 'approved' => 'success',
            'warning' => 'warning',
            'failure', 'blocked', 'rejected' => 'danger',
            default => 'neutral',
        };
    }

    private static function riskClass(string $risk): string
    {
        return match ($risk) {
            'medium' => 'warning',
            'high', 'critical' => 'danger',
            default => 'neutral',
        };
    }

    /** @param array<string,mixed> $event */
    private static function attentionReason(array $event): string
    {
        $action = (string)($event['action'] ?? '');
        $result = (string)($event['result'] ?? '');
        if ($action === 'security.login.new_context') {
            return 'new_context';
        }
        if (str_contains($action, 'recovery')) {
            return 'recovery';
        }
        if ($action === 'mobile.signature.invalid') {
            return 'mobile_integrity';
        }
        if (in_array($result, ['failure', 'blocked', 'rejected'], true)) {
            return 'failed_security';
        }
        if ((string)($event['risk_level'] ?? '') === 'critical') {
            return 'critical_risk';
        }
        return 'important_operation';
    }

    /** @param array<string,mixed> $event */
    private static function confirmation(array $event): string
    {
        return match ((string)($event['action'] ?? '')) {
            'security.step_up.approved' => 'dors3',
            'mobile.request.approved' => 'mobile',
            default => in_array((string)($event['result'] ?? ''), ['success', 'approved'], true)
                ? 'recorded'
                : 'attention',
        };
    }
}
