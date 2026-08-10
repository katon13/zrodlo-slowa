<?php
namespace App\Controllers;

use App\Services\Dors3UiText;

use App\Core\Database;
use App\Services\ArticleService;
use App\Services\PayoutService;
use App\Services\UserService;
use App\Services\CategoryService;
use App\Services\MailService;
use App\Services\ArticleEconomyService;
use App\Services\ArticleTranslationService;
use App\Services\SurveyService;
use App\Services\CampaignService;
use App\Services\UserDeletionService;
use App\Services\LedgerService;
use App\Services\RoleService;
use App\Services\AuthSecurityService;
use App\Services\FraudGuardService;
use App\Services\MainBannerService;
use App\Services\AiFoundationService;
use App\Services\OpenAiClient;
use App\Services\EarningsDiagnosticsService;
use App\Services\TalentRulePresenter;

final class AdminController extends BaseController
{
    public function dashboard(): string
    {
        $this->requireAdmin();
        $roles = $this->currentUserRoles();
        $roleService = new RoleService($this->app->db);
        $database = $this->app->db;
        $pendingApprovals = $database->one('SELECT COUNT(*) as cnt FROM financial_approvals WHERE status=\'pending\'')['cnt'] ?? 0;
        $sentinelOpenAlerts = $database->tableExists('security_alerts')
            ? (int)$database->cell('SELECT COUNT(*) FROM security_alerts WHERE status<>\'resolved\'')
            : 0;

        return $this->view('admin/dashboard', [
            'title' => 'Admin',
            'slowo_snajper' => $this->slowoSnajperConfig()->all(),
            'pending_authors_count' => (new UserService($this->app->db))->pendingAuthorsCount(),
            'role_panels' => $roleService->panelsForRoles($roles, in_array('admin', $roles, true)),
            'pending_approvals_count' => $pendingApprovals,
            'sentinel_open_alerts' => $sentinelOpenAlerts,
            'earnings_diagnostics' => (new EarningsDiagnosticsService(
                $this->app->db,
                $this->app->valkey,
                $this->slowoSnajperConfig(),
            ))->snapshot(),
        ]);
    }

    public function clearCache(): never
    {
        $this->requireAdmin();
        $ajax = $this->isAjax();

        try {
            $this->app->cache->flushAll();
        } catch (\Exception $e) {
            $this->cacheClearFailure($ajax, $e);
        }

        if ($ajax) {
            $this->json(['success' => true, 'message' => 'Cache strony wyczyszczony.']);
        }
        $_SESSION['flash_success'] = 'Cache strony wyczyszczony.';

        header('Location: /admin');
        exit;
    }

    private function cacheClearFailure(bool $ajax, \Throwable $error): never
    {
        if ($ajax) {
            $this->json(['success' => false, 'message' => 'Nie udało się wyczyścić cache.']);
        }
        $_SESSION['flash_error'] = $this->safeError($error, 'Nie udało się wyczyścić cache.', 'admin_cache');
        header('Location: /admin');
        exit;
    }

    public function articles(): string
    {
        $this->requireAdmin();
        [$page, $limit, $offset] = $this->slowoSnajper()->pageLimitOffset('admin_articles', $_GET['page'] ?? 1, 50, 200);
        $articles = (new ArticleService($this->app->db))->allForAdmin($limit, $offset, 'submitted');

        return $this->view('admin/articles', [
            'title' => 'Redakcja Główna',
            'articles' => $articles,
            'snajper_page' => $page,
            'snajper_limit' => $limit,
        ]);
    }

    public function editorial(): string
    {
        $this->requireAdminOrRoles([RoleService::ROLE_EDITOR, RoleService::ROLE_PUBLISHER]);
        [$page, $limit, $offset] = $this->slowoSnajper()->pageLimitOffset('admin_editorial', $_GET['page'] ?? 1, 50, 200);

        $articleService = new ArticleService($this->app->db);
        $articles = $articleService->allForAdmin($limit, $offset, null);
        $languages = $this->app->config['languages'] ?? [];
        $articleIds = array_map(static fn(array $article): int => (int)($article['id'] ?? 0), $articles);
        $translationMap = (new ArticleTranslationService($this->app->db, $languages))->mapForArticles($articleIds, false);

        return $this->view('admin/editorial_list', [
            'title' => 'Wydawca',
            'articles' => $articles,
            'languages' => $languages,
            'article_translations_map' => $translationMap,
            'snajper_page' => $page,
            'snajper_limit' => $limit,
        ]);
    }

    public function editEditorialArticle(): string
    {
        $this->requireAdminOrRoles([RoleService::ROLE_EDITOR, RoleService::ROLE_PUBLISHER]);
        $id = (int)($_GET['id'] ?? 0);
        $articleService = new ArticleService($this->app->db);
        $article = $articleService->findForAdmin($id);
        
        if (!$article) {
            http_response_code(404);
            return $this->view('layouts/error', ['title' => '404', 'message' => 'Nie znaleziono tekstu.']);
        }

        $translationService = new ArticleTranslationService($this->app->db, $this->app->config['languages'] ?? []);
        $currentRoles = $this->currentUserRoles();

        return $this->view('admin/editorial_edit', [
            'title' => 'Edycja tekstu: ' . $article['title'],
            'article' => $article,
            'media' => $articleService->getMedia($id),
            'translations' => $translationService->allForArticle($id),
            'languages' => $this->app->config['languages'] ?? [],
            'can_review_translations' => in_array(RoleService::ROLE_ADMIN, $currentRoles, true)
                || in_array(RoleService::ROLE_PUBLISHER, $currentRoles, true),
        ]);
    }

