<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ArticleService;
use App\Services\Dors3MobileService;
use App\Services\Dors3OperationFingerprintService;
use App\Services\ResponsePublicationService;
use App\Services\ResponseSubmissionDepositException;
use App\Services\SecretCipher;
use App\Services\UploadService;
use App\Services\UserService;

final class ResponsePublicationController extends BaseController
{
    public function dashboard(): string
    {
        $userId = $this->requireAuth();
        $service = new ResponsePublicationService($this->app->db);
        return $this->view('responses/dashboard', [
            'title' => t('response.dashboard.page_title', public_language()),
            'eligibility' => $service->eligibility($userId),
            'responses' => $service->forUser($userId),
            'submission_deposit_points' => $service->submissionDepositPoints(),
            'flash_success' => $this->app->session->pullFlash('success'),
            'flash_error' => $this->app->session->pullFlash('error'),
        ]);
    }

    public function create(): string
    {
        $userId = $this->requireAuth();
        $responseService = new ResponsePublicationService($this->app->db);
        $responseService->assertEligible($userId);
        $articleId = (int)($_GET['article_id'] ?? 0);
        $articles = new ArticleService($this->app->db);
        $source = $articles->findPublished($articleId);
        if ($source === null) {
            http_response_code(404);
            return $this->view('layouts/error', ['title' => '404', 'message' => t('response.error.source_not_found', public_language())]);
        }
        if (!$articles->hasAccess($userId, $articleId)) {
            $this->app->session->flash('error', t('response.error.source_access_required', public_language()));
            redirect(public_language_url(public_language(), '/article?id=' . $articleId) . '#dostep-do-tekstu');
        }
        return $this->view('responses/form', [
            'title' => t('response.form.page_title', public_language()),
            'source' => $source,
            'response' => null,
            'media' => [],
            'submission_deposit_points' => $responseService->submissionDepositPoints(),
        ]);
    }

