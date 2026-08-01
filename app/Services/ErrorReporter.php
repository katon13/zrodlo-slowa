<?php
namespace App\Services;

final class ErrorReporter
{
    public function report(\Throwable $error, string $context = 'request'): string
    {
        $reference = strtoupper(bin2hex(random_bytes(6)));
        error_log(sprintf(
            '[%s] %s | %s: %s | %s',
            $reference,
            $context,
            $error::class,
            $error->getMessage(),
            $error->getTraceAsString()
        ));
        return $reference;
    }

    public function publicMessage(\Throwable $error, string $fallback, string $context = 'request'): string
    {
        $reference = $this->report($error, $context);
        if (strtolower((string)env('APP_ENV', 'production')) !== 'production' && env_bool('APP_DEBUG', false)) {
            return $error->getMessage() . " [{$reference}]";
        }
        return rtrim($fallback) . " Kod zgłoszenia: {$reference}.";
    }
}
