<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Dors3MobileException;
use App\Services\Dors3MobileOperationExecutor;
use App\Services\Dors3MobileService;
use App\Services\SecretCipher;

final class Dors3MobileApiController extends BaseController
{
    public function completeEnrollment(): string
    {
        return $this->run(fn(): array => $this->service()->completeEnrollment($this->jsonInput()));
    }

    public function confirmEnrollment(): string
    {
        return $this->run(function (): array {
            $input = $this->jsonInput();
            [$credentialPublicId, $apiToken] = $this->deviceAuth();
            $this->service()->confirmEnrollment(
                trim((string)($input['device_public_id'] ?? '')),
                $credentialPublicId,
                $apiToken,
                trim((string)($input['comparison_code'] ?? '')),
                filter_var($input['confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
            http_response_code(204);
            return [];
        });
    }

    public function pendingRequest(): string
    {
        return $this->run(function (): ?array {
            [$credentialPublicId, $apiToken] = $this->deviceAuth();
            $request = $this->service()->pendingRequest(
                $this->route('device_public_id'),
                $credentialPublicId,
                $apiToken,
            );
            if ($request === null) {
                http_response_code(204);
            }
            return $request;
        });
    }

    public function requestDetails(): string
    {
        return $this->run(function (): array {
            [$credentialPublicId, $apiToken] = $this->deviceAuth();
            return $this->service()->requestDetails($this->route('public_id'), $credentialPublicId, $apiToken);
        });
    }

    public function approve(): string
    {
        return $this->decision('approve');
    }

    public function reject(): string
    {
        return $this->decision('reject');
    }

    public function deviceStatus(): string
    {
        return $this->run(function (): array {
            [$credentialPublicId, $apiToken] = $this->deviceAuth();
            return $this->service()->deviceStatus(
                $this->route('device_public_id'),
                $credentialPublicId,
                $apiToken,
            );
        });
    }

    public function heartbeat(): string
    {
        return $this->run(function (): array {
            $input = $this->jsonInput();
            [$credentialPublicId, $apiToken] = $this->deviceAuth();
            $this->service()->heartbeat(
                $this->route('device_public_id'),
                $credentialPublicId,
                $apiToken,
                trim((string)($input['application_variant'] ?? '')),
            );
            return ['status' => 'ok'];
        });
    }

    public function startAuth(): string
    {
        return $this->run(function (): array {
            $userId = $this->requireAuth();
            $input = $this->jsonInput();
            $variant = trim((string)($input['application_variant'] ?? ($this->app->session->role() === 'admin' ? 'admin' : 'author')));
            return $this->service()->createApprovalRequest(
                $userId,
                $variant,
                'login',
                'auth.login',
                'user',
                (string)$userId,
                [
                    'Operacja' => 'Logowanie do Źródła Słowa',
                    'Konto' => (string)$userId,
                    'Urządzenie inicjujące' => mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Przeglądarka')), 0, 120),
                ],
            );
        });
    }

    public function authStatus(): string
    {
        return $this->run(function (): array {
            $this->requireAuth();
            return $this->service()->approvalStatus($this->route('public_id'));
        });
    }

    private function decision(string $decision): string
    {
        return $this->run(fn(): array => $this->service()->decide(
            $this->route('public_id'),
            $decision,
            $this->jsonInput(),
            (new Dors3MobileOperationExecutor())->execute(...),
        ));
    }

    /** @param callable(): (array<string, mixed>|null) $callback */
    private function run(callable $callback): string
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        try {
            $result = $callback();
            return $result === null || http_response_code() === 204
                ? ''
                : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Dors3MobileException $error) {
            http_response_code($error->httpStatus);
            return json_encode([
                'error' => $error->errorCode,
                'message' => $error->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            http_response_code(500);
            error_log('3DORS Mobile API failure [' . \App\Core\RequestContext::requestId() . ']: ' . $error->getMessage());
            return json_encode([
                'error' => 'internal_error',
                'message' => 'Operacja 3DORS Mobile nie powiodła się.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }

    /** @return array<string,mixed> */
    private function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > 65536) {
            throw new Dors3MobileException('invalid_json', 'Nieprawidłowy rozmiar żądania.');
        }
        $decoded = json_decode($raw === '' ? '{}' : $raw, true);
        if (!is_array($decoded)) {
            throw new Dors3MobileException('invalid_json', 'Body musi być obiektem JSON.');
        }
        return $decoded;
    }

    private function route(string $name): string
    {
        $value = trim((string)($_GET[$name] ?? ''));
        if ($value === '' || strlen($value) > 160) {
            throw new Dors3MobileException('invalid_identifier', 'Nieprawidłowy identyfikator ścieżki.');
        }
        return $value;
    }

    /** @return array{0:string,1:string} */
    private function deviceAuth(): array
    {
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Dors3Device\s+([a-zA-Z0-9-]{16,80})\.([a-zA-Z0-9_-]{32,256})$/D', $header, $matches) !== 1) {
            throw new Dors3MobileException(
                'device_auth_required',
                'Wymagany jest nagłówek uwierzytelniający urządzenie.',
                401,
            );
        }
        return [$matches[1], $matches[2]];
    }

    private function service(): Dors3MobileService
    {
        return new Dors3MobileService(
            $this->app->db,
            SecretCipher::fromEnvironment(),
            $this->securityEvents(),
            is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
        );
    }
}
