<?php
declare(strict_types=1);

namespace App\Contracts;

interface MailSenderInterface
{
    public function send(string $to, string $subject, string $body): ?string;
}
