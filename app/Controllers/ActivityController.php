<?php
namespace App\Controllers;

use App\Services\ActivityService;
use App\Services\LedgerService;
use App\Services\TalentService;

final class ActivityController extends BaseController
{
    public function record(): never
    {
        $userId = $this->requireAuth();
        $type = (string)($_POST['activity_type'] ?? '');
        $refType = trim((string)($_POST['reference_type'] ?? '')) ?: null;
        $refId = isset($_POST['reference_id']) && $_POST['reference_id'] !== '' ? (int)$_POST['reference_id'] : null;
        $note = trim((string)($_POST['note'] ?? ''));
        $back = $this->safeLocalPath((string)($_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? '/')));

        try {
            $result = $this->service()->record($userId, $type, $refType, $refId, $note);
            $label = $this->service()->allowedTypes()[$type] ?? 'aktywność';
            $this->app->session->flash('success', $result ? 'Aktywność została zapisana: ' . $label . '.' : 'Ten bonus osiągnął dzienny limit albo został już zapisany.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się zapisać aktywności.', 'activity_record'));
        }

        redirect($back);
    }

    private function service(): ActivityService
    {
        return new ActivityService($this->app->db, $this->talentService());
    }

    private function safeLocalPath(string $candidate): string
    {
        $candidate = trim($candidate);
        if (
            $candidate === ''
            || $candidate[0] !== '/'
            || str_starts_with($candidate, '//')
            || str_contains($candidate, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1
        ) {
            return '/';
        }
        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            return '/';
        }
        return $candidate;
    }
}
