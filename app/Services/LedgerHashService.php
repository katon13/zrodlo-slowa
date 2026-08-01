<?php
namespace App\Services;

final class LedgerHashService
{
    public const VERSION = 2;
    public const ALGORITHM = 'hmac-sha256';
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) < 32 || preg_match('/change|replace|example|placeholder|wygeneruj|twoj/i', $key)) {
            throw new \RuntimeException('FINANCE_HMAC_KEY must be a non-placeholder secret of at least 32 characters.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self((string)env('FINANCE_HMAC_KEY', ''));
    }

    public function sign(array $transaction, string $currency, string $balanceType, string $previousHash): string
    {
        return hash_hmac(
            'sha256',
            $this->canonicalJson($this->payload($transaction, $currency, $balanceType, $previousHash)),
            $this->key
        );
    }

    public function verify(array $transaction, string $currency, string $balanceType, string $previousHash): bool
    {
        return $this->verifyStored(
            $transaction,
            $currency,
            $balanceType,
            $previousHash,
            (string)($transaction['entry_hash'] ?? '')
        );
    }

    public function verifyStored(array $transaction, string $currency, string $balanceType, string $previousHash, string $storedHash): bool
    {
        return $storedHash !== ''
            && hash_equals($storedHash, $this->sign($transaction, $currency, $balanceType, $previousHash));
    }

    public function signCanonical(array $payload): string
    {
        return hash_hmac('sha256', $this->canonicalJson($payload), $this->key);
    }

    public function verifyCanonical(array $payload, string $storedHash): bool
    {
        return $storedHash !== '' && hash_equals($storedHash, $this->signCanonical($payload));
    }

    public function verifyLegacyV1(array $transaction, string $currency, string $balanceType, string $previousHash, string $storedHash): bool
    {
        $payload = [
            'transaction_id' => (int)$transaction['id'],
            'wallet_id' => (int)$transaction['wallet_id'],
            'user_id' => (int)$transaction['user_id'],
            'type' => (string)$transaction['type'],
            'amount' => (int)$transaction['amount_minor'],
            'currency' => strtoupper($currency),
            'balance_before' => (int)$transaction['balance_before_minor'],
            'balance_after' => (int)$transaction['balance_after_minor'],
            'balance_type' => $balanceType,
            'account_type' => (string)$transaction['account_type'],
            'source' => (string)$transaction['source_module'],
            'created_at' => (string)$transaction['created_at'],
            'previous_hash' => $previousHash,
        ];
        ksort($payload);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        return is_string($json)
            && $storedHash !== ''
            && hash_equals($storedHash, hash_hmac('sha256', $json, $this->key));
    }

    public function payload(array $transaction, string $currency, string $balanceType, string $previousHash): array
    {
        return [
            'hash_version' => self::VERSION,
            'transaction_id' => (int)$transaction['id'],
            'wallet_id' => (int)$transaction['wallet_id'],
            'user_id' => (int)$transaction['user_id'],
            'source_module' => (string)$transaction['source_module'],
            'type' => (string)$transaction['type'],
            'account_type' => (string)$transaction['account_type'],
            'status' => (string)$transaction['status'],
            'amount_minor' => (int)$transaction['amount_minor'],
            'currency' => strtoupper($currency),
            'balance_before_minor' => (int)$transaction['balance_before_minor'],
            'balance_after_minor' => (int)$transaction['balance_after_minor'],
            'balance_type' => $balanceType,
            'description' => $transaction['description'] !== null ? (string)$transaction['description'] : null,
            'title_key' => $transaction['title_key'] !== null ? (string)$transaction['title_key'] : null,
            'message_key' => $transaction['message_key'] !== null ? (string)$transaction['message_key'] : null,
            'description_key' => $transaction['description_key'] !== null ? (string)$transaction['description_key'] : null,
            'counterparty_user_id' => isset($transaction['counterparty_user_id']) ? (int)$transaction['counterparty_user_id'] : null,
            'ref_type' => $transaction['ref_type'] !== null ? (string)$transaction['ref_type'] : null,
            'ref_id' => isset($transaction['ref_id']) ? (int)$transaction['ref_id'] : null,
            'idempotency_key' => $transaction['idempotency_key'] !== null ? (string)$transaction['idempotency_key'] : null,
            'meta' => $this->decodeMeta($transaction['meta_json'] ?? null),
            'created_at' => (string)$transaction['created_at'],
            'previous_hash' => $previousHash,
        ];
    }

    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (!is_string($meta) || trim($meta) === '') {
            return [];
        }
        $decoded = json_decode($meta, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Ledger transaction contains invalid meta_json.');
        }
        return $decoded;
    }

    private function canonicalJson(array $value): string
    {
        $normalized = $this->normalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode canonical ledger payload.');
        }
        return $json;
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }
        return $value;
    }
}
