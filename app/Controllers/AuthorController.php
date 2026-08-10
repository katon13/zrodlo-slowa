<?php
namespace App\Controllers;

use App\Services\Dors3UiText;

use App\Services\ArticleService;
use App\Services\WalletService;
use App\Services\UploadService;
use App\Services\UserService;

final class AuthorController extends BaseController
{
    private function requireApprovedAuthor(): int
    {
        $userId = $this->requireAuth();
        $user = (new UserService($this->app->db))->findUserStatus($userId) ?? [];
        $roles = array_filter(explode(',', (string)($user['roles'] ?? '')));

        if (($user['status'] ?? '') === 'pending_author' && in_array('author', $roles, true)) {
            $this->app->session->flash('error', 'Konto autora czeka na akceptację redakcji. Dodawanie i edycja tekstów będzie dostępne po zatwierdzeniu.');
            redirect('/author');
        }

        if (($user['status'] ?? '') !== 'active') {
            $this->app->session->flash('error', 'Konto nie jest aktywne.');
            redirect('/');
        }

        if ((int)($user['can_write'] ?? 0) !== 1) {
            $this->app->session->flash('error', 'Możliwość pisania nie jest jeszcze aktywna. Redakcja musi ręcznie nadać tę zgodę.');
            redirect('/author');
        }

        return $userId;
    }

    private function currentAuthorState(int $userId): array
    {
        $user = (new UserService($this->app->db))->findUserStatus($userId) ?? [];
        $roles = array_filter(explode(',', (string)($user['roles'] ?? '')));
        $blockedUntil = $user['article_submit_blocked_until'] ?? null;
        $isSubmitBlocked = $blockedUntil !== null && strtotime((string)$blockedUntil) > time();

        return [
            'status' => (string)($user['status'] ?? ''),
            'roles' => $roles,
            'is_pending_author' => (($user['status'] ?? '') === 'pending_author' && in_array('author', $roles, true)),
            'can_write' => (int)($user['can_write'] ?? 0) === 1,
            'talent_enabled' => (int)($user['talent_enabled'] ?? 0) === 1,
            'wallet_enabled' => (int)($user['wallet_enabled'] ?? 0) === 1,
            'payout_enabled' => (int)($user['payout_enabled'] ?? 0) === 1,
            'article_submit_blocked_until' => $blockedUntil,
            'article_submit_block_reason' => $user['article_submit_block_reason'] ?? null,
            'is_article_submit_blocked' => $isSubmitBlocked,
            'can_publish' => in_array('publisher', $roles, true) || in_array('chief_editor', $roles, true),
        ];
    }

    public function dashboard(): string
    {
        $userId = $this->requireAuth();
        $authorState = $this->currentAuthorState($userId);
        $articleService = new ArticleService($this->app->db);
        $walletService = new WalletService($this->app->db);
        return $this->view('author/dashboard', [
            'title' => 'Panel autora',
            'articles' => $articleService->forAuthor($userId, $this->slowoSnajperConfig()->limit('author_articles', 30, 100)),
            'wallet' => $walletService->optionalWalletForUser($userId),
            'author_state' => $authorState,
            'flash_success' => $this->app->session->pullFlash('success'),
            'flash_error' => $this->app->session->pullFlash('error'),
        ]);
    }


    public function authorsShortcut(): never
    {
        $lang = function_exists('public_language') ? \public_language() : 'pl';
        $target = $this->app->session->userId() ? '/author' : '/register';
        redirect(function_exists('public_language_url') ? \public_language_url($lang, $target) : $target);
    }

    public function createArticle(): string
    {
        $this->requireApprovedAuthor();
        return $this->view('author/create_article', ['title' => 'Nowy tekst']);
    }

