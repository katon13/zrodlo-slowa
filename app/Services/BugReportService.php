<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class BugReportService
{
    public function __construct(
        private readonly Database $db,
        private readonly TalentService $talent,
    ) {}

    /** @return list<array<string,mixed>> */
    public function allForAdmin(int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        return $this->db->all(
            "SELECT b.*,u.display_name AS user_name,u.email AS user_email,a.display_name AS reviewer_name
             FROM bug_reports b
             LEFT JOIN users u ON u.id=b.user_id
             LEFT JOIN users a ON a.id=b.reviewed_by_admin_id
             ORDER BY CASE b.status WHEN 'submitted' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END,
                      b.created_at DESC,b.id DESC
             LIMIT {$limit}",
        );
    }

    public function create(
        ?int $userId,
        string $pageUrl,
        string $description,
        ?string $details,
        ?string $attachmentPath,
        ?string $attachmentMime,
    ): int {
        $pageUrl = mb_substr(trim($pageUrl), 0, 1000);
        $description = mb_substr(trim($description), 0, 5000);
        $details = mb_substr(trim((string)$details), 0, 10000) ?: null;
        $attachmentPath = trim((string)$attachmentPath) ?: null;
        $attachmentMime = strtolower(trim((string)$attachmentMime)) ?: null;
        if ($pageUrl === '' || $description === '' || $details === null) {
            throw new \InvalidArgumentException('Opisz błąd, wskaż stronę i podaj kroki potrzebne do jego powtórzenia.');
        }
        if ($attachmentPath === null || !in_array($attachmentMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Dołącz zdjęcie ekranu w formacie JPG, PNG albo WEBP.');
        }
        return $this->db->insert(
            'INSERT INTO bug_reports(public_id,user_id,page_url,description,details,attachment_path,attachment_mime,status,created_at,updated_at)
             VALUES(:public_id,:user,:page_url,:description,:details,:attachment,:mime,\'submitted\',NOW(),NOW())',
            [
                'public_id' => $this->uuidV4(),
                'user' => $userId,
                'page_url' => $pageUrl,
                'description' => $description,
                'details' => $details,
                'attachment' => $attachmentPath,
                'mime' => $attachmentMime,
            ],
        );
    }

    public function accept(int $reportId, int $adminId, string $note = ''): array
    {
        return $this->db->transaction(function (Database $db) use ($reportId, $adminId, $note): array {
            $report = $db->one('SELECT * FROM bug_reports WHERE id=:id FOR UPDATE', ['id' => $reportId]);
            if ($report === null) {
                throw new \RuntimeException('Nie znaleziono zgłoszenia.');
            }
            if ((string)$report['status'] === 'accepted') {
                return ['duplicate' => true, 'job_public_id' => $report['reward_job_public_id'] ?? null];
            }
            if ((string)$report['status'] !== 'submitted') {
                throw new \RuntimeException('Zgłoszenie zostało już rozpatrzone.');
            }
            if (empty($report['user_id'])) {
                throw new \RuntimeException('Anonimowe zgłoszenie można zaakceptować, ale nie można przypisać mu TT. Najpierw skontaktuj się ze zgłaszającym.');
            }
            $rule = $db->one(
                "SELECT is_active,points_amount FROM activity_reward_rules WHERE activity_type='bug_report_bonus' FOR UPDATE",
            );
            $points = (int)($rule['points_amount'] ?? 0);
            if ((int)($rule['is_active'] ?? 0) !== 1 || $points <= 0) {
                throw new \RuntimeException('Włącz i ustaw nagrodę „Zaakceptowane zgłoszenie błędu” w Programie Talent.');
            }
            $db->query(
                "UPDATE bug_reports SET status='accepted',reward_qualified=TRUE,reward_points=:points,
                        review_note=:note,reviewed_by_admin_id=:admin,reviewed_at=NOW(),updated_at=NOW()
                 WHERE id=:id",
                ['points' => $points, 'note' => mb_substr(trim($note), 0, 5000) ?: null, 'admin' => $adminId, 'id' => $reportId],
            );
            $job = $this->talent->queueAward(
                (int)$report['user_id'],
                'bug_report_bonus',
                'bug_report',
                $reportId,
                ['bug_report_qualified' => true, 'bug_report_points' => $points],
            );
            if (($job['queued'] ?? false) !== true || empty($job['public_id'])) {
                throw new \RuntimeException('Nie udało się przekazać nagrody do Programu Talent. Decyzja nie została zapisana.');
            }
            $db->query(
                'UPDATE bug_reports SET reward_job_public_id=:job WHERE id=:id',
                ['job' => (string)$job['public_id'], 'id' => $reportId],
            );
            return ['duplicate' => false, 'points' => $points, 'job_public_id' => (string)$job['public_id']];
        });
    }

    public function reject(int $reportId, int $adminId, string $note): void
    {
        if (mb_strlen(trim($note)) < 3) {
            throw new \InvalidArgumentException('Krótko wyjaśnij powód odrzucenia.');
        }
        $updated = $this->db->query(
            "UPDATE bug_reports SET status='rejected',reward_qualified=FALSE,reward_points=0,
                    review_note=:note,reviewed_by_admin_id=:admin,reviewed_at=NOW(),updated_at=NOW()
             WHERE id=:id AND status='submitted'",
            ['note' => mb_substr(trim($note), 0, 5000), 'admin' => $adminId, 'id' => $reportId],
        );
        if ($updated->rowCount() !== 1) {
            throw new \RuntimeException('Zgłoszenie nie istnieje albo zostało już rozpatrzone.');
        }
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
