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
use App\Services\BugReportService;
use App\Services\UploadService;

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
            'title' => t('layout.menu.admin'),
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
            $this->json(['success' => true, 'message' => t('controller.admin.cache_strony_wyczyszczony')]);
        }
        $_SESSION['flash_success'] = 'Cache strony wyczyszczony.';

        header('Location: /admin');
        exit;
    }

    private function cacheClearFailure(bool $ajax, \Throwable $error): never
    {
        if ($ajax) {
            $this->json(['success' => false, 'message' => t('controller.admin.nie_udao_sie_wyczyscic_cache')]);
        }
        $_SESSION['flash_error'] = $this->safeError($error, t('controller.admin.nie_udao_sie_wyczyscic_cache'), 'admin_cache');
        header('Location: /admin');
        exit;
    }

    public function articles(): string
    {
        $this->requireAdmin();
        [$page, $limit, $offset] = $this->slowoSnajper()->pageLimitOffset('admin_articles', $_GET['page'] ?? 1, 50, 200);
        $articles = (new ArticleService($this->app->db))->allForAdmin($limit, $offset, 'submitted');

        return $this->view('admin/articles', [
            'title' => t('controller.admin.redakcja_gowna'),
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
            'title' => t('controller.admin.wydawca'),
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
            return $this->view('layouts/error', ['title' => '404', 'message' => t('controller.admin.nie_znaleziono_tekstu')]);
        }

        $translationService = new ArticleTranslationService($this->app->db, $this->app->config['languages'] ?? []);
        $currentRoles = $this->currentUserRoles();

        return $this->view('admin/editorial_edit', [
            'title' => t('controller.admin.edycja_tekstu') . $article['title'],
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
                throw new \InvalidArgumentException(t('controller.admin.nieprawidowy_jezyk_oryginau'));
            }

            $languageVersions = isset($_POST['language_versions']) && is_array($_POST['language_versions'])
                ? $_POST['language_versions']
                : [];
            $editorialData = $_POST;
            if ($languageVersions !== []) {
                $sourceVersion = $languageVersions[$sourceLanguage] ?? null;
                if (!is_array($sourceVersion)) {
                    throw new \InvalidArgumentException(t('controller.admin.brak_tresci_dla_wybranego_jezyka_oryginau'));
                }

                $sourceTitle = trim(is_scalar($sourceVersion['title'] ?? null) ? (string)$sourceVersion['title'] : '');
                $sourceLead = trim(is_scalar($sourceVersion['lead'] ?? null) ? (string)$sourceVersion['lead'] : '');
                $sourceBody = trim(is_scalar($sourceVersion['body'] ?? null) ? (string)$sourceVersion['body'] : '');
                if ($sourceTitle === '' || $sourceBody === '') {
                    throw new \InvalidArgumentException(t('controller.admin.tytu_i_tresc_w_jezyku_oryginau_sa_wymagane'));
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
                            str_replace('{language}', strtoupper($language), t('controller.admin.translation_incomplete'))
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
                throw new \RuntimeException(t('controller.admin.nie_znaleziono_tekstu'));
            }

            $translationService = new ArticleTranslationService($this->app->db, $this->app->config['languages'] ?? []);
            $translationService->saveEditorialVersion($articleId, $language, [
                'source_language' => (string)($article['source_language'] ?? 'pl'),
                'title' => (string)($_POST['title'] ?? ''),
                'lead' => (string)($_POST['lead'] ?? ''),
                'body' => (string)($_POST['body'] ?? ''),
                'translation_instructions' => (string)($_POST['translation_instructions'] ?? ''),
            ], $adminId);

            $this->app->session->flash('success', t('admin.main_banner.wersja_jezykowa') . strtoupper($language) . t('controller.admin.zostaa_zapisana'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.bad_zapisu_wersji_jezykowej') . $e->getMessage());
        }

        redirect('/admin/editorial/edit?id=' . $articleId . '#translations');
    }

    public function saveEditorialOrder(): string
    {
        $this->requireAdminOrRoles([RoleService::ROLE_EDITOR, RoleService::ROLE_PUBLISHER]);
        $order = $_POST['order'] ?? []; // Array of article IDs in order
        if (!is_array($order) || $order === []) {
            if ($this->isAjax()) { header('Content-Type: application/json'); }
            return json_encode(['success' => false, 'message' => t('controller.admin.brak_tekstow_do_zapisania_kolejnosci')]);
        }

        try {
            $db = $this->app->db;
            $order = array_map('intval', $order);
            if (in_array(0, $order, true) || count($order) !== count(array_unique($order))) {
                throw new \InvalidArgumentException(t('controller.admin.lista_kolejnosci_zawiera_nieprawidowe_albo_powtorzone_i_82af7f0e'));
            }
            $idsSql = implode(',', $order);
            $existingCount = (int)$db->cell('SELECT COUNT(*) FROM articles WHERE id IN (' . $idsSql . ')');
            if ($existingCount !== count($order)) {
                throw new \RuntimeException(t('controller.admin.co_najmniej_jeden_tekst_z_listy_juz_nie_istnieje_odswiez_panel'));
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
                throw new \RuntimeException(t('controller.admin.nie_znaleziono_tekstu'));
            }
            if (!isset($_POST['is_featured']) || !in_array((string)$_POST['is_featured'], ['0', '1'], true)) {
                throw new \InvalidArgumentException(t('controller.admin.nieprawidowy_stan_promocji_tekstu'));
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
            return $this->view('layouts/error', ['title' => '404', 'message' => t('controller.admin.nie_znaleziono_tekstu_do_korekty')]);
        }

        return $this->view('admin/proofreader_edit', [
            'title' => t('controller.admin.korekta_tekstu') . $article['title'],
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
            $this->app->session->flash('success', t('controller.admin.korekta_zostaa_zapisana_tekst_oznaczono_jako_korekta'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_korekty') . $e->getMessage());
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
            $msg = t('controller.admin.status_tekstu_zosta_zmieniony_na') . ($labels[$status] ?? $status) . '.';

            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg]);
            }

            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) {
            $error = t('controller.admin.nie_udao_sie_zmienic_statusu_tekstu') . $e->getMessage();
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
            $msg = t('controller.admin.tekst_zosta_skierowany_do_korekty');
            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) {
            $error = t('controller.admin.nie_udao_sie_skierowac_tekstu_do_korekty') . $e->getMessage();
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
            $msg = t('controller.admin.dane_artykuu_zostay_zaktualizowane_wycena_etykieta_i_us_a154bf9a');
            if ($this->isAjax()) {
                $this->json(['success' => true, 'message' => $msg]);
            }
            $this->app->session->flash('success', $msg);
        } catch (\Throwable $e) {
            $error = t('controller.admin.nie_udao_sie_zapisac_wyceny_tekstu') . $e->getMessage();
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


    public function surveys(): never
    {
        $this->requireAdmin();
        $suffix = isset($_GET['id']) ? '&survey_id=' . (int)$_GET['id'] : '';
        redirect('/admin/campaigns?tab=survey' . $suffix);
    }

    public function createSurvey(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $id = (new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig())))->createSurvey($adminId, $_POST);
            $this->app->session->flash('success', t('controller.admin.ankieta_zostaa_utworzona_dodaj_pytania_i_ustaw_status_a_e09cf606'));
            redirect('/admin/campaigns?tab=survey&survey_id=' . $id);
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_utworzyc_ankiety') . $e->getMessage());
            redirect('/admin/campaigns?tab=survey');
        }
    }

    public function updateSurvey(): never
    {
        $this->requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        try {
            (new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig())))->updateSurvey($id, $_POST);
            $this->app->session->flash('success', t('controller.admin.ankieta_zostaa_zapisana'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_ankiety') . $e->getMessage());
        }
        redirect('/admin/campaigns?tab=survey&survey_id=' . $id);
    }

    public function addSurveyQuestion(): never
    {
        $this->requireAdmin();
        $surveyId = (int)($_POST['survey_id'] ?? 0);
        try {
            (new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig())))->addQuestion($surveyId, $_POST);
            $this->app->session->flash('success', t('controller.admin.pytanie_zostao_dodane'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_dodac_pytania') . $e->getMessage());
        }
        redirect('/admin/campaigns?tab=survey&survey_id=' . $surveyId);
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
            $this->app->session->flash('success', t('controller.admin.pytanie_zostao_usuniete'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_usunac_pytania') . $e->getMessage());
        }
        redirect('/admin/campaigns?tab=survey&survey_id=' . $surveyId);
    }

    public function surveyReport(): string
    {
        $this->requireAdmin();
        try {
            $report = (new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig())))->responseReport((int)($_GET['id'] ?? 0));
        } catch (\RuntimeException) {
            http_response_code(404);
            return $this->view('layouts/error', [
                'title' => t('controller.admin.nie_znaleziono_ankiety'),
                'message' => t('controller.admin.wybrana_ankieta_nie_istnieje_albo_zostaa_usunieta'),
            ]);
        }
        return $this->view('admin/survey_report', [
            'title' => t('admin.survey_report.raport_ankiety'),
            'survey' => $report['survey'],
            'questions' => $report['questions'],
            'responses' => $report['responses'],
            'summary' => $report['summary'],
            'survey_reward_points' => (int)($this->app->db->cell("SELECT CASE WHEN is_active=1 THEN points_amount ELSE 0 END FROM activity_reward_rules WHERE activity_type='survey_reward' LIMIT 1") ?: 0),
        ]);
    }


    public function campaigns(): string
    {
        $this->requireAdmin();
        $service = $this->campaignService();
        $surveyService = new SurveyService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig()));
        $tab = (string)($_GET['tab'] ?? 'banner');
        if (!in_array($tab, ['banner','video','article','survey','bugs'], true)) {
            $tab = 'banner';
        }
        $selectedSurveyId = (int)($_GET['survey_id'] ?? 0);
        return $this->view('admin/campaigns', [
            'title' => t('controller.admin.kampanie_i_zaangazowanie'),
            'campaigns' => $service->allForAdmin(...array_slice($this->slowoSnajper()->pageLimitOffset('admin_campaigns', $_GET['page'] ?? 1, 50, 200), 1)),
            'types' => $service->types(),
            'type_definitions' => $service->typeDefinitions(),
            'statuses' => $service->statuses(),
            'placements' => $service->placements(),
            'selected_campaign' => isset($_GET['id']) ? $service->find((int)$_GET['id']) : null,
            'campaign_tab' => $tab,
            'surveys' => $surveyService->allForAdmin(200),
            'survey_types' => $surveyService->types(),
            'selected_survey' => $selectedSurveyId > 0 ? $surveyService->find($selectedSurveyId) : null,
            'selected_questions' => $selectedSurveyId > 0 ? $surveyService->questions($selectedSurveyId) : [],
            'published_articles' => $this->app->db->all(
                "SELECT id,title,published_at FROM articles WHERE status='published' AND revision_of_article_id IS NULL ORDER BY published_at DESC,id DESC LIMIT 300"
            ),
            'bug_reports' => (new BugReportService($this->app->db, $this->talentService()))->allForAdmin(),
        ]);
    }

    public function createCampaign(): never
    {
        $adminId = $this->requireAdmin();
        $upload = new UploadService($this->app->db, $this->app->objectStorage);
        $creative = null;
        try {
            $data = $_POST;
            if (isset($_FILES['creative']) && (int)($_FILES['creative']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $creative = $upload->uploadCampaignCreative($_FILES['creative'], (string)($data['type'] ?? ''));
                $data['creative_path'] = $creative['path'];
                $data['creative_mime'] = $creative['mime'];
            }
            $id = $this->campaignService()->create($adminId, $data);
            $this->app->session->flash('success', t('controller.admin.kampania_zostaa_utworzona'));
            redirect('/admin/campaigns?tab=' . urlencode($this->campaignTab((string)($data['type'] ?? ''))) . '&id=' . $id);
        } catch (\Throwable $e) {
            if (is_array($creative)) {
                try { $upload->deleteReference((string)$creative['path']); } catch (\Throwable) {}
            }
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_utworzyc_kampanii') . $e->getMessage());
            redirect('/admin/campaigns?tab=' . urlencode($this->campaignTab((string)($_POST['type'] ?? ''))));
        }
    }

    public function updateCampaign(): never
    {
        $adminId = $this->requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        $service = $this->campaignService();
        $before = $service->find($id);
        $upload = new UploadService($this->app->db, $this->app->objectStorage);
        $creative = null;
        try {
            $data = $_POST;
            if (isset($_FILES['creative']) && (int)($_FILES['creative']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $creative = $upload->uploadCampaignCreative($_FILES['creative'], (string)($data['type'] ?? ''));
                $data['creative_path'] = $creative['path'];
                $data['creative_mime'] = $creative['mime'];
            }
            $service->update($id, $adminId, $data);
            if (is_array($creative) && !empty($before['creative_path']) && $before['creative_path'] !== $creative['path']) {
                try { $upload->deleteReference((string)$before['creative_path']); } catch (\Throwable) {}
            }
            $this->app->session->flash('success', t('controller.admin.kampania_zostaa_zapisana'));
        } catch (\Throwable $e) {
            if (is_array($creative)) {
                try { $upload->deleteReference((string)$creative['path']); } catch (\Throwable) {}
            }
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_kampanii') . $e->getMessage());
        }
        redirect('/admin/campaigns?tab=' . urlencode($this->campaignTab((string)($_POST['type'] ?? ''))) . '&id=' . $id);
    }

    public function campaignReport(): string
    {
        $this->requireAdmin();
        try {
            $report = $this->campaignService()->report((int)($_GET['id'] ?? 0));
        } catch (\RuntimeException) {
            http_response_code(404);
            return $this->view('layouts/error', [
                'title' => t('ui.donations.campaign.nie_znaleziono_kampanii'),
                'message' => t('controller.admin.wybrana_kampania_nie_istnieje_albo_zostaa_usunieta'),
            ]);
        }
        return $this->view('admin/campaign_report', [
            'title' => t('controller.admin.raport_kampanii'),
            'campaign' => $report['campaign'],
            'events' => $report['events'],
            'recent' => $report['recent'],
            'summary' => $report['summary'],
        ]);
    }

    private function campaignService(): CampaignService
    {
        return new CampaignService($this->app->db, $this->talentService(), new FraudGuardService($this->app->db, $this->slowoSnajperConfig()));
    }

    public function reviewBugReport(): never
    {
        $adminId = $this->requireAdminOrRoles(['editor','publisher','moderator','chief_editor','redaktor_naczelny','wydawca']);
        $reportId = (int)($_POST['id'] ?? 0);
        try {
            $service = new BugReportService($this->app->db, $this->talentService());
            if ((string)($_POST['decision'] ?? '') === 'accept') {
                $result = $service->accept($reportId, $adminId, (string)($_POST['note'] ?? ''));
                $this->app->session->flash('success', !empty($result['duplicate']) ? t('controller.admin.to_zgoszenie_byo_juz_zaakceptowane') : t('controller.admin.bad_zaakceptowano_a_tt_przekazano_do_programu_talent'));
            } else {
                $service->reject($reportId, $adminId, (string)($_POST['note'] ?? ''));
                $this->app->session->flash('success', t('controller.admin.zgoszenie_zostao_odrzucone_bez_naliczenia_tt'));
            }
        } catch (\Throwable $error) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_rozpatrzyc_zgoszenia') . $error->getMessage());
        }
        redirect((string)($_POST['return_to'] ?? '') === 'bug_reports' ? '/admin/bug-reports' : '/admin/campaigns?tab=bugs');
    }

    public function bugReports(): string
    {
        $this->requireAdminOrRoles(['editor','publisher','moderator','chief_editor','redaktor_naczelny','wydawca']);
        return $this->view('admin/bug_reports', [
            'title' => t('admin.bug_reports.zgoszenia_bedow'),
            'bug_reports' => (new BugReportService($this->app->db, $this->talentService()))->allForAdmin(),
            'bug_report_return_to' => 'bug_reports',
        ]);
    }

    private function campaignTab(string $type): string
    {
        return match ($type) {
            'ad_view' => 'video',
            'sponsored_article' => 'article',
            'survey_ad' => 'survey',
            default => 'banner',
        };
    }

    public function payouts(): string
    {
        $this->requireAdmin();
        $service = new PayoutService($this->app->db, new FraudGuardService($this->app->db, $this->slowoSnajperConfig()));
        return $this->view('admin/payouts', [
            'title' => t('wallet.payout_active'),
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
            if (!$payout) throw new \RuntimeException(t('controller.admin.wypata_nie_istnieje'));
            if (!in_array($status, [
                PayoutService::STATUS_APPROVED,
                PayoutService::STATUS_PAID,
                PayoutService::STATUS_REJECTED,
                PayoutService::STATUS_CANCELLED,
            ], true)) {
                throw new \InvalidArgumentException(t('controller.admin.nieobsugiwany_status_wypaty'));
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
                str_replace(['{id}', '{status}', '{note}'], [(string)$id, (string)$status, (string)$note], t('controller.admin.payout_status_change'))
            );
            $this->app->session->flash('info', t('controller.admin.zlecenie_zmiany_statusu_wypaty_oczekuje_na_zatwierdzeni_c1edcc69'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zmienic_statusu_wypaty') . $e->getMessage());
        }
        redirect('/admin/payouts');
    }

    public function users(): string
    {
        $this->requireAdmin();
        return $this->view('admin/users', [
            'title' => t('admin.anti_fraud.uzytkownicy'),
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
                throw new \RuntimeException(t('controller.admin.nie_mozesz_zablokowac_ani_dezaktywowac_wasnego_konta_ad_a1687777'));
            }
            $beforeUser = $this->app->db->one('SELECT status FROM users WHERE id=:id', ['id' => $userId]);
            if ($beforeUser === null) {
                throw new \RuntimeException(t('controller.admin.nie_znaleziono_uzytkownika'));
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
            $this->app->session->flash('success', t('controller.admin.status_uzytkownika_zosta_zmieniony'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zmienic_statusu') . $e->getMessage());
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
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_przygotowac_raportu_uzytkownika') . $e->getMessage());
            redirect('/admin/users');
        }

        return $this->view('admin/user_delete', [
            'title' => t('admin.user_delete.bezpieczne_usuwanie_uzytkownika'),
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
                throw new \RuntimeException(t('controller.admin.nie_znaleziono_uzytkownika'));
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
            $this->app->session->flash('success', t('controller.admin.uzytkownik_zosta_dezaktywowany_i_zanonimizowany'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zanonimizowac_uzytkownika') . $e->getMessage());
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
                throw new \RuntimeException(t('controller.admin.twarde_czyszczenie_uzytkownika_jest_odpiete_w_snajperze_d3314da9'));
            }
            $beforeUser = $this->app->db->one('SELECT status,email,login_name FROM users WHERE id=:id', ['id' => $userId]);
            if ($beforeUser === null) {
                throw new \RuntimeException(t('controller.admin.nie_znaleziono_uzytkownika'));
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
            $this->app->session->flash('success', t('controller.admin.uzytkownik_zosta_twardo_wyczyszczony'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_wykonac_twardego_czyszczenia') . $e->getMessage());
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
                throw new \RuntimeException(t('controller.admin.nie_mozesz_odebrac_roli_administratora_wasnemu_kontu'));
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
            $this->app->session->flash('success', t('controller.admin.typ_konta_uzytkownika_zosta_zmieniony_bez_naruszania_ro_fd1d3160'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zmienic_typu_konta') . $e->getMessage());
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
                throw new \RuntimeException(t('controller.admin.nie_znaleziono_autora'));
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
            $this->app->session->flash('success', t('controller.admin.autor_zosta_zatwierdzony_waczono_zgode_pisanie'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zatwierdzic_autora') . $e->getMessage());
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
                throw new \RuntimeException(t('controller.admin.nie_znaleziono_uzytkownika'));
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
                $this->app->session->flash('success', t('controller.admin.zgody_operacyjne_bez_zmian'));
            } else {
                $labels = [
                    'can_write' => 'Pisanie',
                    'talent_enabled' => 'Talent',
                    'wallet_enabled' => 'Wallet',
                    'payout_enabled' => t('wallet.payout_active'),
                ];
                $changedLabels = [];
                foreach (array_keys($changed) as $key) {
                    $changedLabels[] = $labels[$key] ?? $key;
                }
                $this->app->session->flash('success', t('controller.admin.zapisano_zgody_operacyjne') . implode(', ', $changedLabels) . '.');
            }
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_zgod_operacyjnych') . $e->getMessage());
        }

        redirect('/admin/users#user-' . $userId);
    }


    public function roles(): string
    {
        $this->requireAdmin();
        [$page, $limit, $offset] = $this->slowoSnajper()->pageLimitOffset('admin_roles_users', $_GET['page'] ?? 1, 50, 200);
        $roleService = new RoleService($this->app->db);
        return $this->view('admin/roles', [
            'title' => t('controller.admin.role_i_uprawnienia_snajpera_sowa'),
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
            $this->app->session->flash('success', t('controller.admin.role_redakcyjne_zostay_zapisane_dostep_do_kafelkow_dzia_80cf7c36'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_rol_redakcyjnych') . $e->getMessage());
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
            $msg = $duration === 'clear' ? t('controller.admin.author_block_removed') : t('controller.admin.author_blocked');

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
                throw new \RuntimeException(t('controller.admin.nie_znaleziono_uzytkownika'));
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
            $this->app->session->flash('success', t('controller.admin.2fa_uzytkownika_zostao_wyaczone_konto_ma_wymuszona_pono_8cea434e'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_wyaczyc_2fa') . $e->getMessage());
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
                'title' => t('controller.admin.nie_znaleziono_kafelka'),
                'message' => t('controller.admin.ten_kafelek_snajpera_sowa_nie_istnieje'),
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
                'title' => t('controller.admin.brak_uprawnien'),
                'message' => t('controller.admin.nie_masz_przypisanej_roli_wymaganej_dla_tego_kafelka'),
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
            'title' => t('controller.admin.baner_gowny'),
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
            $this->app->session->flash('success', t('controller.admin.baner_gowny_zosta_zapisany'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_baneru_gownego') . $e->getMessage());
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
            $this->app->session->flash('success', t('controller.admin.tumaczenie_baneru_gownego_trafio_do_izolowanej_kolejki_ai'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_przetumaczyc_baneru_gownego_przez_ai') . $e->getMessage());
        }

        redirect('/admin/main-banner#translations');
    }

    public function categories(): string
    {
        $this->requireAdmin();
        return $this->view('admin/categories', [
            'title' => t('admin.categories.kategorie'),
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
            $this->app->session->flash('success', t('controller.admin.kategoria_zostaa_utworzona'));
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => t('controller.admin.kategoria_zostaa_utworzona')]);
                exit;
            }
        } catch (\Throwable $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => t('controller.admin.bad') . $e->getMessage()]);
                exit;
            }
            $this->app->session->flash('error', t('controller.admin.bad') . $e->getMessage());
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
                echo json_encode(['success' => true, 'message' => t('controller.admin.zmiany_w_kategorii_zostay_zapisane')]);
                exit;
            }
            $this->app->session->flash('success', t('controller.admin.zmiany_w_kategorii_zostay_zapisane'));
        } catch (\Throwable $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => t('controller.admin.bad') . $e->getMessage()]);
                exit;
            }
            $this->app->session->flash('error', t('controller.admin.bad') . $e->getMessage());
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
            $msg = t('controller.admin.nie_udao_sie_usunac_kategorii') . $e->getMessage();
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
                echo json_encode(['success' => true, 'message' => t('controller.admin.kategoria_zostaa_usunieta')]);
                exit;
            }
            $this->app->session->flash('success', t('controller.admin.kategoria_zostaa_usunieta'));
        } else {
            $msg = t('controller.admin.ta_kategoria_ma_przypisane_artykuy_mozesz_ja_ukryc_w_me_113cb07d');
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
        return $this->view('admin/mails', ['title'=>t('admin.mails.kolejka_maili'), 'mails'=>(new MailService($this->app->db))->recent()]);
    }


    public function antiFraud(): string
    {
        $adminId = $this->requireAdmin();
        $this->requireHighRoleSecurity($adminId, 'panelu ANTYFRAUD');

        [$page, $limit, $offset] = $this->slowoSnajper()->pageLimitOffset('admin_fraud_events', $_GET['page'] ?? 1, 50, 200);
        $guard = new FraudGuardService($this->app->db, $this->slowoSnajperConfig());
        $data = $guard->dashboard($limit);

        return $this->view('admin/anti_fraud', [
            'title' => t('controller.admin.antyfraud'),
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
            $this->app->session->flash('success', t('controller.admin.skan_antyfraudowy_zakonczony_sprawdzono') . (int)$result['scanned'] . t('controller.admin.oznaczono') . (int)$result['flagged'] . '.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_wykonac_skanu_antyfraudowego') . $e->getMessage());
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
            'title' => t('controller.admin.ustawienia_talent_i_snajper_sowa'),
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
                throw new \InvalidArgumentException(t('controller.admin.brak_ustawien_do_zapisania'));
            }
            $updates = [];
            foreach ($input as $name => $rawValue) {
                if (!isset($allowed[$name])) {
                    throw new \InvalidArgumentException(t('controller.admin.tego_ustawienia_nie_mozna_zmieniac_w_formularzu_ogolnym') . $name);
                }
                $value = trim((string)$rawValue);
                if ($allowed[$name] === 'text') {
                    if ($value === '') {
                        throw new \InvalidArgumentException(t('controller.admin.ustawienie') . $name . t('controller.admin.nie_moze_byc_puste'));
                    }
                    $value = mb_substr($value, 0, 255);
                } elseif (!is_numeric($value)) {
                    throw new \InvalidArgumentException(t('controller.admin.ustawienie') . $name . t('controller.admin.musi_byc_liczba'));
                } else {
                    $number = (int)$value;
                    if ($allowed[$name] === 'hours' && ($number < 1 || $number > 8760)) {
                        throw new \InvalidArgumentException(t('controller.admin.czas_dostepu_premium_musi_miescic_sie_w_zakresie_18760_godzin'));
                    }
                    if ($allowed[$name] === 'percent' && ($number < 0 || $number > 100)) {
                        throw new \InvalidArgumentException(t('controller.admin.udzia_serwisu_musi_miescic_sie_w_zakresie_0100'));
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
            $this->app->session->flash('success', t('account.settings.saved'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_ustawien') . $e->getMessage());
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
            $this->app->session->flash('success', t('controller.admin.ustawienia_snajpera_sowa_zostay_zapisane'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_snajpera_sowa') . $e->getMessage());
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
                throw new \InvalidArgumentException(t('controller.admin.brak_regu_talentu_do_zapisania'));
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
                        throw new \InvalidArgumentException(t('controller.admin.nieprawidowe_dane_reguy_talentu'));
                    }
                    $money = str_replace([' ', ','], ['', '.'], trim((string)($data['money'] ?? '0')));
                    if (!is_numeric($money)) {
                        throw new \InvalidArgumentException(t('controller.admin.kwota_reguy_talentu_musi_byc_liczba'));
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
            $this->app->session->flash('success', t('controller.admin.reguy_talentu_zostay_zapisane'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_regu_talentu') . $e->getMessage());
        }
        redirect('/admin/settings');
    }

    public function updateTalentPromotion(): never
    {
        $adminId = $this->requireAdmin();
        try {
            $input = is_array($_POST['promotion'] ?? null) ? $_POST['promotion'] : [];
            if ($input === []) {
                throw new \InvalidArgumentException(t('controller.admin.brak_danych_promocji_talent'));
            }
            $service = $this->appReferralService();
            $before = $service->latestPromotion();
            $this->authorizeCriticalOperation(
                $adminId,
                'earnings.app_referral_promotion.update',
                'talent_promotion',
                \App\Services\AppReferralService::PROMOTION_CODE,
                ['snapshot_policy' => t('controller.admin.reward_points_zapisane_w_zaproszeniu_nie_sa_zmieniane')],
                $before,
                ['submitted_promotion' => $input],
            );
            $this->app->db->transaction(function () use ($service, $adminId, $input): void {
                $service->updatePromotion($adminId, $input);
            });
            $this->app->session->flash('success', t('controller.admin.promocja_instalacyjna_talent_zostaa_zapisana_istniejace_aca2e516'));
        } catch (\Throwable $error) {
            $this->app->session->flash('error', t('controller.admin.nie_udao_sie_zapisac_promocji_talent') . $error->getMessage());
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
        $description = trim($_POST['description'] ?? t('controller.admin.reczna_korekta_admina'));
        
        try {
            $financialService = new \App\Services\FinancialService($this->app->db);
            $wallet = $this->app->db->one('SELECT id,points_balance FROM wallets WHERE user_id=:id', ['id' => $userId]);
            if ($wallet === null) {
                throw new \RuntimeException(t('controller.admin.nie_znaleziono_portfela_uzytkownika'));
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
                str_replace(['{points}', '{reason}'], [(string)$points, (string)$description], t('controller.admin.manual_talent_award'))
            );

            $this->app->session->flash('last_user_id', $userId);
            $this->app->session->flash('info', t('controller.admin.zlecenie_naliczenia_talentow_zostao_utworzone_maker_checker'));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', t('controller.admin.bad') . $e->getMessage());
        }

        redirect('/admin/users#user-' . $userId);
    }
}
