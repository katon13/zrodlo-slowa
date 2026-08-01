<?php
declare(strict_types=1);

$healthPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($healthPath === '/health/live' || $healthPath === '/health/ready') {
    $instanceId = trim((string)(getenv('APP_INSTANCE_ID') ?: 'unknown'));
    $payload = [
        'status' => 'ok',
        'service' => 'zrodlo-slowa-app',
        'instance' => $instanceId,
        'check' => $healthPath === '/health/live' ? 'liveness' : 'readiness',
        'stage' => 'scaling-ready',
        'timestamp' => gmdate('c'),
    ];
    $statusCode = 200;

    if ($healthPath === '/health/ready') {
        $checks = [
            'php' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'vendor' => is_file(__DIR__ . '/../vendor/autoload.php'),
        ];

        $databaseCheckEnabled = filter_var(
            getenv('HEALTHCHECK_DATABASE') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );
        if ($databaseCheckEnabled && $checks['pdo_pgsql']) {
            try {
                $host = (string)(getenv('DB_HOST') ?: 'postgres');
                $port = (string)(getenv('DB_PORT') ?: '5432');
                $name = (string)(getenv('DB_NAME') ?: 'zrodlo_slowa');
                $user = (string)(getenv('DB_USER') ?: 'zrodlo');
                $password = (string)(getenv('DB_PASS') ?: '');
                $sslMode = (string)(getenv('DB_SSLMODE') ?: 'disable');
                $pdo = new PDO(
                    "pgsql:host={$host};port={$port};dbname={$name};sslmode={$sslMode};connect_timeout=2",
                    $user,
                    $password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]
                );
                $checks['postgres'] = (int)$pdo->query('SELECT 1')->fetchColumn() === 1;
                $schema = (string)(getenv('DB_SCHEMA') ?: 'public');
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $schema) !== 1) {
                    throw new RuntimeException('Invalid DB_SCHEMA.');
                }
                $pdo->exec('SET search_path TO "' . str_replace('"', '""', $schema) . '"');
                $checks['schema'] = (int)$pdo->query(
                    "SELECT CASE WHEN to_regclass('users') IS NOT NULL THEN 1 ELSE 0 END"
                )->fetchColumn() === 1;
            } catch (Throwable) {
                $checks['postgres'] = false;
                $checks['schema'] = false;
            }
        }

        $valkeyCheckEnabled = filter_var(
            getenv('HEALTHCHECK_VALKEY') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );
        if ($valkeyCheckEnabled) {
            $checks['php_redis'] = extension_loaded('redis') && class_exists('Redis');
            $checks['valkey'] = false;
            if ($checks['php_redis']) {
                try {
                    $host = (string)(getenv('VALKEY_HOST') ?: 'valkey');
                    if (filter_var(getenv('VALKEY_TLS') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
                        $host = 'tls://' . preg_replace('#^tls://#', '', $host);
                    }
                    $redis = new Redis();
                    $connected = $redis->connect(
                        $host,
                        (int)(getenv('VALKEY_PORT') ?: 6379),
                        max(0.05, (float)(getenv('VALKEY_CONNECT_TIMEOUT') ?: 0.5)),
                        null,
                        100,
                        max(0.05, (float)(getenv('VALKEY_READ_TIMEOUT') ?: 0.5))
                    );
                    $password = (string)(getenv('VALKEY_PASSWORD') ?: '');
                    if ($connected && $password !== '') {
                        $connected = $redis->auth($password);
                    }
                    $database = max(0, (int)(getenv('VALKEY_DATABASE') ?: 0));
                    if ($connected && $database > 0) {
                        $connected = $redis->select($database);
                    }
                    $pong = $connected ? $redis->ping() : false;
                    $checks['valkey'] = $pong === true || strtoupper((string)$pong) === 'PONG';
                    $redis->close();
                } catch (Throwable) {
                    $checks['valkey'] = false;
                }
            }
        }

        $objectStorageCheckEnabled = filter_var(
            getenv('HEALTHCHECK_OBJECT_STORAGE') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );
        if ($objectStorageCheckEnabled) {
            $checks['object_storage_sdk'] = false;
            $checks['object_storage'] = false;
            try {
                if ($checks['vendor']) {
                    require_once __DIR__ . '/../vendor/autoload.php';
                }
                $checks['object_storage_sdk'] = class_exists(\Aws\S3\S3Client::class);
                if ($checks['object_storage_sdk']) {
                    $options = [
                        'version' => 'latest',
                        'region' => (string)(getenv('S3_REGION') ?: 'us-east-1'),
                        'use_path_style_endpoint' => filter_var(
                            getenv('S3_PATH_STYLE') ?: 'false',
                            FILTER_VALIDATE_BOOLEAN
                        ),
                        'retries' => 0,
                        'http' => [
                            'connect_timeout' => 1.0,
                            'timeout' => 2.0,
                        ],
                    ];
                    $endpoint = trim((string)(getenv('S3_ENDPOINT') ?: ''));
                    if ($endpoint !== '') {
                        $options['endpoint'] = $endpoint;
                    }
                    $accessKey = trim((string)(getenv('S3_ACCESS_KEY') ?: ''));
                    $secretKey = (string)(getenv('S3_SECRET_KEY') ?: '');
                    if ($accessKey !== '' && $secretKey !== '') {
                        $options['credentials'] = [
                            'key' => $accessKey,
                            'secret' => $secretKey,
                        ];
                    }
                    $s3 = new \Aws\S3\S3Client($options);
                    $s3->headBucket([
                        'Bucket' => (string)(getenv('S3_BUCKET') ?: ''),
                    ]);
                    $checks['object_storage'] = true;
                }
            } catch (Throwable) {
                $checks['object_storage'] = false;
            }
        }

        $ready = !in_array(false, $checks, true);
        $payload['status'] = $ready ? 'ok' : 'not_ready';
        $payload['checks'] = $checks;
        $payload['schema'] = ($checks['schema'] ?? false) ? 'postgresql' : 'not_installed';
        $statusCode = $ready ? 200 : 503;
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-App-Instance: ' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $instanceId));
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/../app/Core/bootstrap.php';

$objectPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (
    is_string($objectPath)
    && preg_match('~^/objects/([A-Za-z0-9_-]+)$~D', $objectPath, $objectMatch) === 1
) {
    try {
        $storageConfig = require __DIR__ . '/../config/storage.php';
        $storage = \App\Infrastructure\Storage\ObjectStorageFactory::create(dirname(__DIR__), $storageConfig);
        (new \App\Services\PublicObjectResponder($storage))->send((string)$objectMatch[1]);
    } catch (\Throwable $error) {
        error_log('Object storage public route initialization failed: ' . $error->getMessage());
        http_response_code(503);
        header('Retry-After: 5');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        exit;
    }
}

use App\Core\App;
use App\Core\Router;
use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\ArticleController;
use App\Controllers\SurveyController;
use App\Controllers\CampaignController;
use App\Controllers\ActivityController;
use App\Controllers\AuthorController;
use App\Controllers\ReaderController;
use App\Controllers\WalletController;
use App\Controllers\DonationController;
use App\Controllers\Dors3AdminController;
use App\Controllers\AdminController;
use App\Controllers\AccountSecurityController;
use App\Controllers\FinanceController;
use App\Controllers\PaymentWebhookController;
use App\Controllers\WalletTopupController;
use App\Controllers\WalletTransferController;
use App\Controllers\StripeWebhookController;
use App\Controllers\AiAdminController;
use App\Controllers\AdminArticleTranslationController;
use App\Controllers\SitemapController;
use App\Controllers\AccountController;
use App\Controllers\OAuthController;
use App\Controllers\EarningsApiController;

$rootPath = dirname(__DIR__);

$app = App::boot($rootPath);
$router = new Router($app);

$router->get('/', [HomeController::class, 'index']);
$router->get('/jak-zarabiac', [HomeController::class, 'economy']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/login/2fa', [AuthController::class, 'showTwoFactorChallenge']);
$router->post('/login/2fa', [AuthController::class, 'verifyTwoFactorChallenge']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/password/forgot', [AuthController::class, 'showForgot']);
$router->post('/password/forgot', [AuthController::class, 'forgot']);
$router->get('/password/reset', [AuthController::class, 'showReset']);
$router->post('/password/reset', [AuthController::class, 'reset']);

$router->get('/auth/google', [OAuthController::class, 'googleRedirect']);
$router->get('/auth/google/callback', [OAuthController::class, 'googleCallback']);
$router->get('/auth/apple', [OAuthController::class, 'appleRedirect']);
$router->post('/auth/apple/callback', [OAuthController::class, 'appleCallback'], ['csrf' => false]);

$router->get('/sitemap.xml', [SitemapController::class, 'index']);
$router->get('/articles', [ArticleController::class, 'index']);
$router->get('/article', [ArticleController::class, 'show']);
$router->post('/article/support', [ArticleController::class, 'support']);
$router->post('/article/buy', [ArticleController::class, 'buy']);
$router->get('/surveys', [SurveyController::class, 'index']);
$router->get('/survey', [SurveyController::class, 'show']);
$router->post('/survey/submit', [SurveyController::class, 'submit']);
$router->get('/campaigns', [CampaignController::class, 'index']);
$router->get('/campaign', [CampaignController::class, 'show']);
$router->post('/campaign/view', [CampaignController::class, 'viewAd']);
$router->post('/campaign/click', [CampaignController::class, 'clickAd']);
$router->post('/campaign/sponsored-read', [CampaignController::class, 'sponsoredRead']);
$router->post('/campaign/ppv', [CampaignController::class, 'ppv']);
$router->post('/campaign/live-join', [CampaignController::class, 'liveJoin']);
$router->post('/activity/record', [ActivityController::class, 'record']);
$router->post('/api/earnings/presence', [EarningsApiController::class, 'presence']);
$router->get('/api/earnings/notifications', [EarningsApiController::class, 'notifications']);
$router->post('/api/earnings/notifications/ack', [EarningsApiController::class, 'acknowledgeNotifications']);
$router->get('/api/earnings/jobs/status', [EarningsApiController::class, 'jobStatus']);
$router->post('/api/earnings/article-read', [EarningsApiController::class, 'articleRead']);

$router->get('/authors', [AuthorController::class, 'authorsShortcut']);
$router->get('/author', [AuthorController::class, 'dashboard']);
$router->get('/author/articles/create', [AuthorController::class, 'createArticle']);
$router->post('/author/articles', [AuthorController::class, 'storeArticle']);
$router->get('/author/articles/edit', [AuthorController::class, 'editArticle']);
$router->post('/author/articles/update', [AuthorController::class, 'updateArticle']);
$router->post('/author/articles/submit', [AuthorController::class, 'submitArticle']);
$router->post('/author/articles/upload-image', [AuthorController::class, 'uploadImageAjax']);
$router->post('/author/articles/delete-image', [AuthorController::class, 'deleteImageAjax']);
$router->post('/author/media/update-position', [AuthorController::class, 'updateImagePositionAjax']);

$router->get('/reader', [ReaderController::class, 'dashboard']);
$router->get('/account/settings', [AccountController::class, 'showSettings']);
$router->post('/account/settings', [AccountController::class, 'updateSettings']);
$router->post('/account/avatar', [AccountController::class, 'updateAvatar']);
$router->get('/account/security', [AccountSecurityController::class, 'show']);
$router->post('/account/security/email', [AccountSecurityController::class, 'sendEmailVerification']);
$router->get('/email/verify', [AccountSecurityController::class, 'verifyEmail']);
$router->post('/account/security/2fa/start', [AccountSecurityController::class, 'start2fa']);
$router->post('/account/security/2fa/enable', [AccountSecurityController::class, 'enable2fa']);

$router->get('/wallet', [WalletController::class, 'show']);
$router->get('/wallet/topup', [WalletTopupController::class, 'index']);
$router->post('/wallet/topup', [WalletTopupController::class, 'create']);
$router->get('/wallet/topup/success', [WalletTopupController::class, 'success']);
$router->get('/wallet/topup/cancel', [WalletTopupController::class, 'cancel']);
$router->post('/stripe/webhook', [StripeWebhookController::class, 'handle'], ['csrf' => false]);
$router->post('/wallet/transfer/talent-to-pln', [WalletTransferController::class, 'talentToPln']);
$router->post('/wallet/payout-methods', [WalletController::class, 'createPayoutMethod']);
$router->post('/wallet/payout-request', [WalletController::class, 'requestPayout']);

$router->get('/donations', [DonationController::class, 'campaign']);
$router->post('/donations/manual', [DonationController::class, 'manualDonation']);

$router->get('/admin', [AdminController::class, 'dashboard']);
$router->get('/admin/security/3dors', [Dors3AdminController::class, 'index']);
$router->get('/admin/security/unlock', [Dors3AdminController::class, 'showUnlock']);
$router->post('/admin/security/unlock', [Dors3AdminController::class, 'unlock']);
$router->post('/admin/security/3dors/recovery/generate', [Dors3AdminController::class, 'generateRecoveryCodes']);
$router->post('/admin/security/3dors/recovery/confirm', [Dors3AdminController::class, 'confirmRecoveryCodes']);
$router->post('/admin/cache/clear', [AdminController::class, 'clearCache']);
$router->get('/admin/articles', [AdminController::class, 'articles']);
$router->get('/admin/editorial', [AdminController::class, 'editorial']);
$router->get('/admin/editorial/edit', [AdminController::class, 'editEditorialArticle']);
$router->post('/admin/editorial/update', [AdminController::class, 'updateEditorialArticle']);
$router->post('/admin/editorial/translations/save', [AdminController::class, 'saveEditorialTranslation']);
$router->post('/admin/editorial/save-order', [AdminController::class, 'saveEditorialOrder']);
$router->post('/admin/editorial/toggle-featured', [AdminController::class, 'toggleFeatured']);
$router->post('/admin/articles/status', [AdminController::class, 'setArticleStatus']);
$router->post('/admin/articles/to-proofreading', [AdminController::class, 'sendArticleToProofreading']);
$router->post('/admin/authors/submit-block', [AdminController::class, 'setAuthorSubmitBlock']);
$router->get('/admin/proofreader/edit', [AdminController::class, 'editProofreadingArticle']);
$router->post('/admin/proofreader/update', [AdminController::class, 'updateProofreadingArticle']);
$router->post('/admin/articles/valuation', [AdminController::class, 'setArticleValuation']);
$router->post('/admin/articles/translations/save', [AdminArticleTranslationController::class, 'save']);
$router->post('/admin/articles/translations/review', [AdminArticleTranslationController::class, 'review']);
$router->post('/admin/articles/translations/ai-package', [AdminArticleTranslationController::class, 'generateAiPackage']);
$router->get('/admin/surveys', [AdminController::class, 'surveys']);
$router->post('/admin/surveys', [AdminController::class, 'createSurvey']);
$router->post('/admin/surveys/update', [AdminController::class, 'updateSurvey']);
$router->post('/admin/surveys/questions', [AdminController::class, 'addSurveyQuestion']);
$router->post('/admin/surveys/questions/delete', [AdminController::class, 'deleteSurveyQuestion']);
$router->get('/admin/surveys/report', [AdminController::class, 'surveyReport']);
$router->get('/admin/campaigns', [AdminController::class, 'campaigns']);
$router->post('/admin/campaigns', [AdminController::class, 'createCampaign']);
$router->post('/admin/campaigns/update', [AdminController::class, 'updateCampaign']);
$router->get('/admin/campaigns/report', [AdminController::class, 'campaignReport']);
$router->get('/admin/anti-fraud', [AdminController::class, 'antiFraud']);
$router->post('/admin/anti-fraud/scan', [AdminController::class, 'runFraudScan']);
$router->get('/admin/payouts', [AdminController::class, 'payouts']);
$router->post('/admin/payouts/status', [AdminController::class, 'setPayoutStatus']);
$router->get('/admin/users', [AdminController::class, 'users']);
$router->get('/admin/roles', [AdminController::class, 'roles']);
$router->post('/admin/roles/editorial', [AdminController::class, 'updateEditorialRoles']);
$router->post('/admin/roles/disable-2fa', [AdminController::class, 'adminDisableUser2fa']);
$router->get('/admin/role-panel', [AdminController::class, 'rolePanel']);
$router->post('/admin/users/status', [AdminController::class, 'setUserStatus']);
$router->get('/admin/users/delete', [AdminController::class, 'userDeleteReport']);
$router->post('/admin/users/anonymize', [AdminController::class, 'anonymizeUser']);
$router->post('/admin/users/hard-clean', [AdminController::class, 'hardCleanUser']);
$router->post('/admin/users/role', [AdminController::class, 'setUserRole']);
$router->post('/admin/users/approve-author', [AdminController::class, 'approveAuthor']);
$router->post('/admin/users/permissions', [AdminController::class, 'updateUserPermissions']);
$router->get('/admin/main-banner', [AdminController::class, 'mainBanner']);
$router->post('/admin/main-banner', [AdminController::class, 'updateMainBanner']);
$router->post('/admin/main-banner/translate-ai', [AdminController::class, 'translateMainBannerAi']);
$router->get('/admin/categories', [AdminController::class, 'categories']);
$router->post('/admin/categories', [AdminController::class, 'createCategory']);
$router->post('/admin/categories/update', [AdminController::class, 'updateCategory']);
$router->post('/admin/categories/delete', [AdminController::class, 'deleteCategory']);
$router->get('/admin/settings', [AdminController::class, 'settings']);
$router->post('/admin/settings', [AdminController::class, 'updateSettings']);
$router->post('/admin/settings/slowo-snajper', [AdminController::class, 'updateSnajperSettings']);
$router->post('/admin/settings/talent-rules', [AdminController::class, 'updateTalentRules']);
$router->post('/admin/users/talent-reward', [AdminController::class, 'manualTalentReward']);
$router->get('/admin/mails', [AdminController::class, 'mails']);
$router->get('/admin/payments', [FinanceController::class, 'payments']);
$router->get('/admin/ai', [AiAdminController::class, 'index']);
$router->post('/admin/ai/settings', [AiAdminController::class, 'updateSettings']);
$router->post('/admin/ai/translation-instruction', [AiAdminController::class, 'updateTranslationInstruction']);
$router->post('/admin/ai/test', [AiAdminController::class, 'testOpenAi']);
$router->post('/admin/ai/jobs/plan', [AiAdminController::class, 'createPlan']);
$router->post('/admin/payments/settings', [FinanceController::class, 'updatePaymentSettings']);
$router->post('/admin/payments/transfers/approve', [FinanceController::class, 'approveWalletTransfer']);
$router->post('/admin/payments/transfers/reject', [FinanceController::class, 'rejectWalletTransfer']);
$router->get('/admin/ledger', [FinanceController::class, 'ledger']);
$router->get('/admin/finance/approvals', [FinanceController::class, 'financialApprovals']);
$router->post('/admin/finance/approvals/execute', [FinanceController::class, 'executeFinancialApproval']);
$router->post('/admin/finance/approvals/reject', [FinanceController::class, 'rejectFinancialApproval']);
$router->get('/admin/finance', [FinanceController::class, 'report']);
$router->post('/admin/payments/manual-paid', [PaymentWebhookController::class, 'manualPaid']);

$router->dispatch($_SERVER['REQUEST_METHOD'], function_exists('public_normalized_uri') ? public_normalized_uri() : $_SERVER['REQUEST_URI']);