    public function store(): never
    {
        $userId = $this->requireAuth();
        $sourceArticleId = (int)($_POST['source_article_id'] ?? 0);
        if (!(new ArticleService($this->app->db))->hasAccess($userId, $sourceArticleId)) {
            $this->app->session->flash('error', t('response.error.source_access_required', public_language()));
            redirect(public_language_url(public_language(), '/article?id=' . $sourceArticleId) . '#dostep-do-tekstu');
        }
        try {
            $service = new ResponsePublicationService($this->app->db);
            $articleId = $service->createDraft($userId, $sourceArticleId, $this->articleInput());
            $this->upload($articleId, $userId);
            if ((string)($_POST['intent'] ?? 'draft') === 'submit') {
                $approvalRequired = $this->submitAccordingToRole($service, $userId, $articleId);
                $message = t($approvalRequired ? 'response.flash.approval_required' : 'response.flash.created_submitted', public_language());
            } else {
                $message = t('response.flash.created_draft', public_language());
            }
            $this->app->session->flash('success', $message);
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->responseError($error, t('response.error.create', public_language()), 'response_create'));
        }
        redirect(public_language_url(public_language(), '/opinie'));
    }

    public function edit(): string
    {
        $userId = $this->requireAuth();
        $service = new ResponsePublicationService($this->app->db);
        $service->assertEligible($userId);
        $response = $service->findOwned((int)($_GET['id'] ?? 0), $userId);
        if ($response === null) {
            http_response_code(404);
            return $this->view('layouts/error', ['title' => '404', 'message' => t('response.error.not_found', public_language())]);
        }
        $source = (new ArticleService($this->app->db))->findPublished((int)$response['response_to_article_id']);
        return $this->view('responses/form', [
            'title' => t('response.form.edit_page_title', public_language()),
            'source' => $source,
            'response' => $response,
            'media' => (new ArticleService($this->app->db))->getMedia((int)$response['id']),
            'submission_deposit_points' => $service->submissionDepositPoints(),
        ]);
    }

    public function update(): never
    {
        $userId = $this->requireAuth();
        $articleId = (int)($_POST['id'] ?? 0);
        try {
            $service = new ResponsePublicationService($this->app->db);
            $articleId = $service->updateDraft($userId, $articleId, $this->articleInput());
            $this->upload($articleId, $userId);
            if ((string)($_POST['intent'] ?? 'draft') === 'submit') {
                $approvalRequired = $this->submitAccordingToRole($service, $userId, $articleId);
                $message = t($approvalRequired ? 'response.flash.approval_required' : 'response.flash.updated_submitted', public_language());
            } else {
                $message = t('response.flash.updated_draft', public_language());
            }
            $this->app->session->flash('success', $message);
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->responseError($error, t('response.error.update', public_language()), 'response_update'));
        }
        redirect(public_language_url(public_language(), '/opinie'));
    }

    public function submit(): never
    {
        $userId = $this->requireAuth();
        try {
            $service = new ResponsePublicationService($this->app->db);
            $approvalRequired = $this->submitAccordingToRole($service, $userId, (int)($_POST['id'] ?? 0));
            $this->app->session->flash(
                'success',
                t($approvalRequired ? 'response.flash.approval_required' : 'response.flash.submitted', public_language())
            );
        } catch (\Throwable $error) {
            $this->app->session->flash('error', $this->responseError($error, t('response.error.submit', public_language()), 'response_submit'));
        }
        redirect(public_language_url(public_language(), '/opinie'));
    }

    /** @return array{title:string,lead:string,body:string,source_language:string} */
    private function articleInput(): array
    {
        return [
            'title' => trim((string)($_POST['title'] ?? '')),
            'lead' => trim((string)($_POST['lead'] ?? '')),
            'body' => trim((string)($_POST['body'] ?? '')),
            'source_language' => trim((string)($_POST['source_language'] ?? 'pl')),
        ];
    }

    private function upload(int $articleId, int $userId): void
    {
        if (!isset($_FILES['image']) || (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }
        (new UploadService($this->app->db, $this->app->objectStorage))->uploadArticleImage(
            $_FILES['image'],
            $userId,
            $articleId,
            max(0, min(100, (int)($_POST['image_position'] ?? 50))),
            trim((string)($_POST['title'] ?? 'opinia-polemika')),
            true,
        );
    }

    /**
     * Commentators use the deliberately narrow direct response flow. Approved
     * authors retain the existing article.submit protection in 3DORS Author.
     */
    private function submitAccordingToRole(ResponsePublicationService $service, int $userId, int $articleId): bool
    {
        $authorApprovalEnabled = $this->mobileApprovalEnabled('article_submit_approval');
        if ($service->submissionMode($userId, $authorApprovalEnabled) !== 'dors3_author') {
            $service->submit($userId, $articleId);
            return false;
        }

        if ($service->findOwned($articleId, $userId) === null) {
            throw new \RuntimeException('Nie znaleziono opinii lub polemiki.');
        }
        $block = (new UserService($this->app->db))->authorSubmitBlockInfo($userId);
        if (!empty($block['is_blocked'])) {
            throw new \RuntimeException('Redakcja czasowo zablokowała wysyłanie publikacji z tego konta.');
        }

        $issuedAt = time();
        $fingerprint = (new Dors3OperationFingerprintService($this->app->db))
            ->articleSubmit($articleId, $userId, $issuedAt);
        $this->dors3Mobile()->createOperationApprovalRequest(
            $userId,
            'article.submit',
            'article',
            (string)$articleId,
            $fingerprint['display_fields'],
            $fingerprint['fingerprint'],
            ['article_id' => $articleId, 'author_id' => $userId],
            $issuedAt,
        );
        return true;
    }

    private function mobileApprovalEnabled(string $flag): bool
    {
        $mobile = $this->app->config['dors3']['mobile'] ?? null;
        return is_array($mobile)
            && \App\Security\Dors3\MobileApprovalConfiguration::isEnabled($mobile, 'author', $flag);
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

    private function responseError(\Throwable $error, string $fallback, string $context): string
    {
        if ($error instanceof ResponseSubmissionDepositException) {
            return str_replace(
                '{points}',
                (string)$error->requiredPoints,
                t('response.error.deposit_insufficient', public_language())
            );
        }
        return $this->safeError($error, $fallback, $context);
    }
}
