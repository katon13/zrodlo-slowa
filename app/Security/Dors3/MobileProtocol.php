<?php
declare(strict_types=1);

namespace App\Security\Dors3;

final class MobileProtocol
{
    public const PROTOCOL_VERSION = 1;
    public const PAYLOAD_VERSION = 1;
    public const ALGORITHM = 'SHA256withECDSA';

    /** @param array<string,mixed> $request */
    public static function canonicalPayload(array $request, string $decision, string $credentialPublicId): string
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new \InvalidArgumentException('Nieobsługiwana decyzja mobilna.');
        }

        return implode("\n", [
            'payload_version=' . self::PAYLOAD_VERSION,
            'decision=' . $decision,
            'purpose=' . (string)$request['purpose'],
            (string)$request['request_id'],
            (string)$request['challenge'],
            (string)$request['account'],
            (string)$request['organization_id'],
            (string)$request['role_context'],
            (string)$request['server_origin'],
            (string)$request['environment'],
            (string)($request['browser_session_hash'] ?? ''),
            (string)($request['action_fingerprint'] ?? ''),
            (string)$request['issued_at_epoch'],
            (string)$request['expires_at_epoch'],
            (string)$request['nonce'],
            $credentialPublicId,
        ]);
    }

    public static function base64Url(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
