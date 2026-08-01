<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\BackgroundJobHandlerInterface;
use App\Jobs\JobRejectedException;
use App\Jobs\NonRetryableJobException;

final class DurableJobWorker
{
    public function __construct(
        private readonly DurableJobQueue $queue,
        private readonly BackgroundJobHandlerInterface $handler,
        private readonly string $queueName,
        private readonly string $workerId,
        private readonly int $leaseSeconds = 300,
    ) {}

    /** @return array{claimed:int,completed:int,retry:int,dead_letter:int,rejected:int} */
    public function runOne(): array
    {
        $result = ['claimed' => 0, 'completed' => 0, 'retry' => 0, 'dead_letter' => 0, 'rejected' => 0];
        $job = $this->queue->claimOne($this->queueName, $this->workerId, $this->leaseSeconds);
        if ($job === null) {
            return $result;
        }
        $result['claimed'] = 1;
        try {
            if (!$this->handler->supports((string)$job['job_type'])) {
                throw new NonRetryableJobException('Worker nie obsługuje tego typu zadania.');
            }
            $output = $this->handler->handle($job);
            $this->queue->complete((int)$job['id'], $this->workerId, $output);
            $result['completed'] = 1;
        } catch (JobRejectedException $error) {
            $this->queue->reject((int)$job['id'], $this->workerId, $error->getMessage());
            $result['rejected'] = 1;
        } catch (NonRetryableJobException $error) {
            $this->queue->deadLetter((int)$job['id'], $this->workerId, $error->getMessage());
            $result['dead_letter'] = 1;
        } catch (\Throwable $error) {
            $status = $this->queue->fail((int)$job['id'], $this->workerId, $error);
            $result[$status] = 1;
        }
        return $result;
    }
}