    public function storeArticle(): never
    {
        $userId = $this->requireApprovedAuthor();
        try {
            $db = $this->app->db;
            $articleId = (new ArticleService($db))->createDraft($userId, [
                'title' => trim($_POST['title'] ?? ''),
                'lead' => trim($_POST['lead'] ?? ''),
                'body' => trim($_POST['body'] ?? ''),
                'source_language' => trim($_POST['source_language'] ?? 'pl'),
            ]);

            $uploadService = new UploadService($db, $this->app->objectStorage);
            $titleSeed = trim((string)($_POST['title'] ?? 'zdjecie-artykulu'));
            $position = (int)($_POST['image_position'] ?? 50);
            if (!empty($_POST['image_data'])) {
                $uploadService->uploadArticleImageDataUrl((string)$_POST['image_data'], $userId, $articleId, $titleSeed, $position, true);
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadService->uploadArticleImage($_FILES['image'], $userId, $articleId, $position, $titleSeed, true);
            }

            $this->app->session->flash('success', 'Szkic zapisany.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się zapisać szkicu.', 'article_create'));
        }
        redirect('/author');
    }

    public function editArticle(): string
    {
        $userId = $this->requireApprovedAuthor();
        $articleService = new ArticleService($this->app->db);
        $article = $articleService->findForAuthor((int)($_GET['id'] ?? 0), $userId);
        if (!$article) { http_response_code(404); return $this->view('layouts/error', ['title'=>'404','message'=>'Nie znaleziono tekstu.']); }
        return $this->view('author/edit_article', [
            'title'=>'Edycja tekstu',
            'article'=>$article,
            'media' => $articleService->getMedia((int)$article['id'], $this->slowoSnajperConfig()->limit('article_media', 12, 50))
        ]);
    }

    public function updateArticle(): never
    {
        $userId = $this->requireApprovedAuthor();
        try {
            $db = $this->app->db;
            $articleId = (int)$_POST['id'];
            $articleId = (new ArticleService($db))->updateDraft($articleId, $userId, [
                'title'=>trim($_POST['title'] ?? ''),
                'lead'=>trim($_POST['lead'] ?? ''),
                'body'=>trim($_POST['body'] ?? ''),
                'source_language' => trim($_POST['source_language'] ?? 'pl'),
            ]);

            $uploadService = new UploadService($db, $this->app->objectStorage);
            $titleSeed = trim((string)($_POST['title'] ?? 'zdjecie-artykulu'));
            $position = (int)($_POST['image_position'] ?? 50);
            if (!empty($_POST['image_data'])) {
                $uploadService->uploadArticleImageDataUrl((string)$_POST['image_data'], $userId, $articleId, $titleSeed, $position, true);
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadService->uploadArticleImage($_FILES['image'], $userId, $articleId, $position, $titleSeed, true);
            }

            $msg = 'Tekst zaktualizowany.';
            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg, 'article_id' => $articleId]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) { 
            if ($this->isAjax()) {
                $this->json(['success' => false, 'message' => $this->safeError($e, 'Nie udało się zapisać tekstu.', 'article_update')]);
            }
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się zapisać tekstu.', 'article_update'));
        }
        redirect('/author');
    }

    public function submitArticle(): never
    {
        $userId = $this->requireApprovedAuthor();
        try {
            $this->enforceArticleSubmitRateLimit($userId);
            $block = (new UserService($this->app->db))->authorSubmitBlockInfo($userId);
            if (!empty($block['is_blocked'])) {
                $until = $block['blocked_until'] ? (' do: ' . $block['blocked_until']) : '';
                throw new \RuntimeException('Redakcja czasowo zablokowała możliwość wysyłania tekstów' . $until . '.');
            }

            $articleId = (int)$_POST['id'];
            if ($this->mobileApprovalEnabled('article_submit_approval')) {
                $issuedAt = time();
                $fingerprint = (new \App\Services\Dors3OperationFingerprintService($this->app->db))
                    ->articleSubmit($articleId, $userId, $issuedAt);
                $request = $this->dors3Mobile()->createOperationApprovalRequest(
                    $userId,
                    'article.submit',
                    'article',
                    (string)$articleId,
                    $fingerprint['display_fields'],
                    $fingerprint['fingerprint'],
                    ['article_id' => $articleId, 'author_id' => $userId],
                    $issuedAt,
                );
                $msg = Dors3UiText::get('messages.article_submit_waiting');
                if ($this->isAjax()) {
                    $this->json([
                        'success' => true,
                        'approval_required' => true,
                        'approval_request_id' => $request['public_id'],
                        'expires_at' => $request['expires_at'],
                        'message' => $msg,
                    ]);
                }
                $this->app->session->flash('success', $msg);
                redirect('/author');
            }

            (new ArticleService($this->app->db))->submit($articleId, $userId);
            $msg = 'Tekst wysłany do redakcji.';
            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) { 
            if ($this->isAjax()) {
                $this->json(['success' => false, 'message' => $this->safeError($e, 'Nie udało się wysłać tekstu.', 'article_submit')]);
            }
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się wysłać tekstu.', 'article_submit'));
        }
        redirect('/author');
    }

    public function publishArticle(): never
    {
        $userId = $this->requireApprovedAuthor();
        $roles = (new UserService($this->app->db))->findUserStatus($userId)['roles'] ?? '';
        $roleList = array_filter(explode(',', (string)$roles));
        try {
            if (!in_array('publisher', $roleList, true) && !in_array('chief_editor', $roleList, true)) {
                throw new \RuntimeException('Publikacja wymaga roli wydawcy lub redaktora naczelnego.');
            }
            if (!$this->mobileApprovalEnabled('article_publish_approval')) {
                throw new \RuntimeException('Publikacja autora wymaga jawnie włączonego 3DORS Author.');
            }
            $articleId = (int)($_POST['id'] ?? 0);
            $issuedAt = time();
            $fingerprint = (new \App\Services\Dors3OperationFingerprintService($this->app->db))
                ->articlePublish($articleId, $userId, $issuedAt);
            $request = $this->dors3Mobile()->createOperationApprovalRequest(
                $userId,
                'article.publish',
                'article',
                (string)$articleId,
                $fingerprint['display_fields'],
                $fingerprint['fingerprint'],
                ['article_id' => $articleId, 'author_id' => $userId],
                $issuedAt,
            );
            $msg = Dors3UiText::get('messages.article_publish_waiting');
            if ($this->isAjax()) {
                $this->json(['success' => true, 'approval_required' => true, 'approval_request_id' => $request['public_id'], 'message' => $msg]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $error) {
            if ($this->isAjax()) {
                $this->json(['success' => false, 'message' => $this->safeError($error, 'Nie udało się rozpocząć publikacji.', 'article_publish')]);
            }
            $this->app->session->flash('error', $this->safeError($error, 'Nie udało się rozpocząć publikacji.', 'article_publish'));
        }
        redirect('/author');
    }

    private function mobileApprovalEnabled(string $flag): bool
    {
        $mobile = $this->app->config['dors3']['mobile'] ?? null;
        return is_array($mobile)
            && \App\Security\Dors3\MobileApprovalConfiguration::isEnabled($mobile, 'author', $flag);
    }

    private function enforceArticleSubmitRateLimit(int $userId): void
    {
        $limiter = $this->app->rateLimiter;
        if ($limiter === null || !$limiter->available()) {
            return;
        }
        $ipHash = hash('sha256', \App\Core\RequestContext::ipAddress() ?? 'unknown');
        $accountKey = 'article-submit:account:' . $userId;
        $ipKey = 'article-submit:ip:' . $ipHash;
        if ($limiter->tooManyAttempts($accountKey, 10) || $limiter->tooManyAttempts($ipKey, 30)) {
            throw new \RuntimeException('Przekroczono limit wysyłania tekstów. Spróbuj ponownie za kilka minut.');
        }
        $limiter->hit($accountKey, 600);
        $limiter->hit($ipKey, 600);
    }

    private function dors3Mobile(): \App\Services\Dors3MobileService
    {
        return new \App\Services\Dors3MobileService(
            $this->app->db,
            \App\Services\SecretCipher::fromEnvironment(),
            $this->securityEvents(),
            is_array($this->app->config['dors3'] ?? null) ? $this->app->config['dors3'] : [],
        );
    }

    public function uploadImageAjax(): string
    {
        header('Content-Type: application/json');
        try {
            $userId = $this->requireApprovedAuthor();
            $articleId = (int)($_POST['article_id'] ?? 0);
            if ($articleId <= 0) throw new \InvalidArgumentException('Nieprawidłowy ID artykułu.');

            $position = (int)($_POST['image_position'] ?? 50);
            $article = (new ArticleService($this->app->db))->assertAuthorEditable($articleId, $userId);
            if (!$article) { throw new \InvalidArgumentException('Nie znaleziono artykułu.'); }

            $uploadService = new UploadService($this->app->db, $this->app->objectStorage);
            if (!empty($_POST['image_data'])) {
                $mediaId = $uploadService->uploadArticleImageDataUrl((string)$_POST['image_data'], $userId, $articleId, (string)($article['title'] ?? 'zdjecie-artykulu'), $position, true);
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $mediaId = $uploadService->uploadArticleImage($_FILES['image'], $userId, $articleId, $position, (string)($article['title'] ?? 'zdjecie-artykulu'), true);
            } else {
                throw new \InvalidArgumentException('Nie przesłano obrazu.');
            }
            $media = (new ArticleService($this->app->db))->getMedia($articleId, 1)[0] ?? null;

            return json_encode(['success' => true, 'message' => 'Zdjęcie zapisane', 'media' => $media]);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['success' => false, 'message' => $this->safeError($e, 'Nie udało się zapisać obrazu.', 'article_image_upload')]);
        }
    }

    public function deleteImageAjax(): string
    {
        header('Content-Type: application/json');
        try {
            $userId = $this->requireApprovedAuthor();
            $mediaId = (int)($_POST['media_id'] ?? 0);
            if ($mediaId <= 0) throw new \InvalidArgumentException('Nieprawidłowy ID mediów.');

            (new UploadService($this->app->db, $this->app->objectStorage))->deleteMedia($mediaId, $userId);

            return json_encode(['success' => true, 'message' => 'Zdjęcie usunięte']);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['success' => false, 'message' => $this->safeError($e, 'Nie udało się usunąć obrazu.', 'article_image_delete')]);
        }
    }

    public function updateImagePositionAjax(): string
    {
        header('Content-Type: application/json');
        try {
            $userId = $this->requireApprovedAuthor();
            $data = json_decode(file_get_contents('php://input'), true);
            $mediaId = (int)($data['media_id'] ?? 0);
            $position = (int)($data['position'] ?? 50);

            if ($mediaId <= 0) throw new \InvalidArgumentException('Nieprawidłowy ID mediów.');
            if ($position < 0 || $position > 100) throw new \InvalidArgumentException('Nieprawidłowa pozycja.');

            $updated = $this->app->db->query(
                'UPDATE media m
                 JOIN articles a ON a.id=m.article_id
                 SET m.image_position=?
                 WHERE m.id=? AND m.owner_user_id=? AND a.author_id=? AND a.status IN (\'draft\',\'rejected\')',
                [$position, $mediaId, $userId, $userId]
            );
            if ($updated->rowCount() !== 1) {
                throw new \RuntimeException('Media opublikowanego tekstu nie można zmieniać bez rewizji.');
            }

            return json_encode(['success' => true, 'message' => 'Pozycja zapisana']);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['success' => false, 'message' => $this->safeError($e, 'Nie udało się zmienić położenia obrazu.', 'article_image_position')]);
        }
    }
}
