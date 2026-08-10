<?php
declare(strict_types=1);

namespace App\Security\Dors3;

final class MobileSignatureVerifier
{
    public function verify(string $publicKeyBase64, string $payload, string $signatureBase64, string $algorithm): bool
    {
        if (!hash_equals(MobileProtocol::ALGORITHM, $algorithm)) {
            return false;
        }

        $der = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($signatureBase64, true);
        if (!is_string($der) || $der === '' || !is_string($signature) || $signature === '') {
            return false;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            return false;
        }
        $curve = (string)($details['ec']['curve_name'] ?? '');
        if (!in_array($curve, ['prime256v1', 'secp256r1'], true)) {
            return false;
        }

        return openssl_verify($payload, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }
}
