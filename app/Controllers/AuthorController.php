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
            $this->app->session->flash('error', t('controller.author.konto_autora_czeka_na_akceptacje_redakcji_dodawanie_i_e_e274a122'));
            redirect('/author');
        }

        if (($user['status'] ?? '') !== 'active') {
            $this->app->session->flash('error', t('controller.author.konto_nie_jest_aktywne'));
            redirect('/');
        }

        if ((int)($user['can_write'] ?? 0) !== 1) {
            $this->app->session->flash('error', t('controller.author.mozliwosc_pisania_nie_jest_jeszcze_aktywna_redakcja_mus_557035f8'));
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
            'title' => t('layout.header.author_panel'),
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
        return $this->view('author/create_article', ['title' => t('author.article.new')]);
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

            $this->app->session->flash('success', t('controller.author.szkic_zapisany'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('controller.author.nie_udao_sie_zapisac_szkicu'), 'article_create'));
        }
        redirect('/author');
    }

    public function editArticle(): string
    {
        $userId = $this->requireApprovedAuthor();
        $articleService = new ArticleService($this->app->db);
        $article = $articleService->findForAuthor((int)($_GET['id'] ?? 0), $userId);
        if (!$article) { http_response_code(404); return $this->view('layouts/error', ['title'=>'404','message'=>t('controller.admin.nie_znaleziono_tekstu')]); }
        return $this->view('author/edit_article', [
            'title'=>t('author.article.edit'),
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

            $msg = t('controller.author.tekst_zaktualizowany');
            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg, 'article_id' => $articleId]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) { 
            if ($this->isAjax()) {
                $this->json(['success' => false, 'message' => $this->safeError($e, t('controller.author.nie_udao_sie_zapisac_tekstu'), 'article_update')]);
            }
            $this->app->session->flash('error', $this->safeError($e, t('controller.author.nie_udao_sie_zapisac_tekstu'), 'article_update'));
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
                throw new \RuntimeException(t('controller.author.redakcja_czasowo_zablokowaa_mozliwosc_wysyania_tekstow') . $until . '.');
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
            $msg = t('controller.author.tekst_wysany_do_redakcji');
            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) { 
            if ($this->isAjax()) {
                $this->json(['success' => false, 'message' => $this->safeError($e, t('controller.author.nie_udao_sie_wysac_tekstu'), 'article_submit')]);
            }
            $this->app->session->flash('error', $this->safeError($e, t('controller.author.nie_udao_sie_wysac_tekstu'), 'article_submit'));
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
                throw new \RuntimeException(t('controller.author.publikacja_wymaga_roli_wydawcy_lub_redaktora_naczelnego'));
            }
            if (!$this->mobileApprovalEnabled('article_publish_approval')) {
                throw new \RuntimeException(t('controller.author.publikacja_autora_wymaga_jawnie_waczonego_3dors_author'));
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
                $this->json(['success' => false, 'message' => $this->safeError($error, t('controller.author.nie_udao_sie_rozpoczac_publikacji'), 'article_publish')]);
            }
            $this->app->session->flash('error', $this->safeError($error, t('controller.author.nie_udao_sie_rozpoczac_publikacji'), 'article_publish'));
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
            throw new \RuntimeException(t('controller.author.przekroczono_limit_wysyania_tekstow_sprobuj_ponownie_za_9e180620'));
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
            if ($articleId <= 0) throw new \InvalidArgumentException(t('controller.author.nieprawidowy_id_artykuu'));

            $position = (int)($_POST['image_position'] ?? 50);
            $article = (new ArticleService($this->app->db))->assertAuthorEditable($articleId, $userId);
            if (!$article) { throw new \InvalidArgumentException(t('controller.adminarticletranslation.nie_znaleziono_artykuu')); }

            $uploadService = new UploadService($this->app->db, $this->app->objectStorage);
            if (!empty($_POST['image_data'])) {
                $mediaId = $uploadService->uploadArticleImageDataUrl((string)$_POST['image_data'], $userId, $articleId, (string)($article['title'] ?? 'zdjecie-artykulu'), $position, true);
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $mediaId = $uploadService->uploadArticleImage($_FILES['image'], $userId, $articleId, $position, (string)($article['title'] ?? 'zdjecie-artykulu'), true);
            } else {
                throw new \InvalidArgumentException(t('controller.author.nie_przesano_obrazu'));
            }
            $media = (new ArticleService($this->app->db))->getMedia($articleId, 1)[0] ?? null;

            return json_encode(['success' => true, 'message' => t('author.article.saved'), 'media' => $media]);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['success' => false, 'message' => $this->safeError($e, t('controller.author.nie_udao_sie_zapisac_obrazu'), 'article_image_upload')]);
        }
    }

    public function deleteImageAjax(): string
    {
        header('Content-Type: application/json');
        try {
            $userId = $this->requireApprovedAuthor();
            $mediaId = (int)($_POST['media_id'] ?? 0);
            if ($mediaId <= 0) throw new \InvalidArgumentException(t('controller.author.nieprawidowy_id_mediow'));

            (new UploadService($this->app->db, $this->app->objectStorage))->deleteMedia($mediaId, $userId);

            return json_encode(['success' => true, 'message' => t('controller.author.zdjecie_usuniete')]);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['success' => false, 'message' => $this->safeError($e, t('controller.author.nie_udao_sie_usunac_obrazu'), 'article_image_delete')]);
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

            if ($mediaId <= 0) throw new \InvalidArgumentException(t('controller.author.nieprawidowy_id_mediow'));
            if ($position < 0 || $position > 100) throw new \InvalidArgumentException(t('controller.author.nieprawidowa_pozycja'));

            $updated = $this->app->db->query(
                'UPDATE media m
                 JOIN articles a ON a.id=m.article_id
                 SET m.image_position=?
                 WHERE m.id=? AND m.owner_user_id=? AND a.author_id=? AND a.status IN (\'draft\',\'rejected\')',
                [$position, $mediaId, $userId, $userId]
            );
            if ($updated->rowCount() !== 1) {
                throw new \RuntimeException(t('controller.author.media_opublikowanego_tekstu_nie_mozna_zmieniac_bez_rewizji'));
            }

            return json_encode(['success' => true, 'message' => t('controller.author.pozycja_zapisana')]);
        } catch (\Throwable $e) {
            http_response_code(400);
            return json_encode(['success' => false, 'message' => $this->safeError($e, t('controller.author.nie_udao_sie_zmienic_poozenia_obrazu'), 'article_image_position')]);
        }
    }
}