    public function updateEditorialArticle(): string
    {
        $adminId = $this->requireAdminOrRoles([RoleService::ROLE_EDITOR, RoleService::ROLE_PUBLISHER]);
        $id = (int)($_POST['id'] ?? 0);
        
        try {
            $db = $this->app->db;
            $languageConfig = $this->app->config['languages'] ?? [];
            $enabledLanguages = $languageConfig['public_enabled'] ?? ['pl', 'en', 'de', 'fr', 'it', 'es'];
            $enabledLanguages = array_values(array_unique(array_filter(
                array_map(static fn($language): string => strtolower(trim((string)$language)), is_array($enabledLanguages) ? $enabledLanguages : []),
                static fn(string $language): bool => $language !== ''
            )));
            $sourceLanguage = strtolower(trim((string)($_POST['source_language'] ?? ($languageConfig['default'] ?? 'pl'))));
            if (!in_array($sourceLanguage, $enabledLanguages, true)) {
                throw new \InvalidArgumentException('Nieprawidłowy język oryginału.');
            }

            $languageVersions = isset($_POST['language_versions']) && is_array($_POST['language_versions'])
                ? $_POST['language_versions']
                : [];
            $editorialData = $_POST;
            if ($languageVersions !== []) {
                $sourceVersion = $languageVersions[$sourceLanguage] ?? null;
                if (!is_array($sourceVersion)) {
                    throw new \InvalidArgumentException('Brak treści dla wybranego języka oryginału.');
                }

                $sourceTitle = trim(is_scalar($sourceVersion['title'] ?? null) ? (string)$sourceVersion['title'] : '');
                $sourceLead = trim(is_scalar($sourceVersion['lead'] ?? null) ? (string)$sourceVersion['lead'] : '');
                $sourceBody = trim(is_scalar($sourceVersion['body'] ?? null) ? (string)$sourceVersion['body'] : '');
                if ($sourceTitle === '' || $sourceBody === '') {
                    throw new \InvalidArgumentException('Tytuł i treść w języku oryginału są wymagane.');
                }

                $editorialData['title'] = $sourceTitle;
                $editorialData['lead'] = $sourceLead;
                $editorialData['body'] = $sourceBody;
                $editorialData['source_language'] = $sourceLanguage;
            }

            $id = $db->transaction(function (Database $db) use (
                $id,
                $adminId,
                $editorialData,
                $languageVersions,
                $enabledLanguages,
                $sourceLanguage,
                $languageConfig
            ): int {
                $effectiveArticleId = (new ArticleService($db))->updateEditorial($id, $adminId, $editorialData);
                if ($languageVersions === []) {
                    return $effectiveArticleId;
                }

                $translationService = new ArticleTranslationService($db, $languageConfig);
                foreach ($enabledLanguages as $language) {
                    if ($language === $sourceLanguage) {
                        continue;
                    }

                    $version = $languageVersions[$language] ?? null;
                    if (!is_array($version)) {
                        continue;
                    }

                    $title = trim(is_scalar($version['title'] ?? null) ? (string)$version['title'] : '');
                    $lead = trim(is_scalar($version['lead'] ?? null) ? (string)$version['lead'] : '');
                    $body = trim(is_scalar($version['body'] ?? null) ? (string)$version['body'] : '');
                    if ($title === '' && $lead === '' && $body === '') {
                        continue;
                    }
                    if ($title === '' || $body === '') {
                        throw new \InvalidArgumentException(
                            'Wersja ' . strtoupper($language) . ' jest niekompletna. Wpisz tytuł i treść albo pozostaw całą wersję pustą.'
                        );
                    }

                    $translationService->saveEditorialVersion($effectiveArticleId, $language, [
                        'source_language' => $sourceLanguage,
                        'title' => $title,
                        'lead' => $lead,
                        'body' => $body,
                        'translation_instructions' => (string)($editorialData['translation_instructions'] ?? ''),
                    ], $adminId);
                }

                return $effectiveArticleId;
            });

            $position = (int)($_POST['image_position'] ?? 50);
            $articleForImage = $db->one('SELECT title FROM articles WHERE id=:id', ['id' => $id]) ?: [];
            $imageTitleSeed = (string)($articleForImage['title'] ?? ($_POST['title'] ?? 'zdjecie-artykulu'));
            $uploadService = new \App\Services\UploadService($db, $this->app->objectStorage);
            if (!empty($_POST['image_data'])) {
                $uploadService->uploadArticleImageDataUrl((string)$_POST['image_data'], $adminId, $id, $imageTitleSeed, $position, true);
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadService->uploadArticleImage($_FILES['image'], $adminId, $id, $position, $imageTitleSeed, true);
            } elseif (isset($_POST['image_position'])) {
                // Just update position if image is already there
                $db->query('UPDATE media SET image_position=:pos WHERE article_id=:id', [
                    'pos' => (int)$_POST['image_position'],
                    'id' => $id
                ]);
            }

            if ($this->isAjax()) {
                header('Content-Type: application/json');
                return json_encode([
                    'success' => true,
                    'message' => t('editorial.editing.saved'),
                    'redirect' => (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) 
                        ? '/admin/editorial/edit?id=' . $id 
                        : null
                ]);
            }

            $this->app->session->flash('success', t('editorial.editing.saved'));
        } catch (\Throwable $e) {
            if ($this->isAjax()) {
                header('Content-Type: application/json');
                return json_encode([
                    'success' => false,
                    'message' => t('editorial.editing.save_error') . ': ' . $e->getMessage()
                ]);
            }
            $this->app->session->flash('error', t('editorial.editing.save_error') . ': ' . $e->getMessage());
        }

        redirect('/admin/editorial/edit?id=' . $id);
    }


    public function saveEditorialTranslation(): never
    {
        $adminId = $this->requireAdminOrRoles([RoleService::ROLE_EDITOR, RoleService::ROLE_PUBLISHER]);
        $articleId = (int)($_POST['article_id'] ?? 0);
        $language = (string)($_POST['language'] ?? '');

        try {
            $articleService = new ArticleService($this->app->db);
            $article = $articleService->findForAdmin($articleId);
            if (!$article) {
                throw new \RuntimeException('Nie znaleziono tekstu.');
            }

            $translationService = new ArticleTranslationService($this->app->db, $this->app->config['languages'] ?? []);
            $translationService->saveEditorialVersion($articleId, $language, [
                'source_language' => (string)($article['source_language'] ?? 'pl'),
                'title' => (string)($_POST['title'] ?? ''),
                'lead' => (string)($_POST['lead'] ?? ''),
                'body' => (string)($_POST['body'] ?? ''),
                'translation_instructions' => (string)($_POST['translation_instructions'] ?? ''),
            ], $adminId);

            $this->app->session->flash('success', 'Wersja językowa ' . strtoupper($language) . ' została zapisana.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Błąd zapisu wersji językowej: ' . $e->getMessage());
        }

        redirect('/admin/editorial/edit?id=' . $articleId . '#translations');
    }

