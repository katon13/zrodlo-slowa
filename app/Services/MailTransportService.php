<?php
namespace App\Services;

use App\Contracts\MailSenderInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class MailTransportService implements MailSenderInterface
{
    private readonly Mailer $mailer;

    public function __construct(
        string $dsn,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
        if (trim($dsn) === '') {
            throw new \RuntimeException('Brak konfiguracji transportu poczty.');
        }
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('MAIL_FROM_ADDRESS nie jest poprawnym adresem e-mail.');
        }
        if (preg_match('/[\r\n]/', $fromName) === 1) {
            throw new \RuntimeException('MAIL_FROM_NAME zawiera niedozwolone znaki.');
        }
        $this->mailer = new Mailer(Transport::fromDsn($dsn));
    }

    public static function fromEnvironment(): self
    {
        $dsn = trim((string)env('MAILER_DSN', ''));
        if ($dsn === '') {
            $dsn = self::legacySmtpDsn();
        }
        return new self(
            $dsn,
            trim((string)env('MAIL_FROM_ADDRESS', '')),
            trim((string)env('MAIL_FROM_NAME', (string)env('APP_NAME', 'ŹRÓDŁO SŁOWA')))
        );
    }

    public function send(string $to, string $subject, string $body): ?string
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Nieprawidłowy adres odbiorcy wiadomości.');
        }
        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($to)
            ->subject(str_replace(["\r", "\n"], ' ', $subject))
            ->text($body);
        $this->mailer->send($email);

        $header = $email->getHeaders()->get('Message-ID');
        return $header !== null ? trim($header->getBodyAsString(), '<>') : null;
    }

    private static function legacySmtpDsn(): string
    {
        $transport = strtolower(trim((string)env('MAIL_TRANSPORT', 'smtp')));
        if ($transport === 'null' && strtolower((string)env('APP_ENV', 'production')) !== 'production') {
            return 'null://null';
        }
        if ($transport !== 'smtp') {
            return '';
        }
        $host = trim((string)env('MAIL_SMTP_HOST', ''));
        if ($host === '' || preg_match('/[^A-Za-z0-9._:-]/', $host) === 1) {
            return '';
        }
        $port = (int)env('MAIL_SMTP_PORT', 587);
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('MAIL_SMTP_PORT jest poza dozwolonym zakresem.');
        }
        $username = rawurlencode((string)env('MAIL_SMTP_USERNAME', ''));
        $password = rawurlencode((string)env('MAIL_SMTP_PASSWORD', ''));
        $credentials = $username !== '' ? $username . ':' . $password . '@' : '';
        $encryption = strtolower(trim((string)env('MAIL_SMTP_ENCRYPTION', 'tls')));
        $scheme = in_array($encryption, ['ssl', 'smtps'], true) ? 'smtps' : 'smtp';
        $query = in_array($encryption, ['tls', 'starttls'], true) ? '?require_tls=true' : '';
        return "{$scheme}://{$credentials}{$host}:{$port}{$query}";
    }
}
