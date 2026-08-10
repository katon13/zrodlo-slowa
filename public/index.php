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
        $heartbeatPdo = null;
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
                $heartbeatPdo = $pdo;
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

        // Docker odpytuje readiness niezależnie dla app-1 i app-2. Wartownik
        // dostaje dzięki temu jawny heartbeat, zamiast uznawać brak zdarzeń za zdrowie instancji.
        if ($heartbeatPdo instanceof PDO && ($checks['schema'] ?? false)) {
            try {
                $heartbeatTable = (string)$heartbeatPdo->query(
                    "SELECT COALESCE(to_regclass('security_instance_heartbeats')::text,'')"
                )->fetchColumn();
                if ($heartbeatTable !== '') {
                    $expectedInstances = array_values(array_filter(
                        array_map('trim', explode(',', (string)(getenv('SENTINEL_EXPECTED_INSTANCES') ?: 'app-1,app-2'))),
                        static fn(string $item): bool => preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D', $item) === 1,
                    ));
                    $statement = $heartbeatPdo->prepare(
                        'INSERT INTO security_instance_heartbeats(
                            instance_id,instance_role,expected,ready,last_seen_at,last_ready_at,created_at,updated_at
                         ) VALUES(:instance,\'application\',:expected,:ready,NOW(),CASE WHEN :ready_again THEN NOW() ELSE NULL END,NOW(),NOW())
                         ON CONFLICT(instance_id) DO UPDATE SET
                            instance_role=EXCLUDED.instance_role,
                            expected=EXCLUDED.expected,
                            ready=EXCLUDED.ready,
                            last_seen_at=NOW(),
                            last_ready_at=CASE WHEN EXCLUDED.ready THEN NOW() ELSE security_instance_heartbeats.last_ready_at END,
                            updated_at=NOW()'
                    );
                    $statement->execute([
                        'instance' => $instanceId,
                        'expected' => in_array($instanceId, $expectedInstances, true),
                        'ready' => $ready,
                        'ready_again' => $ready,
                    ]);
                }
            } catch (Throwable $error) {
                error_log('[3dors_sentinel_heartbeat] instance=' . $instanceId . ' error=' . $error::class);
            }
        }
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-App-Instance: ' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $instanceId));
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/../app/Core/bootstrap.php';

$wellKnownPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($wellKnownPath === '/.well-known/assetlinks.json') {
    $fingerprints = array_values(array_filter(array_map(
        static fn(string $value): string => strtoupper(trim($value)),
        explode(',', (string)env('ANDROID_APP_LINK_SHA256_CERT_FINGERPRINTS', ''))
    ), static fn(string $value): bool => preg_match('/^(?:[A-F0-9]{2}:){31}[A-F0-9]{2}$/D', $value) === 1));
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    echo json_encode($fingerprints === [] ? [] : [[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => [
            'namespace' => 'android_app',
            'package_name' => 'pl.zrodloslowa.app',
            'sha256_cert_fingerprints' => $fingerprints,
        ],
    ]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

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
use App\Controllers\BugReportController;
use App\Controllers\AuthorController;
use App\Controllers\ResponsePublicationController;
use App\Controllers\ReaderController;
use App\Controllers\WalletController;
use App\Controllers\DonationController;
use App\Controllers\Dors3AdminController;
use App\Controllers\Dors3SentinelController;
use App\Controllers\Dors3MobileAdminController;
use App\Controllers\Dors3MobileApiController;
use App\Controllers\MobileSessionController;
use App\Controllers\AdminWebRecoveryController;
use App\Controllers\SafetyFundAdminController;
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
use App\Controllers\AppReferralController;

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
$router->get('/login/3dors-mobile', [AuthController::class, 'showMobileChallenge']);
$router->post('/login/3dors-mobile/complete', [AuthController::class, 'completeMobileChallenge']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/security/recovery', [AdminWebRecoveryController::class, 'show']);
$router->post('/security/recovery/start', [AdminWebRecoveryController::class, 'start']);
$router->post('/security/recovery/enrollment/start', [AdminWebRecoveryController::class, 'startEnrollment']);
$router->post('/security/recovery/enrollments/{enrollment_public_id}/approve', [AdminWebRecoveryController::class, 'approveEnrollment']);
$router->post('/security/recovery/enrollments/{enrollment_public_id}/cancel', [AdminWebRecoveryController::class, 'cancelEnrollment']);
$router->post('/security/recovery/codes/generate', [AdminWebRecoveryController::class, 'generateCodes']);
$router->post('/security/recovery/codes/confirm', [AdminWebRecoveryController::class, 'confirmCodes']);
$router->post('/security/recovery/finish', [AdminWebRecoveryController::class, 'finish']);
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
$router->get('/campaign/go', [CampaignController::class, 'go']);
$router->post('/campaign/delivery', [CampaignController::class, 'delivery']);
$router->get('/report-bug', [BugReportController::class, 'form']);
$router->post('/report-bug', [BugReportController::class, 'submit']);
$router->post('/api/earnings/presence', [EarningsApiController::class, 'presence']);
$router->get('/api/earnings/notifications', [EarningsApiController::class, 'notifications']);
$router->post('/api/earnings/notifications/ack', [EarningsApiController::class, 'acknowledgeNotifications']);
$router->get('/api/earnings/jobs/status', [EarningsApiController::class, 'jobStatus']);
$router->post('/api/earnings/article-read', [EarningsApiController::class, 'articleRead']);
$router->get('/api/mobile/session', [MobileSessionController::class, 'show']);
$router->get('/app/referral/{token}', [AppReferralController::class, 'landing']);
$router->get('/api/talent/referrals', [AppReferralController::class, 'overview']);
$router->post('/api/talent/referrals', [AppReferralController::class, 'create']);
$router->post('/api/mobile/referral/install', [AppReferralController::class, 'mobileInstall'], ['csrf' => false]);
$router->post('/api/mobile/referral/registration-nonce', [AppReferralController::class, 'mobileRegistrationNonce'], ['csrf' => false]);
$router->post('/api/mobile/referral/first-session', [AppReferralController::class, 'mobileFirstSession'], ['csrf' => false]);

$router->get('/authors', [AuthorController::class, 'authorsShortcut']);
$router->get('/author', [AuthorController::class, 'dashboard']);
$router->get('/author/articles/create', [AuthorController::class, 'createArticle']);
$router->post('/author/articles', [AuthorController::class, 'storeArticle']);
$router->get('/author/articles/edit', [AuthorController::class, 'editArticle']);
$router->post('/author/articles/update', [AuthorController::class, 'updateArticle']);
$router->post('/author/articles/submit', [AuthorController::class, 'submitArticle']);
$router->post('/author/articles/publish', [AuthorController::class, 'publishArticle']);
$router->post('/author/articles/upload-image', [AuthorController::class, 'uploadImageAjax']);
$router->post('/author/articles/delete-image', [AuthorController::class, 'deleteImageAjax']);
$router->post('/author/media/update-position', [AuthorController::class, 'updateImagePositionAjax']);

$router->get('/opinie', [ResponsePublicationController::class, 'dashboard']);
$router->get('/opinie/nowa', [ResponsePublicationController::class, 'create']);
$router->post('/opinie', [ResponsePublicationController::class, 'store']);
$router->get('/opinie/edytuj', [ResponsePublicationController::class, 'edit']);
$router->post('/opinie/aktualizuj', [ResponsePublicationController::class, 'update']);
$router->post('/opinie/wyslij', [ResponsePublicationController::class, 'submit']);

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

$router->post('/auth/3dors/mobile/start', [Dors3MobileApiController::class, 'startAuth']);
$router->get('/auth/3dors/mobile/status/{public_id}', [Dors3MobileApiController::class, 'authStatus']);
$router->post('/api/3dors/mobile/enrollment/complete', [Dors3MobileApiController::class, 'completeEnrollment'], ['csrf' => false]);
$router->post('/api/3dors/mobile/enrollment/confirm', [Dors3MobileApiController::class, 'confirmEnrollment'], ['csrf' => false]);
$router->get('/api/3dors/mobile/requests/{public_id}', [Dors3MobileApiController::class, 'requestDetails']);
$router->post('/api/3dors/mobile/requests/{public_id}/approve', [Dors3MobileApiController::class, 'approve'], ['csrf' => false]);
$router->post('/api/3dors/mobile/requests/{public_id}/reject', [Dors3MobileApiController::class, 'reject'], ['csrf' => false]);
$router->get('/api/3dors/mobile/devices/{device_public_id}/pending-request', [Dors3MobileApiController::class, 'pendingRequest']);
$router->get('/api/3dors/mobile/devices/{device_public_id}/status', [Dors3MobileApiController::class, 'deviceStatus']);
$router->post('/api/3dors/mobile/devices/{device_public_id}/heartbeat', [Dors3MobileApiController::class, 'heartbeat'], ['csrf' => false]);

$router->get('/donations', [DonationController::class, 'campaign']);
$router->post('/donations/manual', [DonationController::class, 'manualDonation']);

$router->get('/admin', [AdminController::class, 'dashboard']);
$router->get('/admin/safety-fund', [SafetyFundAdminController::class, 'index']);
$router->post('/admin/safety-fund/policy', [SafetyFundAdminController::class, 'requestPolicyChange']);
$router->post('/admin/safety-fund/disbursements', [SafetyFundAdminController::class, 'requestDisbursement']);
$router->get('/admin/security/3dors', [Dors3AdminController::class, 'index']);
$router->get('/admin/security/sentinel', [Dors3SentinelController::class, 'index']);
$router->post('/admin/security/sentinel/alerts/{alert_public_id}/acknowledge', [Dors3SentinelController::class, 'acknowledge']);
$router->post('/admin/security/sentinel/alerts/{alert_public_id}/resolve', [Dors3SentinelController::class, 'resolve']);
$router->get('/admin/security/unlock', [Dors3AdminController::class, 'showUnlock']);
$router->post('/admin/security/unlock', [Dors3AdminController::class, 'unlock']);
$router->post('/admin/security/3dors/recovery/generate', [Dors3AdminController::class, 'generateRecoveryCodes']);
$router->post('/admin/security/3dors/recovery/confirm', [Dors3AdminController::class, 'confirmRecoveryCodes']);
$router->post('/admin/security/mobile/enrollment/start', [Dors3MobileAdminController::class, 'startEnrollment']);
$router->post('/admin/security/mobile/enrollments/{enrollment_public_id}/approve', [Dors3MobileAdminController::class, 'approveEnrollment']);
$router->post('/admin/security/mobile/devices/{device_public_id}/suspend', [Dors3MobileAdminController::class, 'suspend']);
$router->post('/admin/security/mobile/devices/{device_public_id}/resume', [Dors3MobileAdminController::class, 'resume']);
$router->post('/admin/security/mobile/devices/{device_public_id}/revoke', [Dors3MobileAdminController::class, 'revoke']);
$router->post('/admin/security/mobile/devices/{device_public_id}/mark-lost', [Dors3MobileAdminController::class, 'markLost']);
$router->post('/admin/security/mobile/enrollments/{enrollment_public_id}/cancel', [Dors3MobileAdminController::class, 'cancelEnrollment']);
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
$router->post('/admin/bug-reports/review', [AdminController::class, 'reviewBugReport']);
$router->get('/admin/bug-reports', [AdminController::class, 'bugReports']);
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
$router->post('/admin/settings/talent-promotion', [AdminController::class, 'updateTalentPromotion']);
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
