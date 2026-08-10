<?php
$money = static fn(mixed $minor): string => number_format(((int)$minor/100),2,',',' ') . ' PLN';
$selected = is_array($selected_campaign ?? null) ? $selected_campaign : null;
$tab = (string)($campaign_tab ?? 'banner');
$tabTypes = ['banner'=>'ad_click','video'=>'ad_view','article'=>'sponsored_article','survey'=>'survey_ad'];
$typeTabs = ['ad_click'=>'banner','ad_view'=>'video','sponsored_article'=>'article','survey_ad'=>'survey'];
$tabLabels = ['banner'=>'Baner','video'=>'Film','article'=>'Artykuł sponsorowany','survey'=>'Ankieta / sondaż','bugs'=>'Zgłoszenia błędów'];
$campaignType = $selected ? (string)$selected['type'] : ($tabTypes[$tab] ?? 'ad_click');
$activeCount = count(array_filter($campaigns,static fn(array $c):bool=>($c['status']??'')==='active'));
$budget = array_sum(array_map(static fn(array $c):int=>(int)($c['budget_minor']??0),$campaigns));
$spent = array_sum(array_map(static fn(array $c):int=>(int)($c['spent_minor']??0),$campaigns));
$effects = array_sum(array_map(static fn(array $c):int=>(int)($c['verified_events_count']??0),$campaigns));
?>
<section class="admin-page-head zs-operator-page-head zs-campaign-admin-head">
  <p class="kicker">Kampanie i zaangażowanie</p>
  <h1>Reklamodawca płaci za efekt, który potrafimy potwierdzić</h1>
  <p>Wybierz rodzaj kampanii, dodaj materiał i ustaw budżet. System pokazuje tylko formy, które mają kompletne rozliczenie oraz działającą nagrodę TT.</p>
  <div class="zs-campaign-trust-row"><span>Jeden mierzalny efekt</span><span>Jasna stawka w PLN</span><span>Nagroda TT z Programu Talent</span></div>
</section>

<nav class="zs-campaign-tabs" aria-label="Rodzaje kampanii">
  <?php foreach($tabLabels as $key=>$label):?><a href="/admin/campaigns?tab=<?= e($key) ?>" class="<?= $tab===$key?'is-active':'' ?>"><?= e($label) ?></a><?php endforeach;?>
</nav>

<section class="zs-operator-overview" aria-label="Podsumowanie kampanii">
  <article><span>Wszystkie zlecenia</span><strong><?= count($campaigns) ?></strong><small>kampanie zapisane w systemie</small></article>
  <article class="<?= $activeCount>0?'is-ready':'is-muted' ?>"><span>Aktywne teraz</span><strong><?= $activeCount ?></strong><small>widoczne dla użytkowników</small></article>
  <article><span>Potwierdzone efekty</span><strong><?= $effects ?></strong><small>tylko one obciążają budżet</small></article>
  <article><span>Wykorzystany budżet</span><strong><?= $money($spent) ?></strong><small>z przyjętych <?= $money($budget) ?></small></article>
</section>

<div class="campaign-admin-page zs-operator-page">
<?php if($tab==='bugs'):?>
  <?php require __DIR__ . '/partials/bug_reports.php'; ?>
<?php else:?>
  <section class="admin-panel-block zs-campaign-editor-panel">
    <div class="admin-section-head"><div><p class="kicker"><?= $selected?'Edycja zlecenia #'.(int)$selected['id']:'Nowe zlecenie' ?></p><h2><?= $selected?e((string)$selected['name']):'Utwórz: '.e($tabLabels[$tab]) ?></h2></div><div class="zs-campaign-head-actions"><?php if($selected):?><a class="btn-line compact" href="/admin/campaigns?tab=<?= e($tab) ?>">Nowa kampania</a><a class="btn-red compact" href="/admin/campaigns/report?id=<?= (int)$selected['id'] ?>">Raport dla reklamodawcy</a><?php endif;?></div></div>
    <?php if($selected):?><div class="zs-campaign-economy-strip"><div><span>Stawka za efekt</span><strong><?= $money($selected['event_cost_minor']??0) ?></strong></div><div><span>Nagroda użytkownika</span><strong><?= (int)($selected['talent_points']??0) ?> TT</strong></div><div><span>Wykorzystano</span><strong><?= $money($selected['spent_minor']??0) ?></strong></div><div><span>Pozostało</span><strong><?= $money(max(0,(int)$selected['budget_minor']-(int)($selected['spent_minor']??0))) ?></strong></div></div><?php endif;?>
    <?php
      $campaignFormData=$selected??[];
      $campaignFormType=$campaignType;
      $campaignFormAction=$selected?'/admin/campaigns/update':'/admin/campaigns';
      $campaignFormSubmit=$selected?'Zapisz kampanię':'Utwórz kampanię';
      require __DIR__ . '/partials/campaign_form.php';
    ?>
  </section>
  <?php if($tab==='survey'): require __DIR__ . '/partials/campaign_surveys.php'; endif; ?>

  <section class="admin-panel-block">
    <div class="admin-section-head"><div><p class="kicker">Kontrola zleceń</p><h2>Wszystkie kampanie</h2><p>Stan, budżet i wynik bez nazw technicznych.</p></div><span><?= count($campaigns) ?> pozycji</span></div>
    <?php if($campaigns===[]):?><div class="empty-state"><h3>Nie ma jeszcze kampanii</h3><p>Pierwsze zlecenie utworzysz w formularzu powyżej.</p></div><?php else:?><div class="admin-table-wrap"><table class="zs-admin-table zs-campaign-table"><thead><tr><th>Kampania</th><th>Za co płaci reklamodawca</th><th>Stan</th><th>Budżet</th><th>Wynik</th><th>Działanie</th></tr></thead><tbody>
      <?php foreach($campaigns as $campaign):$rowTab=$typeTabs[(string)$campaign['type']]??'banner';$remaining=max(0,(int)$campaign['budget_minor']-(int)($campaign['spent_minor']??0));?>
      <tr><td><strong><?= e((string)$campaign['name']) ?></strong><small><?= e((string)($campaign['client_name']??'')) ?></small></td><td><?= e((string)($types[$campaign['type']]??$campaign['type'])) ?><small><?= $money($campaign['event_cost_minor']??0) ?> za efekt · <?= (int)($campaign['talent_points']??0) ?> TT</small></td><td><span class="status-pill <?= ($campaign['runtime_ready']??false)?'is-ready':'is-muted' ?>"><?= e((string)($statuses[$campaign['status']]??$campaign['status'])) ?></span><small><?= !empty($campaign['budget_confirmed'])?'Zlecenie potwierdzone':'Czeka na potwierdzenie budżetu' ?></small></td><td><strong><?= $money($remaining) ?></strong><small>pozostało z <?= $money($campaign['budget_minor']) ?></small></td><td><strong><?= (int)($campaign['verified_events_count']??0) ?> efektów</strong><small><?= (int)($campaign['duplicate_attempts_count']??0) ?> powtórzeń bez kosztu</small></td><td><a class="text-link" href="/admin/campaigns?tab=<?= e($rowTab) ?>&id=<?= (int)$campaign['id'] ?>">Edytuj</a> · <a class="text-link" href="/admin/campaigns/report?id=<?= (int)$campaign['id'] ?>">Raport</a></td></tr>
      <?php endforeach;?></tbody></table></div><?php endif;?>
  </section>
<?php endif;?>
</div>
