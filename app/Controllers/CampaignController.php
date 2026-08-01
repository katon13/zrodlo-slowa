<?php
namespace App\Controllers;

use App\Services\CampaignService;
use App\Services\LedgerService;
use App\Services\TalentService;
use App\Services\FraudGuardService;
use App\Core\SlowoSnajperConfig;

final class CampaignController extends BaseController
{
    public function index(): string { $s=$this->service(); return $this->view('campaigns/index',['title'=>'Reklamy i akcje specjalne','campaigns'=>$s->activeCampaigns(),'types'=>$s->types()]); }
    public function show(): string { $c=$this->service()->find((int)($_GET['id']??0)); if(!$c){http_response_code(404); return $this->view('layouts/error',['title'=>'Nie znaleziono','message'=>'Nie znaleziono kampanii.']);} return $this->view('campaigns/show',['title'=>$c['name'],'campaign'=>$c,'types'=>$this->service()->types()]); }
    public function viewAd(): never { $this->handle('recordView','Obejrzenie zapisano do weryfikacji. Wynik bonusu pojawi się po przetworzeniu.','Ta aktywność była już dziś zapisana.','Nie udało się zapisać obejrzenia reklamy: '); }
    public function clickAd(): never { $user=$this->requireAuth(); $id=(int)($_POST['campaign_id']??0); try{$r=$this->service()->recordClick($user,$id); $this->app->session->flash('success',$r?'Kliknięcie zapisano do weryfikacji. Wynik bonusu pojawi się po przetworzeniu.':'To kliknięcie było już dziś zapisane.'); $url=is_array($r)?($r['campaign']['target_url']??null):null; if($url && preg_match('~^https?://~i',(string)$url)) redirect((string)$url);}catch(\Throwable $e){$this->app->session->flash('error',$this->safeError($e,'Nie udało się zapisać kliknięcia reklamy.','campaign_click'));} redirect('/campaign?id='.$id); }
    public function sponsoredRead(): never { $this->handle('recordSponsoredRead','Czytanie sponsorowane zapisano do weryfikacji. Wynik bonusu pojawi się po przetworzeniu.','Ta aktywność była już dziś zapisana.','Nie udało się zapisać czytania sponsorowanego: '); }
    public function ppv(): never { $this->handle('recordPpv','Udział PPV zapisano do weryfikacji. Wynik bonusu pojawi się po przetworzeniu.','Ten udział PPV był już dziś zapisany.','Nie udało się zapisać udziału PPV: '); }
    public function liveJoin(): never { $this->handle('recordLiveJoin','Udział w transmisji zapisano do weryfikacji. Wynik bonusu pojawi się po przetworzeniu.','Ten udział live był już dziś zapisany.','Nie udało się zapisać udziału live: '); }
    private function handle(string $method,string $ok,string $dup,string $err): never { $user=$this->requireAuth(); $id=(int)($_POST['campaign_id']??0); try{$r=$this->service()->$method($user,$id); $this->app->session->flash('success',$r?$ok:$dup);}catch(\Throwable $e){$this->app->session->flash('error',$this->safeError($e,rtrim($err),'campaign_'.$method));} redirect('/campaign?id='.$id); }
    private function service(): CampaignService { return new CampaignService($this->app->db,$this->talentService(), new FraudGuardService($this->app->db, $this->slowoSnajperConfig())); }
}
