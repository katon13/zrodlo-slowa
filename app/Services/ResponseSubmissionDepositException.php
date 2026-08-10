<?php
declare(strict_types=1);

namespace App\Services;

final class ResponseSubmissionDepositException extends \RuntimeException
{
    public function __construct(public readonly int $requiredPoints)
    {
        parent::__construct('Aby wysłać polemikę, potrzebujesz co najmniej ' . $requiredPoints . ' TT na kaucję.');
    }
}