    public function saveEditorialOrder(): string
    {
        $this->requireAdminOrRoles([RoleService::ROLE_EDITOR, RoleService::ROLE_PUBLISHER]);
        $order = $_POST['order'] ?? []; // Array of article IDs in order
        if (!is_array($order) || $order === []) {
            if ($this->isAjax()) { header('Content-Type: application/json'); }
            return json_encode(['success' => false, 'message' => 'Brak tekstów do zapisania kolejności.']);
        }

        try {
            $db = $this->app->db;
            $order = array_map('intval', $order);
            if (in_array(0, $order, true) || count($order) !== count(array_unique($order))) {
                throw new \InvalidArgumentException('Lista kolejności zawiera nieprawidłowe albo powtórzone identyfikatory.');
            }
            $idsSql = implode(',', $order);
            $existingCount = (int)$db->cell('SELECT COUNT(*) FROM articles WHERE id IN (' . $idsSql . ')');
            if ($existingCount !== count($order)) {
                throw new \RuntimeException('Co najmniej jeden tekst z listy już nie istnieje. Odśwież panel.');
            }
            $db->transaction(function($db) use ($order) {
                foreach ($order as $index => $id) {
                    $db->query('UPDATE articles SET display_order=:order WHERE id=:id', [
                        'order' => $index,
                        'id' => (int)$id
                    ]);
                }
            });
            if ($this->isAjax()) { header('Content-Type: application/json'); }
            return json_encode(['success' => true, 'message' => t('editorial.editing.order_saved')]);
        } catch (\Throwable $e) {
            if ($this->isAjax()) { header('Content-Type: application/json'); }
            return json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function toggleFeatured(): string
    {
        $this->requireAdminOrRoles([RoleService::ROLE_EDITOR, RoleService::ROLE_PUBLISHER]);
        $id = (int)($_POST['id'] ?? 0);
        $isFeatured = (int)($_POST['is_featured'] ?? 0);

        try {
            if ($id <= 0 || !$this->app->db->one('SELECT id FROM articles WHERE id=:id LIMIT 1', ['id' => $id])) {
                throw new \RuntimeException('Nie znaleziono tekstu.');
            }
            if (!isset($_POST['is_featured']) || !in_array((string)$_POST['is_featured'], ['0', '1'], true)) {
                throw new \InvalidArgumentException('Nieprawidłowy stan promocji tekstu.');
            }
            $this->app->db->query('UPDATE articles SET is_featured=:val WHERE id=:id', [
                'val' => $isFeatured ? 1 : 0,
                'id' => $id
            ]);
            if ($this->isAjax()) { header('Content-Type: application/json'); }
            return json_encode(['success' => true, 'message' => t('editorial.editing.saved')]);
        } catch (\Throwable $e) {
            if ($this->isAjax()) { header('Content-Type: application/json'); }
            return json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    public function editProofreadingArticle(): string
    {
        $this->requireAdminOrRoles([RoleService::ROLE_PROOFREADER]);
        $id = (int)($_GET['id'] ?? 0);
        $article = (new ArticleService($this->app->db))->findForProofreading($id);

        if (!$article) {
            http_response_code(404);
            return $this->view('layouts/error', ['title' => '404', 'message' => 'Nie znaleziono tekstu do korekty.']);
        }

        return $this->view('admin/proofreader_edit', [
            'title' => 'Korekta tekstu: ' . $article['title'],
            'article' => $article,
        ]);
    }

    public function updateProofreadingArticle(): never
    {
        $proofreaderId = $this->requireAdminOrRoles([RoleService::ROLE_PROOFREADER]);
        $id = (int)($_POST['id'] ?? 0);

        try {
            (new ArticleService($this->app->db))->updateProofreading($id, $proofreaderId, $_POST);
            $this->slowoSnajper()->audit($proofreaderId, 'article_proofread_saved', ['article_id'=>$id]);
            $this->app->session->flash('success', 'Korekta została zapisana. Tekst oznaczono jako KOREKTA.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać korekty: ' . $e->getMessage());
        }

        redirect('/admin/proofreader/edit?id=' . $id);
    }

    public function setArticleStatus(): never
    {
        $adminId = $this->requireAdmin();
        $articleId = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');

        try {
            $this->app->db->transaction(function($db) use ($articleId, $status, $adminId) {
                $effectiveArticleId = (new ArticleService($db))->setStatus($articleId, $status, $adminId);

                // Dodatkowo aktualizujemy wycenę, jeśli przesłano pola wyceny (np. z panelu moderatora)
                if (isset($_POST['access_mode'])) {
                    (new ArticleEconomyService($db))->valueArticle($effectiveArticleId, $adminId, $_POST);
                }
            });

            $this->slowoSnajper()->audit($adminId, 'article_status_update', ['article_id'=>$articleId, 'status'=>$status]);
            $labels = [
                'draft' => 'roboczy',
                'submitted' => 'do moderacji',
                'review' => 'w redakcji',
                'approved' => 'zaakceptowany',
                'published' => 'opublikowany',
                'rejected' => 'odrzucony',
                'archived' => 'niepubliczny',
            ];
            $msg = 'Status tekstu został zmieniony na: ' . ($labels[$status] ?? $status) . '.';

            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg]);
            }

            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) {
            $error = 'Nie udało się zmienić statusu tekstu: ' . $e->getMessage();
            if ($this->isAjax()) {
                $this->json(['success' => false, 'message' => $error]);
            }
            $this->app->session->flash('error', $error);
        }

        $returnTo = (string)($_POST['return_to'] ?? '');
        if ($returnTo === 'moderator') {
            redirect('/admin/role-panel?panel=moderator#article-' . $articleId);
        }

        redirect('/admin/articles#article-' . $articleId);
    }

    public function sendArticleToProofreading(): never
    {
        $adminId = $this->requireAdmin();
        $articleId = (int)($_POST['id'] ?? 0);

        try {
            (new ArticleService($this->app->db))->sendToProofreading($articleId, $adminId);
            $this->slowoSnajper()->audit($adminId, 'article_sent_to_proofreading', ['article_id'=>$articleId]);
            $msg = 'Tekst został skierowany do korekty.';
            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) {
            $error = 'Nie udało się skierować tekstu do korekty: ' . $e->getMessage();
            if ($this->isAjax()) {
                $this->json(['success' => false, 'message' => $error]);
            }
            $this->app->session->flash('error', $error);
        }

        redirect('/admin/articles#article-' . $articleId);
    }

    public function setArticleValuation(): never
    {
        $adminId = $this->requireAdmin();
        $articleId = (int)($_POST['id'] ?? 0);

        try {
            (new ArticleEconomyService($this->app->db))->valueArticle($articleId, $adminId, $_POST);
            $msg = 'Dane artykułu zostały zaktualizowane: wycena, etykieta i ustawienia dostępu.';
            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) {
            $error = 'Nie udało się zapisać wyceny tekstu: ' . $e->getMessage();
            if ($this->isAjax()) {
                $this->json(['success' => false, 'message' => $error]);
            }
            $this->app->session->flash('error', $error);
        }

        $returnTo = (string)($_POST['return_to'] ?? '');
        if ($returnTo === 'moderator') {
            redirect('/admin/role-panel?panel=moderator#article-' . $articleId);
        }

        redirect('/admin/articles#article-' . $articleId);
    }


    public function surveys(): string
    {
        $this->requireAdmin();
        $service = new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig()));
        return $this->view('admin/surveys', [
            'title' => 'Ankiety i sondaże',
            'surveys' => $service->allForAdmin(...array_slice($this->slowoSnajper()->pageLimitOffset('admin_surveys', $_GET['page'] ?? 1, 50, 200), 1)),
            'types' => $service->types(),
            'selected_survey' => isset($_GET['id']) ? $service->find((int)$_GET['id']) : null,
            'selected_questions' => isset($_GET['id']) ? $service->questions((int)$_GET['id']) : [],
        ]);
    }

    public function createSurvey(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $id = (new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig())))->createSurvey($adminId, $_POST);
            $this->app->session->flash('success', 'Ankieta została utworzona. Dodaj pytania i ustaw status aktywny, gdy będzie gotowa.');
            redirect('/admin/surveys?id=' . $id);
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się utworzyć ankiety: ' . $e->getMessage());
            redirect('/admin/surveys');
        }
    }

    public function updateSurvey(): never
    {
        $this->requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        try {
            (new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig())))->updateSurvey($id, $_POST);
            $this->app->session->flash('success', 'Ankieta została zapisana.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać ankiety: ' . $e->getMessage());
        }
        redirect('/admin/surveys?id=' . $id);
    }

    public function addSurveyQuestion(): never
    {
        $this->requireAdmin();
        $surveyId = (int)($_POST['survey_id'] ?? 0);
        try {
            (new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig())))->addQuestion($surveyId, $_POST);
            $this->app->session->flash('success', 'Pytanie zostało dodane.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się dodać pytania: ' . $e->getMessage());
        }
        redirect('/admin/surveys?id=' . $surveyId);
    }

    public function deleteSurveyQuestion(): never
    {
        $this->requireAdmin();
        $surveyId = (int)($_POST['survey_id'] ?? 0);
        try {
            (new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig())))->deleteQuestion(
                (int)($_POST['question_id'] ?? 0),
                $surveyId
            );
            $this->app->session->flash('success', 'Pytanie zostało usunięte.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się usunąć pytania: ' . $e->getMessage());
        }
        redirect('/admin/surveys?id=' . $surveyId);
    }

    public function surveyReport(): string
    {
        $this->requireAdmin();
        try {
            $report = (new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig())))->responseReport((int)($_GET['id'] ?? 0));
        } catch (\RuntimeException) {
            http_response_code(404);
            return $this->view('layouts/error', [
                'title' => 'Nie znaleziono ankiety',
                'message' => 'Wybrana ankieta nie istnieje albo została usunięta.',
            ]);
        }
        return $this->view('admin/survey_report', [
            'title' => 'Raport ankiety',
            'survey' => $report['survey'],
            'questions' => $report['questions'],
            'responses' => $report['responses'],
            'summary' => $report['summary'],
        ]);
    }


    public function campaigns(): string
    {
        $this->requireAdmin();
        $service = $this->campaignService();
        return $this->view('admin/campaigns', [
            'title' => 'Kampanie, reklamy, PPV i live',
            'campaigns' => $service->allForAdmin(...array_slice($this->slowoSnajper()->pageLimitOffset('admin_campaigns', $_GET['page'] ?? 1, 50, 200), 1)),
            'types' => $service->types(),
            'statuses' => $service->statuses(),
            'selected_campaign' => isset($_GET['id']) ? $service->find((int)$_GET['id']) : null,
        ]);
    }

    public function createCampaign(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $id = $this->campaignService()->create($adminId, $_POST);
            $this->app->session->flash('success', 'Kampania została utworzona.');
            redirect('/admin/campaigns?id=' . $id);
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się utworzyć kampanii: ' . $e->getMessage());
            redirect('/admin/campaigns');
        }
    }

    public function updateCampaign(): never
    {
        $adminId = $this->requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        try {
            $this->campaignService()->update($id, $adminId, $_POST);
            $this->app->session->flash('success', 'Kampania została zapisana.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać kampanii: ' . $e->getMessage());
        }
        redirect('/admin/campaigns?id=' . $id);
    }

    public function campaignReport(): string
    {
        $this->requireAdmin();
        try {
            $report = $this->campaignService()->report((int)($_GET['id'] ?? 0));
        } catch (\RuntimeException) {
            http_response_code(404);
            return $this->view('layouts/error', [
                'title' => 'Nie znaleziono kampanii',
                'message' => 'Wybrana kampania nie istnieje albo została usunięta.',
            ]);
        }
        return $this->view('admin/campaign_report', [
            'title' => 'Raport kampanii',
            'campaign' => $report['campaign'],
            'events' => $report['events'],
            'recent' => $report['recent'],
        ]);
    }

    private function campaignService(): CampaignService
    {
        return new CampaignService($this->app->db, $this->talentService(), new FraudGuardService($this->app->db, $this->slowoSnajperConfig()));
    }

    public function payouts(): string
    {
        $this->requireAdmin();
        $service = new PayoutService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig()));
        return $this->view('admin/payouts', [
            'title' => 'Wypłaty',
            'payouts' => $service->all(...array_slice($this->slowoSnajper()->pageLimitOffset('admin_payouts', $_GET['page'] ?? 1, 50, 200), 1)),
            'summary' => $service->summary(),
        ]);
    }

    public function setPayoutStatus(): never
    {
        $adminId = $this->requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $note = trim($_POST['admin_note'] ?? '');

        try {
            $payout = $this->app->db->one('SELECT * FROM payouts WHERE id=:id', ['id' => $id]);
            if (!$payout) throw new \RuntimeException('Wypłata nie istnieje.');
            if (!in_array($status, [
                PayoutService::STATUS_APPROVED,
                PayoutService::STATUS_PAID,
                PayoutService::STATUS_REJECTED,
                PayoutService::STATUS_CANCELLED,
            ], true)) {
                throw new \InvalidArgumentException('Nieobsługiwany status wypłaty.');
            }

            $this->authorizeCriticalOperation(
                $adminId,
                'payout.status_request',
                'payout',
                (string)$id,
                [
                    'recipient_user_id' => (int)$payout['user_id'],
                    'amount_minor' => (int)$payout['amount_minor'],
                    'currency' => (string)$payout['currency'],
                    'admin_note' => $note,
                ],
                ['status' => (string)$payout['status']],
                ['status' => $status],
            );
            if ($this->mobileApprovalEnabled('payout_approval')) {
                $issuedAt = time();
                $fingerprint = (new \App\Services\Dors3OperationFingerprintService($this->app->db))
                    ->payoutStatus($id, $status, $issuedAt);
                $actionType = in_array($status, [PayoutService::STATUS_REJECTED, PayoutService::STATUS_CANCELLED], true)
                    ? 'payout.reject'
                    : 'payout.approve';
                $request = $this->dors3Mobile()->createOperationApprovalRequest(
                    $adminId,
                    $actionType,
                    'payout',
                    (string)$id,
                    $fingerprint['display_fields'],
                    $fingerprint['fingerprint'],
                    [
                        'payout_id' => $id,
                        'target_status' => $status,
                        'admin_id' => $adminId,
                        'admin_note' => $note,
                    ],
                    $issuedAt,
                );
                $this->app->session->flash(
                    'success',
                    Dors3UiText::get('messages.payout_waiting', ['id' => (string)$request['public_id']])
                );
                redirect('/admin/payouts');
            }

            $financialService = new \App\Services\FinancialService($this->app->db);
            $financialService->requestApproval(
                'payout_status_update',
                (int)$payout['amount_minor'],
                (string)$payout['currency'],
                0,
                (int)$payout['user_id'],
                ['payout_id' => $id, 'target_status' => $status, 'admin_note' => $note],
                "Zmiana statusu wypłaty #$id na $status. Notatka: $note"
            );
            $this->app->session->flash('info', 'Zlecenie zmiany statusu wypłaty oczekuje na zatwierdzenie drugiego pracownika (Maker–Checker).');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zmienić statusu wypłaty: ' . $e->getMessage());
        }
        redirect('/admin/payouts');
    }

    public function users(): string
    {
        $this->requireAdmin();
        return $this->view('admin/users', [
            'title' => 'Użytkownicy',
            'users' => (new UserService($this->app->db))->listUsers(...array_slice($this->slowoSnajper()->pageLimitOffset('admin_users', $_GET['page'] ?? 1, 50, 200), 1)),
            'hide_global_flashes' => true
        ]);
    }

    public function setUserStatus(): never
    {
        $adminId = $this->requireAdmin();
        $userId = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');

        try {
            if ($userId === $adminId && $status !== 'active') {
                throw new \RuntimeException('Nie możesz zablokować ani dezaktywować własnego konta administratora.');
            }
            $beforeUser = $this->app->db->one('SELECT status FROM users WHERE id=:id', ['id' => $userId]);
            if ($beforeUser === null) {
                throw new \RuntimeException('Nie znaleziono użytkownika.');
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'user.status.update',
                'user',
                (string)$userId,
                [],
                ['status' => (string)$beforeUser['status']],
                ['status' => $status],
            );
            (new UserService($this->app->db))->setStatus($userId, $status);
            $this->slowoSnajper()->audit($adminId, 'user_status_update', ['user_id'=>$userId, 'status'=>$status]);
            $this->app->session->flash('success', 'Status użytkownika został zmieniony.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zmienić statusu: ' . $e->getMessage());
        }

        $this->app->session->flash('last_user_id', $userId);
        redirect('/admin/users#user-' . $userId);
    }

    public function userDeleteReport(): string
    {
        $this->requireAdmin();
        $userId = (int)($_GET['id'] ?? 0);
        $service = new UserDeletionService($this->app->db);

        try {
            $report = $service->report($userId);
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się przygotować raportu użytkownika: ' . $e->getMessage());
            redirect('/admin/users');
        }

        return $this->view('admin/user_delete', [
            'title' => 'Bezpieczne usuwanie użytkownika',
            'report' => $report,
            'recent_reports' => $service->recentReports(),
            'hide_global_flashes' => true
        ]);
    }

    public function anonymizeUser(): never
    {
        $adminId = $this->requireAdmin();
        $userId = (int)($_POST['id'] ?? 0);

        try {
            $beforeUser = $this->app->db->one('SELECT status,email,login_name FROM users WHERE id=:id', ['id' => $userId]);
            if ($beforeUser === null) {
                throw new \RuntimeException('Nie znaleziono użytkownika.');
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'user.anonymize',
                'user',
                (string)$userId,
                ['deletion_mode' => 'anonymized'],
                $beforeUser,
                ['status' => 'deleted', 'deletion_mode' => 'anonymized'],
            );
            (new UserDeletionService($this->app->db))->anonymize($userId, $adminId);
            $this->app->session->flash('last_user_id', $userId);
            $this->app->session->flash('success', 'Użytkownik został dezaktywowany i zanonimizowany.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zanonimizować użytkownika: ' . $e->getMessage());
            redirect('/admin/users/delete?id=' . $userId);
        }

        redirect('/admin/users');
    }

    public function hardCleanUser(): never
    {
        $adminId = $this->requireAdmin();
        $userId = (int)($_POST['id'] ?? 0);

        try {
            if (!$this->slowoSnajperConfig()->antiHeavyFlag('allow_hard_user_clean', false)) {
                throw new \RuntimeException('Twarde czyszczenie użytkownika jest odpięte w SNAJPERZE SŁOWA. Włącz je świadomie w Administracja → Ustawienia → SNAJPER SŁOWA.');
            }
            $beforeUser = $this->app->db->one('SELECT status,email,login_name FROM users WHERE id=:id', ['id' => $userId]);
            if ($beforeUser === null) {
                throw new \RuntimeException('Nie znaleziono użytkownika.');
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'user.hard_clean',
                'user',
                (string)$userId,
                ['deletion_mode' => 'hard_clean', 'confirmation' => (string)($_POST['confirmation'] ?? '')],
                $beforeUser,
                ['status' => 'deleted', 'deletion_mode' => 'hard_clean'],
            );
            (new UserDeletionService($this->app->db))->hardClean($userId, $adminId, (string)($_POST['confirmation'] ?? ''));
            $this->slowoSnajper()->audit($adminId, 'user_hard_clean', ['user_id'=>$userId]);
            $this->app->session->flash('last_user_id', $userId);
            $this->app->session->flash('success', 'Użytkownik został twardo wyczyszczony.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się wykonać twardego czyszczenia: ' . $e->getMessage());
            redirect('/admin/users/delete?id=' . $userId);
        }

        redirect('/admin/users');
    }

    public function setUserRole(): never
    {
        $adminId = $this->requireAdmin();
        $userId = (int)($_POST['id'] ?? 0);
        $role = (string)($_POST['role'] ?? '');

        try {
            if ($userId === $adminId && $role !== 'admin') {
                throw new \RuntimeException('Nie możesz odebrać roli administratora własnemu kontu.');
            }
            $beforeRoles = array_map(
                static fn(array $row): string => (string)$row['role'],
                $this->app->db->all('SELECT role FROM user_roles WHERE user_id=:id ORDER BY role', ['id' => $userId])
            );
            $this->authorizeCriticalOperation(
                $adminId,
                'user.primary_role.update',
                'user',
                (string)$userId,
                ['target_role' => $role],
                ['roles' => $beforeRoles],
                ['primary_role' => $role],
            );
            if ($this->mobileApprovalEnabled('admin_critical_approval')) {
                $issuedAt = time();
                $fingerprint = (new \App\Services\Dors3OperationFingerprintService($this->app->db))->adminCritical(
                    'role.change',
                    $adminId,
                    'user',
                    (string)$userId,
                    ['kind' => 'primary_role', 'target_role' => $role],
                    ['roles' => $beforeRoles],
                    ['primary_role' => $role],
                    $issuedAt,
                );
                $request = $this->dors3Mobile()->createOperationApprovalRequest(
                    $adminId,
                    'role.change',
                    'user',
                    (string)$userId,
                    $fingerprint['display_fields'],
                    $fingerprint['fingerprint'],
                    [
                        'kind' => 'primary_role',
                        'target_user_id' => $userId,
                        'target_role' => $role,
                        'admin_id' => $adminId,
                    ],
                    $issuedAt,
                );
                $this->app->session->flash('success', Dors3UiText::get('messages.primary_role_waiting', ['id' => (string)$request['public_id']]));
                redirect('/admin/users#user-' . $userId);
            }
            (new UserService($this->app->db))->setPrimaryRole($userId, $role);
            $this->slowoSnajper()->audit($adminId, 'user_role_update', ['user_id'=>$userId, 'role'=>$role]);
            $this->app->session->flash('success', 'Typ konta użytkownika został zmieniony bez naruszania ról redakcyjnych.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zmienić typu konta: ' . $e->getMessage());
        }

        $this->app->session->flash('last_user_id', $userId);
        redirect('/admin/users#user-' . $userId);
    }


    public function approveAuthor(): never
    {
        $adminId = $this->requireAdmin();
        $userId = (int)($_POST['id'] ?? 0);

        try {
            $beforeUser = $this->app->db->one('SELECT status,can_write FROM users WHERE id=:id', ['id' => $userId]);
            if ($beforeUser === null) {
                throw new \RuntimeException('Nie znaleziono autora.');
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'author.approve',
                'user',
                (string)$userId,
                [],
                $beforeUser,
                ['status' => 'active', 'can_write' => 1],
            );
            (new UserService($this->app->db, $this->app->queueSignals))->approveAuthor($userId);
            $this->app->session->flash('last_user_id', $userId);
            $this->app->session->flash('success', 'Autor został zatwierdzony. Włączono zgodę Pisanie.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zatwierdzić autora: ' . $e->getMessage());
        }

        redirect('/admin/users#user-' . $userId);
    }

    public function updateUserPermissions(): never
    {
        $adminId = $this->requireAdmin();
        $userId = (int)($_POST['id'] ?? 0);

        try {
            $beforePermissions = $this->app->db->one(
                'SELECT can_write,talent_enabled,wallet_enabled,payout_enabled FROM users WHERE id=:id',
                ['id' => $userId]
            );
            if ($beforePermissions === null) {
                throw new \RuntimeException('Nie znaleziono użytkownika.');
            }
            $afterPermissions = [
                'can_write' => isset($_POST['can_write']) ? 1 : 0,
                'talent_enabled' => isset($_POST['talent_enabled']) ? 1 : 0,
                'wallet_enabled' => isset($_POST['wallet_enabled']) ? 1 : 0,
                'payout_enabled' => isset($_POST['payout_enabled']) ? 1 : 0,
            ];
            $this->authorizeCriticalOperation(
                $adminId,
                'user.operational_permissions.update',
                'user',
                (string)$userId,
                [],
                $beforePermissions,
                $afterPermissions,
            );
            $changed = (new UserService($this->app->db, $this->app->queueSignals))
                ->updateOperationalPermissions($userId, $_POST);
            $this->slowoSnajper()->audit($this->app->session->userId(), 'user_operational_permissions_update', ['user_id'=>$userId, 'changed'=>array_keys($changed)]);
            $this->app->session->flash('last_user_id', $userId);
            if ($changed === []) {
                $this->app->session->flash('success', 'Zgody operacyjne bez zmian.');
            } else {
                $labels = [
                    'can_write' => 'Pisanie',
                    'talent_enabled' => 'Talent',
                    'wallet_enabled' => 'Wallet',
                    'payout_enabled' => 'Wypłaty',
                ];
                $changedLabels = [];
                foreach (array_keys($changed) as $key) {
                    $changedLabels[] = $labels[$key] ?? $key;
                }
                $this->app->session->flash('success', 'Zapisano zgody operacyjne: ' . implode(', ', $changedLabels) . '.');
            }
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać zgód operacyjnych: ' . $e->getMessage());
        }

        redirect('/admin/users#user-' . $userId);
    }


    public function roles(): string
    {
        $this->requireAdmin();
        [$page, $limit, $offset] = $this->slowoSnajper()->pageLimitOffset('admin_roles_users', $_GET['page'] ?? 1, 50, 200);
        $roleService = new RoleService($this->app->db);
        return $this->view('admin/roles', [
            'title' => 'Role i uprawnienia SNAJPERA SŁOWA',
            'roles' => $roleService->editorialRoles(),
            'panels' => $roleService->panelDefinitions(),
            'users' => $roleService->listUsersForRoleAdmin($limit, $offset),
            'snajper_page' => $page,
            'snajper_limit' => $limit,
        ]);
    }

    public function updateEditorialRoles(): never
    {
        $adminId = $this->requireAdmin();
        $userId = (int)($_POST['user_id'] ?? 0);
        try {
            $beforeRoles = array_map(
                static fn(array $row): string => (string)$row['role'],
                $this->app->db->all('SELECT role FROM user_roles WHERE user_id=:id ORDER BY role', ['id' => $userId])
            );
            $requestedRoles = array_values(array_map('strval', is_array($_POST['roles'] ?? null) ? $_POST['roles'] : []));
            sort($requestedRoles, SORT_STRING);
            $this->authorizeCriticalOperation(
                $adminId,
                'user.editorial_roles.update',
                'user',
                (string)$userId,
                [],
                ['roles' => $beforeRoles],
                ['requested_editorial_roles' => $requestedRoles],
            );
            if ($this->mobileApprovalEnabled('admin_critical_approval')) {
                $issuedAt = time();
                $fingerprint = (new \App\Services\Dors3OperationFingerprintService($this->app->db))->adminCritical(
                    'role.change',
                    $adminId,
                    'user',
                    (string)$userId,
                    ['kind' => 'editorial_roles'],
                    ['roles' => $beforeRoles],
                    ['requested_editorial_roles' => $requestedRoles],
                    $issuedAt,
                );
                $request = $this->dors3Mobile()->createOperationApprovalRequest(
                    $adminId,
                    'role.change',
                    'user',
                    (string)$userId,
                    $fingerprint['display_fields'],
                    $fingerprint['fingerprint'],
                    [
                        'kind' => 'editorial_roles',
                        'target_user_id' => $userId,
                        'target_roles' => $requestedRoles,
                        'admin_id' => $adminId,
                    ],
                    $issuedAt,
                );
                $this->app->session->flash('success', Dors3UiText::get('messages.editorial_roles_waiting', ['id' => (string)$request['public_id']]));
                redirect('/admin/roles#user-' . $userId);
            }
            $changed = (new RoleService($this->app->db))->syncEditorialRoles($userId, $_POST['roles'] ?? [], $adminId);
            $this->slowoSnajper()->audit($adminId, 'editorial_roles_update', [
                'user_id' => $userId,
                'added' => $changed['added'] ?? [],
                'removed' => $changed['removed'] ?? [],
                'selected' => $changed['selected'] ?? [],
            ]);
            $this->app->session->flash('last_user_id', $userId);
            $this->app->session->flash('success', 'Role redakcyjne zostały zapisane. Dostęp do kafelków działa według SNAJPERA SŁOWA. Wysokie role wymagają teraz potwierdzonego e-maila i 2FA przed wejściem do kafelka.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać ról redakcyjnych: ' . $e->getMessage());
        }
        redirect('/admin/roles#user-' . $userId);
    }

    public function setAuthorSubmitBlock(): never
    {
        $adminId = $this->requireAdmin();
        $authorId = (int)($_POST['author_id'] ?? 0);
        $duration = (string)($_POST['duration'] ?? '');
        $reason = (string)($_POST['reason'] ?? '');
        $articleId = (int)($_POST['article_id'] ?? 0);

        try {
            $res = (new \App\Services\UserService($this->app->db))->setAuthorSubmitBlock($authorId, $adminId, $duration, $reason);
            $msg = $duration === 'clear' ? 'Blokada została zdjęta.' : 'Autor został zablokowany.';

            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) {
            if ($this->isAjax()) {
                $this->json(['success' => false, 'message' => $e->getMessage()]);
            }
            $this->app->session->flash('error', $e->getMessage());
        }

        if ($articleId > 0) {
            redirect('/admin/articles#article-' . $articleId);
        }
        redirect('/admin/role-panel?panel=moderator');
    }


    public function adminDisableUser2fa(): never
    {
        $adminId = $this->requireAdmin();
        $userId = (int)($_POST['user_id'] ?? 0);
        try {
            $beforeSecurity = $this->app->db->one(
                'SELECT two_factor_enabled,session_version FROM users WHERE id=:id',
                ['id' => $userId]
            );
            if ($beforeSecurity === null) {
                throw new \RuntimeException('Nie znaleziono użytkownika.');
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'user.totp.disable',
                'user',
                (string)$userId,
                ['sessions_invalidated' => true],
                $beforeSecurity,
                ['two_factor_enabled' => 0, 'session_version' => (int)$beforeSecurity['session_version'] + 1],
            );
            (new AuthSecurityService($this->app->db, $this->slowoSnajperConfig()))->disableTwoFactorByAdmin($userId, $adminId);
            $this->app->session->flash('last_user_id', $userId);
            $this->app->session->flash('success', '2FA użytkownika zostało wyłączone. Konto ma wymuszoną ponowną konfigurację 2FA.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się wyłączyć 2FA: ' . $e->getMessage());
        }
        redirect('/admin/roles#user-' . $userId);
    }

    public function rolePanel(): string
    {
        $panelCode = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['panel'] ?? ''));
        $roleService = new RoleService($this->app->db);
        $panels = $roleService->panelDefinitions();
        if (!isset($panels[$panelCode])) {
            http_response_code(404);
            return $this->view('layouts/error', [
                'title' => 'Nie znaleziono kafelka',
                'message' => 'Ten kafelek SNAJPERA SŁOWA nie istnieje.',
            ]);
        }

        $userId = $this->requireAdminOrRoles([$panels[$panelCode]['role']]);
        $currentRoles = $this->currentUserRoles();
        $isAdmin = in_array('admin', $currentRoles, true);
        if (!$isAdmin) {
            $this->requireHighRoleSecurity($userId, $panels[$panelCode]['title']);
        }
        if (!$roleService->canAccessPanel($userId, $panelCode, $isAdmin)) {
            http_response_code(403);
            return $this->view('layouts/error', [
                'title' => 'Brak uprawnień',
                'message' => 'Nie masz przypisanej roli wymaganej dla tego kafelka.',
            ]);
        }

        [$page, $limit, $offset] = $this->slowoSnajper()->pageLimitOffset('role_panel_items', $_GET['page'] ?? 1, 50, 200);
        $rows = $roleService->panelRows($panelCode, $limit, $offset);
        $languages = $this->app->config['languages'] ?? [];
        $translationMap = [];

        if ($panelCode === 'moderator') {
            $articleIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows);
            $translationMap = (new ArticleTranslationService($this->app->db, $languages))->mapForArticles($articleIds, false);
        }

        return $this->view('admin/role_panel', [
            'title' => $panels[$panelCode]['title'],
            'panel_code' => $panelCode,
            'panel' => $panels[$panelCode],
            'rows' => $rows,
            'languages' => $languages,
            'article_translations_map' => $translationMap,
            'revenue_split_policy' => $panelCode === 'moderator'
                ? (new \App\Services\SafetyFundService($this->app->db))->currentPolicy()
                : null,
            'snajper_page' => $page,
            'snajper_limit' => $limit,
        ]);
    }


    public function mainBanner(): string
    {
        $this->requireAdmin();
        $languages = $this->app->config['languages']['public_enabled'] ?? ['pl', 'en', 'de', 'fr', 'it', 'es'];
        $labels = $this->app->config['languages']['labels'] ?? [];

        return $this->view('admin/main_banner', [
            'title' => 'Baner Główny',
            'banner' => (new MainBannerService($this->app->db))->forAdmin($languages),
            'languages' => $languages,
            'language_labels' => $labels,
        ]);
    }

    public function updateMainBanner(): never
    {
        $this->requireAdmin();
        $languages = $this->app->config['languages']['public_enabled'] ?? ['pl', 'en', 'de', 'fr', 'it', 'es'];

        try {
            (new MainBannerService($this->app->db))->updateFromAdmin($_POST, $languages);
            $this->app->cache->flushGroup('main_banner_public');
            $this->app->session->flash('success', 'Baner Główny został zapisany.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać Baneru Głównego: ' . $e->getMessage());
        }

        redirect('/admin/main-banner');
    }

    public function translateMainBannerAi(): never
    {
        $adminId = $this->requirePermission(\App\Security\Authorization\PermissionCatalog::AI_BANNER_TRANSLATE);
        $languageConfig = $this->app->config['languages'] ?? [];
        $languages = $languageConfig['public_enabled'] ?? ['pl', 'en', 'de', 'fr', 'it', 'es'];
        $targetLanguages = array_values(array_filter(
            array_map(static fn($lang): string => strtolower(trim((string)$lang)), is_array($languages) ? $languages : []),
            static fn(string $lang): bool => $lang !== '' && $lang !== 'pl'
        ));

        try {
            $source = $this->app->db->one(
                'SELECT t.kicker,t.title,t.lead_text,t.body_text,t.button_label,t.updated_at
                 FROM main_banner_translations t
                 JOIN main_banners b ON b.id=t.banner_id
                 WHERE b.slug=\'home-main\' AND t.language=\'pl\' LIMIT 1'
            );
            $job = (new \App\Services\AiBackgroundJobService(
                new \App\Services\DurableJobQueue($this->app->db, $this->app->queueSignals),
                new \App\Services\StructuredAuditService($this->app->db),
            ))->queueMainBannerTranslation(
                $adminId,
                \App\Security\Authorization\PermissionCatalog::AI_BANNER_TRANSLATE,
                $targetLanguages,
                trim((string)($_POST['translation_instructions'] ?? '')),
                hash('sha256', json_encode($source ?: [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            );

            $this->slowoSnajper()->audit($adminId, 'main_banner_ai_translate.queued', [
                'background_job_id' => (int)$job['id'],
                'target_languages' => $targetLanguages,
            ]);
            $this->app->session->flash('success', 'Tłumaczenie Baneru Głównego trafiło do izolowanej kolejki AI.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się przetłumaczyć Baneru Głównego przez AI: ' . $e->getMessage());
        }

        redirect('/admin/main-banner#translations');
    }

    public function categories(): string
    {
        $this->requireAdmin();
        return $this->view('admin/categories', [
            'title' => 'Kategorie', 
            'categories' => (new CategoryService($this->app->db))->allForAdmin()
        ]);
    }

    public function createCategory(): never
    {
        $this->requireAdmin();
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $name = trim($_POST['name'] ?? '');

        try {
            (new CategoryService($this->app->db))->create($name);
            $this->app->cache->flushGroup('site_menu');
            $this->app->session->flash('success', 'Kategoria została utworzona.');
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Kategoria została utworzona.']);
                exit;
            }
        } catch (\Throwable $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Błąd: ' . $e->getMessage()]);
                exit;
            }
            $this->app->session->flash('error', 'Błąd: ' . $e->getMessage());
        }
        redirect('/admin/categories');
    }

    public function updateCategory(): never
    {
        $this->requireAdmin();
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'show_in_menu' => isset($_POST['show_in_menu']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'menu_order' => (int)($_POST['menu_order'] ?? 100),
            'translations' => $_POST['translations'] ?? []
        ];

        try {
            (new CategoryService($this->app->db))->update($id, $data);
            $this->app->cache->flushGroup('site_menu');
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Zmiany w kategorii zostały zapisane.']);
                exit;
            }
            $this->app->session->flash('success', 'Zmiany w kategorii zostały zapisane.');
        } catch (\Throwable $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Błąd: ' . $e->getMessage()]);
                exit;
            }
            $this->app->session->flash('error', 'Błąd: ' . $e->getMessage());
        }
        redirect('/admin/categories');
    }

    public function deleteCategory(): never
    {
        $this->requireAdmin();
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $id = (int)($_POST['id'] ?? 0);
        
        try {
            $success = (new CategoryService($this->app->db))->delete($id);
        } catch (\Throwable $e) {
            $msg = 'Nie udało się usunąć kategorii: ' . $e->getMessage();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            $this->app->session->flash('error', $msg);
            redirect('/admin/categories');
        }
        
        if ($success) {
            $this->app->cache->flushGroup('site_menu');
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Kategoria została usunięta.']);
                exit;
            }
            $this->app->session->flash('success', 'Kategoria została usunięta.');
        } else {
            $msg = 'Ta kategoria ma przypisane artykuły. Możesz ją ukryć w menu albo dezaktywować.';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $msg]);
                exit;
            }
            $this->app->session->flash('error', $msg);
        }
        redirect('/admin/categories');
    }

    public function mails(): string
    {
        $this->requireAdmin();
        return $this->view('admin/mails', ['title'=>'Kolejka maili', 'mails'=>(new MailService($this->app->db))->recent()]);
    }


    public function antiFraud(): string
    {
        $adminId = $this->requireAdmin();
        $this->requireHighRoleSecurity($adminId, 'panelu ANTYFRAUD');

        [$page, $limit, $offset] = $this->slowoSnajper()->pageLimitOffset('admin_fraud_events', $_GET['page'] ?? 1, 50, 200);
        $guard = new FraudGuardService($this->app->db, $this->slowoSnajperConfig());
        $data = $guard->dashboard($limit);

        return $this->view('admin/anti_fraud', [
            'title' => 'ANTYFRAUD',
            'summary' => $data['summary'],
            'events' => $data['events'],
            'risk_users' => $data['users'],
            'snajper_page' => $page,
            'snajper_limit' => $limit,
            'snajper_offset' => $offset,
            'slowo_snajper' => $this->slowoSnajperConfig()->all(),
        ]);
    }

    public function runFraudScan(): never
    {
        $adminId = $this->requireAdmin();
        $this->requireHighRoleSecurity($adminId, 'skanowania ANTYFRAUD');

        $limit = $this->slowoSnajperConfig()->limit('fraud_scan_users', 200, 1000);
        try {
            $result = (new FraudGuardService($this->app->db, $this->slowoSnajperConfig()))->scan($limit);
            $this->slowoSnajper()->audit($adminId, 'fraud_scan_manual', $result);
            $this->app->session->flash('success', 'Skan antyfraudowy zakończony. Sprawdzono: ' . (int)$result['scanned'] . ', oznaczono: ' . (int)$result['flagged'] . '.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się wykonać skanu antyfraudowego: ' . $e->getMessage());
        }

        redirect('/admin/anti-fraud');
    }

    public function settings(): string
    {
        $this->requireAdmin();
        $settings = $this->app->db->all('SELECT * FROM settings ORDER BY name');
        $talentService = new \App\Services\TalentService($this->app->db, new \App\Services\LedgerService($this->app->db, new \App\Services\FinancialService($this->app->db)));
        $rules = array_values(array_filter(
            $talentService->getRules(),
            static fn(array $rule): bool => (string)$rule['activity_type'] !== \App\Services\AppReferralService::ACTIVITY_TYPE
        ));
        return $this->view('admin/settings', [
            'title' => 'Ustawienia, Talent i SNAJPER SŁOWA',
            'settings' => $settings,
            'rules' => $rules,
            'talent_rule_groups' => TalentRulePresenter::groups($rules),
            'referral_overview' => $this->appReferralService()->adminOverview(),
            'slowo_snajper' => $this->slowoSnajperConfig()->all(),
        ]);
    }

    public function updateSettings(): never
    {
        $adminId = $this->requireAdmin();
        $allowed = [
            'site.name' => 'text',
            'site.tagline' => 'text',
            'migration.status' => 'text',
            'premium_access_hours' => 'hours',
            'publisher_fee_percent' => 'percent',
        ];

        try {
            $input = $_POST['settings'] ?? [];
            if (!is_array($input) || $input === []) {
                throw new \InvalidArgumentException('Brak ustawień do zapisania.');
            }
            $updates = [];
            foreach ($input as $name => $rawValue) {
                if (!isset($allowed[$name])) {
                    throw new \InvalidArgumentException('Tego ustawienia nie można zmieniać w formularzu ogólnym: ' . $name);
                }
                $value = trim((string)$rawValue);
                if ($allowed[$name] === 'text') {
                    if ($value === '') {
                        throw new \InvalidArgumentException('Ustawienie ' . $name . ' nie może być puste.');
                    }
                    $value = mb_substr($value, 0, 255);
                } elseif (!is_numeric($value)) {
                    throw new \InvalidArgumentException('Ustawienie ' . $name . ' musi być liczbą.');
                } else {
                    $number = (int)$value;
                    if ($allowed[$name] === 'hours' && ($number < 1 || $number > 8760)) {
                        throw new \InvalidArgumentException('Czas dostępu premium musi mieścić się w zakresie 1–8760 godzin.');
                    }
                    if ($allowed[$name] === 'percent' && ($number < 0 || $number > 100)) {
                        throw new \InvalidArgumentException('Udział serwisu musi mieścić się w zakresie 0–100%.');
                    }
                    $value = (string)$number;
                }
                $updates[$name] = $value;
            }

            $beforeRows = $this->app->db->all(
                'SELECT name,value FROM settings WHERE name IN ('
                . implode(',', array_fill(0, count($updates), '?')) . ') ORDER BY name',
                array_keys($updates)
            );
            $before = [];
            foreach ($beforeRows as $beforeRow) {
                $before[(string)$beforeRow['name']] = (string)$beforeRow['value'];
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'site_settings.update',
                'settings_group',
                'site',
                ['keys' => array_keys($updates)],
                $before,
                $updates,
            );

            $this->app->db->transaction(function ($db) use ($updates): void {
                foreach ($updates as $name => $value) {
                    $db->query('UPDATE settings SET value=:v, updated_at=NOW() WHERE name=:n', ['v' => $value, 'n' => $name]);
                }
            });
            $this->app->cache->flushGroup('site_settings');
            $this->app->session->flash('success', 'Ustawienia zostały zapisane.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać ustawień: ' . $e->getMessage());
        }
        redirect('/admin/settings');
    }


    public function updateSnajperSettings(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $submitted = is_array($_POST['snajper'] ?? null) ? $_POST['snajper'] : [];
            $this->authorizeCriticalOperation(
                $adminId,
                'security.snajper_settings.update',
                'settings_group',
                'slowo_snajper',
                ['keys' => array_keys($submitted)],
                $this->slowoSnajperConfig()->all(),
                $submitted,
            );
            $this->slowoSnajperConfig()->saveFromAdmin($_POST['snajper'] ?? []);
            $this->slowoSnajper()->audit($adminId, 'slowo_snajper_settings_update', ['keys' => array_keys($_POST['snajper'] ?? [])]);
            $this->app->session->flash('success', 'Ustawienia SNAJPERA SŁOWA zostały zapisane.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać SNAJPERA SŁOWA: ' . $e->getMessage());
        }
        redirect('/admin/settings#slowo-snajper');
    }

    public function updateTalentRules(): never
    {
        $adminId = $this->requireAdmin();
        $talentService = new \App\Services\TalentService($this->app->db, new \App\Services\LedgerService($this->app->db, new \App\Services\FinancialService($this->app->db)));
        try {
            $rules = $_POST['rules'] ?? [];
            if (!is_array($rules) || $rules === []) {
                throw new \InvalidArgumentException('Brak reguł Talentu do zapisania.');
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'earnings.rules.update',
                'settings_group',
                'talent_rules',
                ['rule_types' => array_keys($rules)],
                ['rules' => $talentService->getRules()],
                ['submitted_rules' => $rules],
            );
            $this->app->db->transaction(function () use ($rules, $talentService): void {
                foreach ($rules as $type => $data) {
                    if (!is_array($data)) {
                        throw new \InvalidArgumentException('Nieprawidłowe dane reguły Talentu.');
                    }
                    $money = str_replace([' ', ','], ['', '.'], trim((string)($data['money'] ?? '0')));
                    if (!is_numeric($money)) {
                        throw new \InvalidArgumentException('Kwota reguły Talentu musi być liczbą.');
                    }
                    $talentService->updateRule(
                        (string)$type,
                        (int)($data['points'] ?? 0),
                        (int)round(((float)$money) * 100),
                        (int)($data['limit'] ?? 0),
                        isset($data['active']),
                        (int)($data['submission_deposit_points'] ?? 0)
                    );
                }
            });
            $this->app->session->flash('success', 'Reguły Talentu zostały zapisane.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Nie udało się zapisać reguł Talentu: ' . $e->getMessage());
        }
        redirect('/admin/settings');
    }

    public function updateTalentPromotion(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $input = is_array($_POST['promotion'] ?? null) ? $_POST['promotion'] : [];
            if ($input === []) {
                throw new \InvalidArgumentException('Brak danych promocji Talent.');
            }
            $service = $this->appReferralService();
            $before = $service->latestPromotion();
            $this->authorizeCriticalOperation(
                $adminId,
                'earnings.app_referral_promotion.update',
                'talent_promotion',
                \App\Services\AppReferralService::PROMOTION_CODE,
                ['snapshot_policy' => 'reward_points zapisane w zaproszeniu nie są zmieniane'],
                $before,
                ['submitted_promotion' => $input],
            );
            $this->app->db->transaction(function () use ($service, $adminId, $input): void {
                $service->updatePromotion($adminId, $input);
            });
            $this->app->session->flash('success', 'Promocja instalacyjna Talent została zapisana. Istniejące zaproszenia zachowały własną wartość TT.');
        } catch (\Throwable $error) {
            $this->app->session->flash('error', 'Nie udało się zapisać promocji Talent: ' . $error->getMessage());
        }
        redirect('/admin/settings#talent-promotion');
    }

    private function mobileApprovalEnabled(string $flag): bool
    {
        $mobile = $this->app->config['dors3']['mobile'] ?? null;
        return is_array($mobile)
            && \App\Security\Dors3\MobileApprovalConfiguration::isEnabled($mobile, 'admin', $flag);
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

    public function manualTalentReward(): never
    {
        $adminId = $this->requireAdmin();
        $userId = (int)($_POST['user_id'] ?? 0);
        $points = (int)($_POST['points'] ?? 0);
        $description = trim($_POST['description'] ?? 'Ręczna korekta admina');
        
        try {
            $financialService = new \App\Services\FinancialService($this->app->db);
            $wallet = $this->app->db->one('SELECT id,points_balance FROM wallets WHERE user_id=:id', ['id' => $userId]);
            if ($wallet === null) {
                throw new \RuntimeException('Nie znaleziono portfela użytkownika.');
            }
            $this->authorizeCriticalOperation(
                $adminId,
                'wallet.manual_talent_reward.request',
                'wallet',
                (string)$wallet['id'],
                [
                    'recipient_user_id' => $userId,
                    'points' => $points,
                    'description' => $description,
                ],
                ['points_balance' => (int)$wallet['points_balance']],
                ['points_balance' => (int)$wallet['points_balance'] + $points],
            );

            $financialService->requestApproval(
                'manual_reward',
                $points,
                'TT',
                (int)($wallet['id'] ?? 0),
                $userId,
                ['account_type' => 'points', 'points' => $points, 'description' => $description],
                "Ręczne naliczenie Talentów (+$points TT). Powód: $description"
            );

            $this->app->session->flash('last_user_id', $userId);
            $this->app->session->flash('info', 'Zlecenie naliczenia Talentów zostało utworzone (Maker-Checker).');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Błąd: ' . $e->getMessage());
        }

        redirect('/admin/users#user-' . $userId);
    }
}
