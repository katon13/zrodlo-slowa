<?php
namespace App\Controllers;

use App\Services\SurveyService;
use App\Services\FraudGuardService;
use App\Services\CampaignService;
use App\Core\SlowoSnajperConfig;

final class SurveyController extends BaseController
{
    public function index(): string
    {
        $language = function_exists('public_language') ? public_language() : 'pl';
        $service = new SurveyService($this->app->db, new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath)));
        return $this->view('surveys/index', [
            'title' => t('survey.index.title', $language),
            'surveys' => $service->active(50),
            'survey_reward_points' => $this->surveyRewardPoints(),
        ]);
    }

    public function show(): string
    {
        $language = function_exists('public_language') ? public_language() : 'pl';
        $service = new SurveyService($this->app->db, new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath)));
        $surveyId = (int)($_GET['id'] ?? 0);
        $survey = $service->findActive($surveyId);
        if (!$survey) {
            http_response_code(404);
            return $this->view('layouts/error', ['title' => t('survey.index.title', $language), 'message' => t('survey.index.empty', $language)]);
        }
        $userId = $this->app->session->userId();
        try {
            (new CampaignService(
                $this->app->db,
                $this->talentService(),
                new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath)),
            ))->recordLinkedSurveyStart($userId, $surveyId, $this->campaignDeliverySessionHash());
        } catch (\Throwable $error) {
            error_log('Nie udało się zapisać rozpoczęcia kampanii ankietowej: ' . $error->getMessage());
        }
        return $this->view('surveys/show', [
            'title' => $survey['title'],
            'survey' => $survey,
            'questions' => $service->questions($surveyId),
            'already_answered' => $userId ? $service->hasUserResponse($surveyId, $userId) : false,
            'survey_reward_points' => $this->surveyRewardPoints(),
        ]);
    }

    public function submit(): never
    {
        $language = function_exists('public_language') ? public_language() : 'pl';
        $userId = $this->requireAuth();
        $surveyId = (int)($_POST['survey_id'] ?? 0);
        try {
            $responseId = (new SurveyService(
                $this->app->db,
                new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath)),
                $this->earningsDispatcher(),
            ))->submitResponse($surveyId, $userId, $_POST['answers'] ?? []);
            try {
                (new \App\Services\CampaignService(
                    $this->app->db,
                    $this->talentService(),
                    new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath)),
                ))->recordSurveyCompletion($userId, $surveyId, $responseId);
            } catch (\Throwable $campaignError) {
                error_log('Nie udało się rozliczyć kampanii ankiety: ' . $campaignError->getMessage());
            }
            $this->app->session->flash('success', t('survey.flash.success', $language));
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, t('survey.flash.error', $language), 'survey_submit'));
        }
        redirect(public_language_url($language, '/survey?id=' . $surveyId));
    }

    private function surveyRewardPoints(): int
    {
        $rule = $this->app->db->one("SELECT points_amount,is_active FROM activity_reward_rules WHERE activity_type='survey_reward' LIMIT 1");
        return (int)($rule['is_active'] ?? 0) === 1 ? max(0, (int)($rule['points_amount'] ?? 0)) : 0;
    }
}
