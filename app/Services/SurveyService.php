<?php
namespace App\Services;

use App\Core\Database;

final class SurveyService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?FraudGuardService $fraudGuard = null,
        private readonly ?EarningsJobDispatcher $earningsDispatcher = null,
    ) {}

    public function active(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->all('SELECT s.*, 
            (SELECT COUNT(*) FROM survey_responses sr WHERE sr.survey_id=s.id) AS responses_count
            FROM surveys s
            WHERE s.status=\'active\'
              AND (s.starts_at IS NULL OR s.starts_at <= NOW())
              AND (s.ends_at IS NULL OR s.ends_at >= NOW())
            ORDER BY s.created_at DESC, s.id DESC
            LIMIT ' . $limit);
    }

    public function allForAdmin(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        return $this->db->all('SELECT s.*, u.display_name AS admin_name,
            (SELECT COUNT(*) FROM survey_questions q WHERE q.survey_id=s.id) AS questions_count,
            (SELECT COUNT(*) FROM survey_responses r WHERE r.survey_id=s.id) AS responses_count,
            (SELECT c.id FROM campaigns c WHERE c.linked_survey_id=s.id AND c.type=\'survey_ad\'
             ORDER BY CASE c.status WHEN \'active\' THEN 0 WHEN \'draft\' THEN 1 ELSE 2 END,c.id DESC LIMIT 1) AS campaign_id,
            (SELECT c.name FROM campaigns c WHERE c.linked_survey_id=s.id AND c.type=\'survey_ad\'
             ORDER BY CASE c.status WHEN \'active\' THEN 0 WHEN \'draft\' THEN 1 ELSE 2 END,c.id DESC LIMIT 1) AS campaign_name,
            (SELECT c.status FROM campaigns c WHERE c.linked_survey_id=s.id AND c.type=\'survey_ad\'
             ORDER BY CASE c.status WHEN \'active\' THEN 0 WHEN \'draft\' THEN 1 ELSE 2 END,c.id DESC LIMIT 1) AS campaign_status
            FROM surveys s
            LEFT JOIN users u ON u.id=s.created_by_admin_id
            ORDER BY s.updated_at DESC, s.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT s.*, 
            (SELECT COUNT(*) FROM survey_responses sr WHERE sr.survey_id=s.id) AS responses_count
            FROM surveys s WHERE s.id=:id LIMIT 1', ['id' => $id]);
    }

    public function findActive(int $id): ?array
    {
        $survey = $this->find($id);
        if (!$survey || !$this->isOpen($survey)) {
            return null;
        }
        return $survey;
    }

    public function questions(int $surveyId): array
    {
        return $this->db->all('SELECT * FROM survey_questions WHERE survey_id=:id ORDER BY sort_order ASC, id ASC', ['id' => $surveyId]);
    }

    public function hasUserResponse(int $surveyId, int $userId): bool
    {
        return $this->db->one('SELECT id FROM survey_responses WHERE survey_id=:s AND user_id=:u LIMIT 1', [
            's' => $surveyId,
            'u' => $userId,
        ]) !== null;
    }

    public function createSurvey(int $adminId, array $data): int
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Podaj tytuł ankiety.');
        }

        $type = (string)($data['type'] ?? 'editorial');
        if (!in_array($type, $this->types(), true)) {
            $type = 'editorial';
        }

        $rewardMinor = $this->moneyToMinor((string)($data['reward_amount'] ?? '0'));
        $budgetMinor = $this->moneyToMinor((string)($data['budget'] ?? '0'));
        $maxResponses = max(0, (int)($data['max_responses'] ?? 0)) ?: null;
        $status = (string)($data['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'active', 'paused', 'closed'], true)) {
            $status = 'draft';
        }
        if ($status === 'active') {
            throw new \InvalidArgumentException('Najpierw utwórz ankietę i dodaj co najmniej jedno pytanie, a dopiero potem ją aktywuj.');
        }
        [$startsAt, $endsAt] = $this->validateSchedule($data['starts_at'] ?? null, $data['ends_at'] ?? null);
        $this->validateBudget($budgetMinor, $rewardMinor, $maxResponses);

        return $this->db->insert('INSERT INTO surveys(title, description, type, client_name, budget_minor, reward_amount_minor, max_responses, status, starts_at, ends_at, created_by_admin_id, created_at, updated_at)
            VALUES(:title,:description,:type,:client,:budget,:reward,:max,:status,:starts,:ends,:admin,NOW(),NOW())', [
            'title' => $title,
            'description' => trim((string)($data['description'] ?? '')),
            'type' => $type,
            'client' => trim((string)($data['client_name'] ?? '')) ?: null,
            'budget' => $budgetMinor,
            'reward' => $rewardMinor,
            'max' => $maxResponses,
            'status' => $status,
            'starts' => $startsAt,
            'ends' => $endsAt,
            'admin' => $adminId,
        ]);
    }

    public function updateSurvey(int $surveyId, array $data): void
    {
        $survey = $this->find($surveyId);
        if (!$survey) {
            throw new \RuntimeException('Nie znaleziono ankiety.');
        }

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Podaj tytuł ankiety.');
        }

        $type = (string)($data['type'] ?? 'editorial');
        if (!in_array($type, $this->types(), true)) {
            $type = 'editorial';
        }
        $status = (string)($data['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'active', 'paused', 'closed'], true)) {
            $status = 'draft';
        }
        if ($status === 'active' && (int)$this->db->cell('SELECT COUNT(*) FROM survey_questions WHERE survey_id=:id', ['id' => $surveyId]) === 0) {
            throw new \InvalidArgumentException('Nie można aktywować ankiety bez pytań.');
        }
        $budgetMinor = $this->moneyToMinor((string)($data['budget'] ?? '0'));
        $rewardMinor = $this->moneyToMinor((string)($data['reward_amount'] ?? '0'));
        $maxResponses = max(0, (int)($data['max_responses'] ?? 0)) ?: null;
        [$startsAt, $endsAt] = $this->validateSchedule($data['starts_at'] ?? null, $data['ends_at'] ?? null);
        $this->validateBudget($budgetMinor, $rewardMinor, $maxResponses);

        $this->db->query('UPDATE surveys SET title=:title, description=:description, type=:type, client_name=:client, budget_minor=:budget, reward_amount_minor=:reward, max_responses=:max, status=:status, starts_at=:starts, ends_at=:ends, updated_at=NOW() WHERE id=:id', [
            'id' => $surveyId,
            'title' => $title,
            'description' => trim((string)($data['description'] ?? '')),
            'type' => $type,
            'client' => trim((string)($data['client_name'] ?? '')) ?: null,
            'budget' => $budgetMinor,
            'reward' => $rewardMinor,
            'max' => $maxResponses,
            'status' => $status,
            'starts' => $startsAt,
            'ends' => $endsAt,
        ]);
    }

    public function addQuestion(int $surveyId, array $data): int
    {
        if (!$this->find($surveyId)) {
            throw new \RuntimeException('Nie znaleziono ankiety.');
        }
        $text = trim((string)($data['question_text'] ?? ''));
        if ($text === '') {
            throw new \InvalidArgumentException('Podaj treść pytania.');
        }
        $type = (string)($data['question_type'] ?? 'single_choice');
        if (!in_array($type, ['single_choice', 'multiple_choice', 'text'], true)) {
            $type = 'single_choice';
        }
        $options = $this->optionsToJson((string)($data['options'] ?? ''));
        if ($type !== 'text' && $options === '[]') {
            throw new \InvalidArgumentException('Dla pytania wyboru podaj opcje, każdą w osobnej linii.');
        }

        return $this->db->insert('INSERT INTO survey_questions(survey_id, question_text, question_type, options_json, sort_order, is_required, created_at, updated_at)
            VALUES(:survey,:text,:type,:options,:sort,:required,NOW(),NOW())', [
            'survey' => $surveyId,
            'text' => $text,
            'type' => $type,
            'options' => $options,
            'sort' => (int)($data['sort_order'] ?? 0),
            'required' => isset($data['is_required']) ? 1 : 0,
        ]);
    }

    public function deleteQuestion(int $questionId, int $surveyId): void
    {
        $question = $this->db->one(
            'SELECT q.id, s.status,
                    (SELECT COUNT(*) FROM survey_questions q2 WHERE q2.survey_id=q.survey_id) AS questions_count
             FROM survey_questions q
             JOIN surveys s ON s.id=q.survey_id
             WHERE q.id=:question AND q.survey_id=:survey
             LIMIT 1',
            ['question' => $questionId, 'survey' => $surveyId]
        );
        if (!$question) {
            throw new \RuntimeException('Nie znaleziono pytania w wybranej ankiecie.');
        }
        if ((string)$question['status'] === 'active' && (int)$question['questions_count'] <= 1) {
            throw new \RuntimeException('Nie można usunąć ostatniego pytania z aktywnej ankiety. Najpierw ją wstrzymaj.');
        }
        $this->db->query('DELETE FROM survey_questions WHERE id=:id AND survey_id=:survey', [
            'id' => $questionId,
            'survey' => $surveyId,
        ]);
    }

    public function submitResponse(int $surveyId, int $userId, array $answers): int
    {
        $survey = $this->findActive($surveyId);
        if (!$survey) {
            throw new \RuntimeException('Ankieta nie jest aktualnie aktywna.');
        }

        $questions = $this->questions($surveyId);
        if ($questions === []) {
            throw new \RuntimeException('Ankieta nie ma jeszcze pytań.');
        }

        $validated = [];
        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            $raw = $answers[$qid] ?? null;
            $required = (int)($q['is_required'] ?? 0) === 1;
            if ($required && ($raw === null || $raw === '' || $raw === [])) {
                throw new \InvalidArgumentException('Uzupełnij wymagane pytanie: ' . $q['question_text']);
            }
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }
            $validated[$qid] = $this->normalizeAnswer((string)$q['question_type'], $raw);
        }

        $answerSeconds = max(0, (int)($_POST['answer_seconds'] ?? 0));
        $guard = $this->fraudGuard?->inspectSurveySubmit($userId, $surveyId, $answerSeconds) ?? [
            'allowed' => true,
            'risk_score' => 0,
            'status' => 'normal',
            'reasons' => [],
        ];
        if (!((bool)$guard['allowed'])) {
            throw new \RuntimeException('SNAJPER SŁOWA wstrzymał nagrodę za ankietę do kontroli antyfraudowej.');
        }

        return $this->db->transaction(function(Database $db) use ($surveyId, $userId, $validated, $answerSeconds, $guard): int {
            // Blokada wiersza ankiety serializuje limit odpowiedzi i budżet.
            $lockedSurvey = $db->one('SELECT * FROM surveys WHERE id=:id FOR UPDATE', ['id' => $surveyId]);
            if (!$lockedSurvey || !$this->isOpen($lockedSurvey)) {
                throw new \RuntimeException('Ankieta nie jest aktualnie aktywna.');
            }
            if ($db->one('SELECT id FROM survey_responses WHERE survey_id=:survey AND user_id=:user LIMIT 1', [
                'survey' => $surveyId,
                'user' => $userId,
            ])) {
                throw new \RuntimeException('Już wypełniłeś tę ankietę.');
            }

            $responsesCount = (int)$db->cell('SELECT COUNT(*) FROM survey_responses WHERE survey_id=:id', ['id' => $surveyId]);
            if (!empty($lockedSurvey['max_responses']) && $responsesCount >= (int)$lockedSurvey['max_responses']) {
                throw new \RuntimeException('Ankieta osiągnęła limit odpowiedzi.');
            }

            $rewardMinor = max(0, (int)($lockedSurvey['reward_amount_minor'] ?? 0));
            $budgetMinor = max(0, (int)($lockedSurvey['budget_minor'] ?? 0));
            $spentMinor = (int)$db->cell(
                'SELECT COALESCE(SUM(reward_amount_minor),0)
                 FROM survey_responses
                 WHERE survey_id=:id AND reward_status IN (\'pending\',\'paid\')',
                ['id' => $surveyId]
            );
            if ($budgetMinor > 0 && ($spentMinor + $rewardMinor) > $budgetMinor) {
                throw new \RuntimeException('Budżet tej ankiety został wyczerpany.');
            }

            $responseId = $db->insert('INSERT INTO survey_responses(survey_id, user_id, reward_amount_minor, reward_status, completed_at, created_at, answer_seconds, ip_hash, user_agent_hash, fraud_risk_score, fraud_status)
                VALUES(:survey,:user,:reward,\'pending\',NOW(),NOW(),:seconds,:ip,:ua,:risk,:fraud_status)', [
                'survey' => $surveyId,
                'user' => $userId,
                'reward' => $rewardMinor,
                'seconds' => $answerSeconds,
                'ip' => $this->hashClientValue((string)($_SERVER['REMOTE_ADDR'] ?? '')),
                'ua' => $this->hashClientValue((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
                'risk' => (int)$guard['risk_score'],
                'fraud_status' => (string)$guard['status'],
            ]);
            foreach ($validated as $questionId => $answer) {
                $db->insert('INSERT INTO survey_response_items(response_id, question_id, answer_text, answer_json, created_at)
                    VALUES(:response,:question,:text,:json,NOW())', [
                    'response' => $responseId,
                    'question' => $questionId,
                    'text' => is_array($answer) ? implode(', ', $answer) : (string)$answer,
                    'json' => json_encode($answer, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $dispatcher = $this->earningsDispatcher ?? new EarningsJobDispatcher(
                $db,
                new DurableJobQueue($db),
                new \App\Infrastructure\Valkey\NullQueueSignal(),
                \App\Core\SlowoSnajperConfig::fromRoot(dirname(__DIR__, 2)),
            );
            $dispatcher->queueSurveyReward($responseId, $userId);

            return $responseId;
        });
    }

    public function responseReport(int $surveyId): array
    {
        $survey = $this->find($surveyId);
        if (!$survey) {
            throw new \RuntimeException('Nie znaleziono ankiety.');
        }
        $questions = $this->questions($surveyId);
        $responses = $this->db->all('SELECT r.*, u.display_name, u.email FROM survey_responses r JOIN users u ON u.id=r.user_id WHERE r.survey_id=:id ORDER BY r.completed_at DESC, r.id DESC LIMIT 200', ['id' => $surveyId]);
        $items = $this->db->all('SELECT i.*, q.question_text, q.question_type FROM survey_response_items i JOIN survey_questions q ON q.id=i.question_id JOIN survey_responses r ON r.id=i.response_id WHERE r.survey_id=:id ORDER BY i.question_id ASC, i.id ASC', ['id' => $surveyId]);

        $summary = [];
        foreach ($questions as $q) {
            $summary[(int)$q['id']] = [
                'question' => $q,
                'answers' => [],
            ];
        }
        foreach ($items as $item) {
            $qid = (int)$item['question_id'];
            $answerText = trim((string)$item['answer_text']);
            if ($answerText === '') {
                continue;
            }
            if (!isset($summary[$qid]['answers'][$answerText])) {
                $summary[$qid]['answers'][$answerText] = 0;
            }
            $summary[$qid]['answers'][$answerText]++;
        }

        return [
            'survey' => $survey,
            'questions' => $questions,
            'responses' => $responses,
            'summary' => $summary,
        ];
    }

    public function types(): array
    {
        return ['consumer','political_poll','social_poll','local_poll','advertising','editorial','market_research'];
    }

    private function isOpen(array $survey): bool
    {
        if (($survey['status'] ?? '') !== 'active') {
            return false;
        }
        $now = time();
        if (!empty($survey['starts_at']) && strtotime((string)$survey['starts_at']) > $now) {
            return false;
        }
        if (!empty($survey['ends_at']) && strtotime((string)$survey['ends_at']) < $now) {
            return false;
        }
        return true;
    }

    private function moneyToMinor(string $value): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if ($normalized === '') {
            return 0;
        }
        if (!is_numeric($normalized)) {
            throw new \InvalidArgumentException('Kwoty ankiety muszą być liczbami.');
        }
        return max(0, (int)round(((float)$normalized) * 100));
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \InvalidArgumentException('Podano nieprawidłową datę ankiety.');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function validateSchedule(mixed $startsAt, mixed $endsAt): array
    {
        $starts = $this->normalizeDateTime($startsAt);
        $ends = $this->normalizeDateTime($endsAt);
        if ($starts !== null && $ends !== null && strtotime($ends) <= strtotime($starts)) {
            throw new \InvalidArgumentException('Data zakończenia ankiety musi być późniejsza niż data rozpoczęcia.');
        }
        return [$starts, $ends];
    }

    private function validateBudget(int $budgetMinor, int $rewardMinor, ?int $maxResponses): void
    {
        if ($budgetMinor > 0 && $rewardMinor > $budgetMinor) {
            throw new \InvalidArgumentException('Nagroda za jedną odpowiedź nie może przekraczać całego budżetu ankiety.');
        }
        if ($budgetMinor > 0 && $rewardMinor > 0 && $maxResponses !== null && ($rewardMinor * $maxResponses) > $budgetMinor) {
            throw new \InvalidArgumentException('Budżet ankiety jest zbyt mały dla podanej nagrody i limitu odpowiedzi.');
        }
    }

    private function optionsToJson(string $options): string
    {
        $lines = preg_split('/\R/u', $options) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }
        return json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_UNICODE);
    }

    private function hashClientValue(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : hash('sha256', 'zrodlo-slowa-fraud:' . $value);
    }

    private function normalizeAnswer(string $type, mixed $raw): string|array
    {
        if ($type === 'multiple_choice') {
            $values = is_array($raw) ? $raw : [$raw];
            $clean = [];
            foreach ($values as $value) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $clean[] = $value;
                }
            }
            return $clean;
        }
        return mb_substr(trim((string)$raw), 0, 2000, 'UTF-8');
    }
}
