<?php
namespace App\Controllers;

use App\Services\SurveyService;
use App\Services\FraudGuardService;
use App\Core\SlowoSnajperConfig;

final class SurveyController extends BaseController
{
    public function index(): string
    {
        $service = new SurveyService($this->app->db, new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath)));
        return $this->view('surveys/index', [
            'title' => 'Ankiety i sondaże',
            'surveys' => $service->active(50),
        ]);
    }

    public function show(): string
    {
        $service = new SurveyService($this->app->db, new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath)));
        $surveyId = (int)($_GET['id'] ?? 0);
        $survey = $service->findActive($surveyId);
        if (!$survey) {
            http_response_code(404);
            return $this->view('layouts/error', ['title' => 'Ankieta niedostępna', 'message' => 'Nie znaleziono aktywnej ankiety albo sondażu.']);
        }
        $userId = $this->app->session->userId();
        return $this->view('surveys/show', [
            'title' => $survey['title'],
            'survey' => $survey,
            'questions' => $service->questions($surveyId),
            'already_answered' => $userId ? $service->hasUserResponse($surveyId, $userId) : false,
        ]);
    }

    public function submit(): never
    {
        $userId = $this->requireAuth();
        $surveyId = (int)($_POST['survey_id'] ?? 0);
        try {
            (new SurveyService(
                $this->app->db,
                new FraudGuardService($this->app->db, SlowoSnajperConfig::fromRoot($this->app->rootPath)),
                $this->earningsDispatcher(),
            ))->submitResponse($surveyId, $userId, $_POST['answers'] ?? []);
            $this->app->session->flash('success', 'Dziękujemy. Odpowiedź została zapisana, a nagroda czeka na przetworzenie.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $this->safeError($e, 'Nie udało się zapisać ankiety.', 'survey_submit'));
        }
        redirect('/survey?id=' . $surveyId);
    }
}
