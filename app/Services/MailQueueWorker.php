<?php
namespace App\Services;

use App\Contracts\MailSenderInterface;

final class MailQueueWorker
{
    public function __construct(
        private readonly MailService $queue,
        private readonly MailSenderInterface $transport,
        private readonly string $workerId,
    ) {}

    public function runBatch(int $limit = 20): array
    {
        $messages = $this->queue->claimBatch($this->workerId, $limit);
        $result = ['claimed' => count($messages), 'sent' => 0, 'retry' => 0, 'dead_letter' => 0];
        foreach ($messages as $message) {
            try {
                $messageId = $this->transport->send(
                    (string)$message['email'],
                    (string)$message['subject'],
                    (string)$message['body']
                );
                $this->queue->markSent((int)$message['id'], $this->workerId, $messageId);
                $result['sent']++;
            } catch (\Throwable $error) {
                $status = $this->queue->markFailed((int)$message['id'], $this->workerId, $error);
                $result[$status]++;
            }
        }
        return $result;
    }
}
