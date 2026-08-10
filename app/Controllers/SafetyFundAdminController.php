<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Security\Dors3\SecurityId;
use App\Services\Dors3MobileService;
use App\Services\Dors3OperationFingerprintService;
use App\Services\SafetyFundService;
use App\Services\SecretCipher;

final class SafetyFundAdminController extends BaseController
{
    public function index(): string
    {
        $this->requireAdmin();
        return $this->view('admin/safety_fund', [
            'title' => $this->message('safety_fund.admin.page_title'),
            'fund' => (new SafetyFundService($this->app->db))->dashboard(),
            'categories' => SafetyFundService::CATEGORIES,
        ]);
    }

    public function requestPolicyChange(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $author = $this->basisPoints((string)($_POST['author_percent'] ?? ''));
            $platform = $this->basisPoints((string)($_POST['platform_percent'] ?? ''));
            $safetyFund = $this->basisPoints((string)($_POST['safety_fund_percent'] ?? ''));
            $service = new SafetyFundService($this->app->db);
            $service->validatePolicy($author, $platform, $safetyFund);
            $current = $service->currentPolicy();
            if (
                (int)$current['author_basis_points'] === $author
                && (int)$current['platform_basis_points'] === $platform
                && (int)$current['safety_fund_basis_points'] === $safetyFund
            ) {
                throw new \RuntimeException('safety_fund.error.policy_unchanged');
            }

            $issuedAt = time();
            $fingerprint = (new Dors3OperationFingerprintService($this->app->db))->revenueSplitPolicy(
                $adminId,
                $author,
                $platform,
                $safetyFund,
                $issuedAt,
            );
            $request = $this->dors3Mobile()->createOperationApprovalRequest(
                $adminId,
                'financial_settings.change',
                'revenue_split_policy',
                'active',
                $fingerprint['display_fields'],
                $fingerprint['fingerprint'],
                [
                    'admin_id' => $adminId,
                    'author_basis_points' => $author,
                    'platform_basis_points' => $platform,
                    'safety_fund_basis_points' => $safetyFund,
                ],
                $issuedAt,
            );
            $this->app->session->flash('success', $this->message(
                'safety_fund.admin.policy_waiting',
                ['id' => (string)$request['public_id']],
            ));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->errorMessage($error));
        }
        redirect('/admin/safety-fund#policy');
    }

    public function requestDisbursement(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $amountMinor = $this->amountMinor((string)($_POST['amount'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $reference = trim((string)($_POST['evidence_reference'] ?? ''));
            $service = new SafetyFundService($this->app->db);
            if ($service->balanceMinor() < $amountMinor) {
                throw new \RuntimeException('safety_fund.error.insufficient_balance');
            }
            if (!in_array($category, SafetyFundService::CATEGORIES, true)) {
                throw new \InvalidArgumentException('safety_fund.error.invalid_category');
            }
            if ($description === '' || mb_strlen($description) > 500) {
                throw new \InvalidArgumentException('safety_fund.error.description_required');
            }
            if ($reference === '' || mb_strlen($reference) > 255) {
                throw new \InvalidArgumentException('safety_fund.error.reference_required');
            }

            $publicId = SecurityId::uuid();
            $issuedAt = time();
            $fingerprint = (new Dors3OperationFingerprintService($this->app->db))->safetyFundDisbursement(
                $adminId,
                $publicId,
                $amountMinor,
                $category,
                $description,
                $reference,
                $issuedAt,
            );
            $request = $this->dors3Mobile()->createOperationApprovalRequest(
                $adminId,
                'safety_fund.disbursement',
                'safety_fund_disbursement',
                $publicId,
                $fingerprint['display_fields'],
                $fingerprint['fingerprint'],
                [
                    'admin_id' => $adminId,
                    'public_id' => $publicId,
                    'amount_minor' => $amountMinor,
                    'category' => $category,
                    'description' => $description,
                    'evidence_reference' => $reference,
                ],
                $issuedAt,
            );
            $this->app->session->flash('success', $this->message(
                'safety_fund.admin.disbursement_waiting',
                ['id' => (string)$request['public_id']],
            ));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->errorMessage($error));
        }
        redirect('/admin/safety-fund#disbursement');
    }

    private function dors3Mobile(): Dors3MobileService
    {
        return new Dors3MobileService(
            $this->app->db,
            SecretCipher::fromEnvironment(),
            $this->securityEvents(),
            is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
        );
    }

    private function basisPoints(string $percent): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($percent));
        if ($normalized === '' || preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/D', $normalized) !== 1) {
            throw new \InvalidArgumentException('safety_fund.error.invalid_split_value');
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        return ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
    }

    private function amountMinor(string $amount): int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($amount));
        if ($normalized === '' || preg_match('/^\d+(?:\.\d{1,2})?$/D', $normalized) !== 1) {
            throw new \InvalidArgumentException('safety_fund.error.invalid_amount');
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        if (strlen($whole) > 12) {
            throw new \InvalidArgumentException('safety_fund.error.invalid_amount');
        }
        $minor = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
        if ($minor <= 0) {
            throw new \InvalidArgumentException('safety_fund.error.amount_positive');
        }
        return $minor;
    }

    /** @param array<string,string> $replace */
    private function message(string $key, array $replace = []): string
    {
        $message = function_exists('t') ? t($key, 'pl') : '';
        if ($message === '' || $message === $key) {
            return $key;
        }
        foreach ($replace as $name => $value) {
            $message = str_replace('{' . $name . '}', $value, $message);
        }
        return $message;
    }

    private function errorMessage(\Throwable $error): string
    {
        $key = trim($error->getMessage());
        if (str_starts_with($key, 'safety_fund.')) {
            return $this->message($key);
        }
        return $this->safeError(
            $error,
            $this->message('safety_fund.error.operation_failed'),
            'safety_fund',
        );
    }
}
