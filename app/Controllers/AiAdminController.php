<?php
namespace App\Controllers;

use App\Services\AiFoundationService;
use App\Services\OpenAiClient;
use App\Services\AiBackgroundJobService;
use App\Services\DurableJobQueue;
use App\Services\StructuredAuditService;
use App\Security\Authorization\PermissionCatalog;

final class AiAdminController extends BaseController
{
    public function index(): string
    {
        $this->requirePermission(PermissionCatalog::AI_VIEW);
        $service = new AiFoundationService($this->app->db);
        return $this->view('admin/ai', [
            'title' => t('controller.aiadmin.ai_redakcyjne'),
            'ai_settings' => $service->settings(),
            'ai_summary' => $service->summary(),
            'ai_jobs' => $service->recentJobs(),
            'article_translations' => $service->recentTranslations(),
            'ai_prompts' => $service->promptTemplates(),
            'ai_active_prompts' => $service->activePromptTemplates(),
            'ai_articles' => $service->articlesForPlanning(),
            'ai_events' => $service->recentEvents(),
            'openai_key_configured' => (new OpenAiClient($this->app->config['ai'] ?? []))->configured(),
        ]);
    }


    public function updateTranslationInstruction(): never
    {
        $actorId = $this->requirePermission(PermissionCatalog::AI_PROMPT_MANAGE);
        try {
            $instruction = (string)($_POST['ai_translation_default_instruction'] ?? '');
            (new AiFoundationService($this->app->db))->updateDefaultTranslationInstruction($instruction);
            $this->slowoSnajper()->audit($actorId, 'ai.translation_instruction.update', [
                'result' => 'success',
            ]);
            $this->app->session->flash('success', t('controller.aiadmin.gowna_dyspozycja_tumaczen_zostaa_zapisana'));
        } catch (\Throwable $e) {
            $this->slowoSnajper()->audit($actorId, 'ai.translation_instruction.update', [
                'result' => 'failure',
                'error_type' => $e::class,
            ]);
            $this->app->session->flash('error', $e->getMessage());
        }
        redirect('/admin/ai');
    }

    public function updateSettings(): never
    {
        $actorId = $this->requirePermission(PermissionCatalog::AI_SETTINGS_MANAGE);
        try {
            $settingsInput = $this->normalizedSettingsPost($_POST);
            $service = new AiFoundationService($this->app->db);
            $this->authorizeCriticalOperation(
                $actorId,
                'ai.settings.update',
                'settings_group',
                'ai',
                ['keys' => array_keys($settingsInput)],
                $service->settings(),
                $settingsInput,
            );
            $service->updateSettings($settingsInput);
            $this->slowoSnajper()->audit($actorId, 'ai.settings.update', [
                'result' => 'success',
                'keys' => array_keys($settingsInput),
            ]);
            $this->app->session->flash('success', t('controller.aiadmin.zaozenia_ai_zostay_zapisane'));
        } catch (\Throwable $e) {
            $this->slowoSnajper()->audit($actorId, 'ai.settings.update', [
                'result' => 'failure',
                'error_type' => $e::class,
            ]);
            $this->app->session->flash('error', $e->getMessage());
        }
        redirect('/admin/ai');
    }

    /**
     * PHP zamienia kropki w nazwach pól formularza na podkreślenia.
     * Panel AI używa kluczy ustawień z kropkami, więc przed zapisem odtwarzamy
     * właściwe nazwy settings.* bez zmiany samego formularza i bez ruszania DB.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function normalizedSettingsPost(array $post): array
    {
        $keys = [
            'ai.enabled',
            'ai.default_provider',
            'ai.openai.model',
            'ai.translation.model',
            'ai.translation.max_chars_per_job',
            'ai.translation.premium_model',
            'ai.translation.daily_jobs_limit',
            'ai.translation.monthly_budget_minor',
            'ai.translation.estimated_cost_per_1k_chars_minor',
            'ai.storage.source_of_truth',
            'ai.storage.raw_json_policy',
            'ai.translation.enabled',
            'ai.translation.require_editor_review',
            'ai.translation.default_source_language',
            'ai.translation.default_target_language',
            'ai.translation.default_instruction',
            'ai.jobs.manual_planning_enabled',
            'ai.jobs.require_admin',
            'ai.jobs.execute_api_enabled',
        ];

        $normalized = [];
        foreach ($keys as $key) {
            $htmlKey = str_replace('.', '_', $key);
            if (array_key_exists($key, $post)) {
                $normalized[$key] = $post[$key];
            } elseif (array_key_exists($htmlKey, $post)) {
                $normalized[$key] = $post[$htmlKey];
            }
        }

        return $normalized;
    }


    public function testOpenAi(): never
    {
        $actorId = $this->requirePermission(PermissionCatalog::AI_PROVIDER_TEST);
        try {
            $adminId = $actorId;
            if ($adminId <= 0) {
                throw new \RuntimeException(t('controller.aiadmin.brak_uzytkownika_administracyjnego_w_sesji'));
            }
            $settings = (new AiFoundationService($this->app->db))->settings();
            $model = trim((string)($_POST['model'] ?? ($settings['ai.openai.model'] ?? 'gpt-5.5')));
            $job = (new AiBackgroundJobService(
                new DurableJobQueue($this->app->db, $this->app->queueSignals),
                new StructuredAuditService($this->app->db),
            ))->queueProviderTest($adminId, PermissionCatalog::AI_PROVIDER_TEST, $model);
            $this->slowoSnajper()->audit($actorId, 'ai.provider.test.queued', [
                'result' => 'success',
                'provider' => 'openai',
                'model' => $model,
                'background_job_id' => (int)$job['id'],
            ]);
            $this->app->session->flash('success', t('controller.aiadmin.test_openai_trafi_do_izolowanej_kolejki_ai_id_zadania') . $job['public_id'] . '.');
        } catch (\Throwable $e) {
            $this->slowoSnajper()->audit($actorId, 'ai.provider.test', [
                'result' => 'failure',
                'provider' => 'openai',
                'error_type' => $e::class,
            ]);
            $this->app->session->flash('error', t('controller.aiadmin.test_openai_nie_powiod_sie') . $e->getMessage());
        }
        redirect('/admin/ai');
    }

    public function createPlan(): never
    {
        $actorId = $this->requirePermission(PermissionCatalog::AI_JOB_PLAN);
        try {
            $adminId = $actorId;
            if ($adminId <= 0) {
                throw new \RuntimeException(t('controller.aiadmin.brak_uzytkownika_administracyjnego_w_sesji'));
            }
            $jobId = (new AiFoundationService($this->app->db))->createPlannedArticleJob($_POST, $adminId);
            $this->slowoSnajper()->audit($actorId, 'ai.job.plan', [
                'result' => 'success',
                'ai_job_id' => $jobId,
            ]);
            $this->app->session->flash('success', str_replace('{id}', (string)$jobId, t('controller.aiadmin.ai_task_planned')));
        } catch (\Throwable $e) {
            $this->slowoSnajper()->audit($actorId, 'ai.job.plan', [
                'result' => 'failure',
                'error_type' => $e::class,
            ]);
            $this->app->session->flash('error', $e->getMessage());
        }
        redirect('/admin/ai');
    }
}
