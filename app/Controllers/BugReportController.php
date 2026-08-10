<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\RequestContext;
use App\Services\BugReportService;
use App\Services\UploadService;

final class BugReportController extends BaseController
{
    public function form(): string
    {
        $language = function_exists('public_language') ? public_language() : 'pl';
        $rewardRule = $this->app->db->one(
            "SELECT points_amount,amount_minor,is_active
             FROM activity_reward_rules
             WHERE activity_type='bug_report_bonus'
             LIMIT 1"
        );
        $rewardPoints = $rewardRule !== null
            && (int)($rewardRule['is_active'] ?? 0) === 1
            && (int)($rewardRule['amount_minor'] ?? 0) === 0
            ? max(0, (int)($rewardRule['points_amount'] ?? 0))
            : 0;
        return $this->view('bug_reports/form', [
            'title' => t('bug_report.title', $language),
            'suggested_url' => mb_substr(trim((string)($_GET['from'] ?? ($_SERVER['HTTP_REFERER'] ?? ''))), 0, 1000),
            'bug_reward_points' => $rewardPoints,
        ]);
    }

    public function submit(): never
    {
        $userId = $this->requireAuth();
        $language = function_exists('public_language') ? public_language() : 'pl';
        $attachment = null;
        $upload = new UploadService($this->app->db, $this->app->objectStorage);
        try {
            $rateLimiter = $this->app->rateLimiter;
            $userKey = 'bug-report:user:' . $userId;
            $ipKey = 'bug-report:ip:' . (RequestContext::ipAddress() ?? 'unknown');
            if ($rateLimiter !== null
                && ($rateLimiter->tooManyAttempts($userKey, 5) || $rateLimiter->tooManyAttempts($ipKey, 15))
            ) {
                throw new \RuntimeException(t('bug_report.rate_limited', $language));
            }
            $rateLimiter?->hit($userKey, 3600);
            $rateLimiter?->hit($ipKey, 3600);
            if (!isset($_FILES['attachment'])
                || (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
            ) {
                throw new \InvalidArgumentException(t('bug_report.attachment_required', $language));
            }
            $attachment = $upload->uploadBugReportAttachment($_FILES['attachment']);
            (new BugReportService($this->app->db, $this->talentService()))->create(
                $userId,
                (string)($_POST['page_url'] ?? ''),
                (string)($_POST['description'] ?? ''),
                (string)($_POST['details'] ?? ''),
                $attachment['path'],
                $attachment['mime'],
            );
            $this->app->session->flash('success', t('bug_report.success', $language));
            redirect(public_language_url($language, '/report-bug'));
        } catch (\Throwable $error) {
            if (is_array($attachment) && !empty($attachment['path'])) {
                try { $upload->deleteReference((string)$attachment['path']); } catch (\Throwable) {}
            }
            $this->app->session->flash('error', $this->safeError($error, t('bug_report.error', $language), 'bug_report_submit'));
            redirect(public_language_url($language, '/report-bug'));
        }
    }
}
