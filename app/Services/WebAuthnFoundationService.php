<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\RequestContext;
use App\Security\Dors3\SecurityId;

final class WebAuthnFoundationService
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Database $db,
        private readonly array $config,
    ) {}

    /** @return array{enabled:bool,library_ready:bool,authorization_ready:bool,attestation_ready:bool,rp_id:string,origin:string,user_verification:string} */
    public function status(): array
    {
        $webauthn = is_array($this->config['webauthn'] ?? null) ? $this->config['webauthn'] : [];
        return [
            'enabled' => (bool)($webauthn['enabled'] ?? false) && (bool)($this->config['fido2_enabled'] ?? false),
            'library_ready' => class_exists(\Webauthn\PublicKeyCredential::class),
            // Biblioteka i magazyn challenge są jedynie fundamentem. Nie udostępniamy
            // pełnej rejestracji/ceremonii ani walidacji atestacji authenticatora.
            'authorization_ready' => false,
            'attestation_ready' => false,
            'rp_id' => (string)($webauthn['rp_id'] ?? ''),
            'origin' => (string)($webauthn['origin'] ?? ''),
            'user_verification' => (string)($webauthn['user_verification'] ?? ''),
        ];
    }

    /** @return array{public_id:string,challenge:string,expires_at:int} */
    public function beginChallenge(int $userId, string $purpose, ?string $actionFingerprint = null): array
    {
        $status = $this->status();
        if (!$status['enabled'] || !$status['library_ready'] || (string)($this->config['mode'] ?? 'prepare') === 'prepare') {
            throw new \RuntimeException('WebAuthn jest przygotowane, ale pozostaje wyłączone do czasu zakupu klucza.');
        }
        if (!in_array($purpose, ['registration', 'authentication', 'step_up', 'test'], true)) {
            throw new \InvalidArgumentException('Nieobsługiwany cel challenge WebAuthn.');
        }
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $publicId = SecurityId::uuid();
        $ttl = max(60, min(900, (int)($this->config['webauthn']['challenge_ttl_seconds'] ?? 300)));
        $expiresAt = time() + $ttl;
        $requestId = RequestContext::requestId();
        $this->db->query(
            'INSERT INTO webauthn_challenges(
                public_id,user_id,purpose,challenge_hash,action_fingerprint,rp_id,origin,
                request_id,correlation_id,expires_at,created_at
             ) VALUES(
                :public_id,:user_id,:purpose,:challenge_hash,:fingerprint,:rp_id,:origin,
                :request_id,:correlation_id,:expires_at,NOW()
             )',
            [
                'public_id' => $publicId,
                'user_id' => $userId,
                'purpose' => $purpose,
                'challenge_hash' => hash('sha256', $challenge),
                'fingerprint' => $actionFingerprint,
                'rp_id' => $status['rp_id'],
                'origin' => $status['origin'],
                'request_id' => $requestId,
                'correlation_id' => $requestId,
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
            ]
        );
        return ['public_id' => $publicId, 'challenge' => $challenge, 'expires_at' => $expiresAt];
    }

    /** @return array<string, mixed> */
    public function consumeChallenge(string $publicId, int $userId, string $challenge, string $origin, string $rpId): array
    {
        $status = $this->status();
        if (!$status['enabled']) {
            throw new \RuntimeException('WebAuthn jest wyłączone.');
        }
        if (!hash_equals($status['origin'], rtrim($origin, '/')) || !hash_equals($status['rp_id'], $rpId)) {
            throw new \RuntimeException('Origin albo RP ID WebAuthn jest nieprawidłowe.');
        }

        return $this->db->transaction(function (Database $db) use ($publicId, $userId, $challenge, $status): array {
            $row = $db->one('SELECT * FROM webauthn_challenges WHERE public_id=:id FOR UPDATE', ['id' => $publicId]);
            if (
                $row === null
                || (int)$row['user_id'] !== $userId
                || !hash_equals((string)$row['challenge_hash'], hash('sha256', $challenge))
                || !hash_equals((string)$row['origin'], $status['origin'])
                || !hash_equals((string)$row['rp_id'], $status['rp_id'])
                || !empty($row['used_at'])
                || strtotime((string)$row['expires_at'] . ' UTC') < time()
            ) {
                throw new \RuntimeException('Challenge WebAuthn jest nieprawidłowy, wygasł albo został już użyty.');
            }
            $updated = $db->query(
                'UPDATE webauthn_challenges SET used_at=NOW() WHERE id=:id AND used_at IS NULL',
                ['id' => (int)$row['id']]
            )->rowCount();
            if ($updated !== 1) {
                throw new \RuntimeException('Wykryto replay challenge WebAuthn.');
            }
            return $row;
        });
    }
}
