<?php
namespace App\Controllers;

use App\Services\ArticleService;

final class ReaderController extends BaseController
{
    public function dashboard(): string
    {
        $this->requireAuth();
        return $this->view('reader/dashboard', ['title' => 'Panel czytelnika', 'articles' => (new ArticleService($this->app->db))->published(20)]);
    }
}
