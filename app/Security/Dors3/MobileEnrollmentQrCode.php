<?php
declare(strict_types=1);

namespace App\Security\Dors3;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;

final class MobileEnrollmentQrCode
{
    /** @param array<string, mixed> $payload */
    public static function dataUri(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $qrCode = new QrCode(
            data: $json,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 360,
            margin: 12,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return (new SvgWriter())->write($qrCode)->getDataUri();
    }
}
