<?php
$money = static fn(mixed $minor): string => number_format(((int)$minor/100),2,',',' ') . ' PLN';
$selected = is_array($selected_campaign ?? null) ? $selected_campaign : null;
$tab = (string)($campaign_tab ?? 'banner');
$tabTypes = ['banner'=>'ad_click','video'=>'ad_view','article'=>'sponsored_article','survey'=>'survey_ad'];
$typeTabs = ['ad_click'=>'banner','ad_view'=>'video','sponsored_article'=>'article','survey_ad'=>'survey'];
$tabLabels = ['banner'=>t('campaign.type.banner'),'video'=>t('campaign.type.video'),'article'=>t('campaign.type.sponsored_article'),'survey'=>t('admin.campaigns.ankieta_sondaz'),'bugs'=>t('admin.bug_reports.zgoszenia_bedow')];
$campaignType = $selected ? (string)$selected['type'] : ($tabTypes[$tab] ?? 'ad_click');
$activeCount = count(array_filter($campaigns,static fn(array $c):bool=>($c['status']??'')==='active'));
$budget = array_sum(array_map(static fn(array $c):int=>(int)($c['budget_minor']??0),$campaigns));
$spent = array_sum(array_map(static fn(array $c):int=>(int)($c['spent_minor']??0),$campaigns));
$effects = array_sum(array_map(static fn(array $c):int=>(int)($c['verified_events_count']??0),$campaigns));
?>
<section class="admin-page-head zs-operator-page-head zs-campaign-admin-head">
  <p class="kicker"><?= e(t('campaign.index.eyebrow')) ?></p>
  <h1><?= e(t('admin.campaigns.reklama')) ?></h1>
  <p><?= e(t('admin.campaigns.reklamodawca_paci_za_efekt_ktory_potrafimy_potwierdzic_6218d494')) ?></p>
  <div class="zs-campaign-trust-row"><span><?= e(t('admin.campaigns.jeden_mierzalny_efekt')) ?></span><span><?= e(t('admin.campaigns.jasna_stawka_w_pln')) ?></span><span><?= e(t('admin.campaigns.nagroda_tt_z_programu_talent')) ?></span></div>
</section>

<nav class="zs-campaign-tabs" aria-label="<?= e(t('admin.campaigns.rodzaje_kampanii')) ?>">
  <?php foreach($tabLabels as $key=>$label):?><a href="/admin/campaigns?tab=<?= e($key) ?>" class="<?= $tab===$key?'is-active':'' ?>"><?= e($label) ?></a><?php endforeach;?>
</nav>

<section class="zs-operator-overview" aria-label="<?= e(t('admin.campaigns.podsumowanie_kampanii')) ?>">
  <article><span><?= e(t('admin.campaigns.wszystkie_zlecenia')) ?></span><strong><?= count($campaigns) ?></strong><small><?= e(t('admin.campaigns.kampanie_zapisane_w_systemie')) ?></small></article>
  <article class="<?= $activeCount>0?'is-ready':'is-muted' ?>"><span><?= e(t('admin.campaigns.aktywne_teraz')) ?></span><strong><?= $activeCount ?></strong><small><?= e(t('admin.campaigns.widoczne_dla_uzytkownikow')) ?></small></article>
  <article><span><?= e(t('admin.campaigns.potwierdzone_efekty')) ?></span><strong><?= $effects ?></strong><small><?= e(t('admin.campaigns.tylko_one_obciazaja_budzet')) ?></small></article>
  <article><span><?= e(t('admin.campaign_report.wykorzystany_budzet')) ?></span><strong><?= $money($spent) ?></strong><small><?= e(str_replace('{amount}', $money($budget), t('admin.campaigns.of_confirmed_budget'))) ?></small></article>
</section>

<div class="campaign-admin-page zs-operator-page">
<?php if($tab==='bugs'):?>
  <?php require __DIR__ . '/partials/bug_reports.php'; ?>
<?php else:?>
  <section class="admin-panel-block zs-campaign-editor-panel">
    <div class="admin-section-head"><div><p class="kicker"><?= e($selected ? str_replace('{id}', (string)(int)$selected['id'], t('admin.campaigns.edit_order')) : t('admin.campaigns.new_order')) ?></p><h2><?= $selected?e((string)$selected['name']):e(str_replace('{type}', $tabLabels[$tab], t('admin.campaigns.create_type'))) ?></h2></div><div class="zs-campaign-head-actions"><?php if($selected):?><a class="btn-line compact" href="/admin/campaigns?tab=<?= e($tab) ?>"><?= e(t('admin.campaigns.nowa_kampania')) ?></a><a class="btn-red compact" href="/admin/campaigns/report?id=<?= (int)$selected['id'] ?>"><?= e(t('admin.campaign_report.raport_dla_reklamodawcy')) ?></a><?php endif;?></div></div>
    <?php if($selected):?><div class="zs-campaign-economy-strip"><div><span><?= e(t('admin.campaigns.stawka_za_efekt')) ?></span><strong><?= $money($selected['event_cost_minor']??0) ?></strong></div><div><span><?= e(t('admin.campaigns.nagroda_uzytkownika')) ?></span><strong><?= (int)($selected['talent_points']??0) ?> TT</strong></div><div><span><?= e(t('admin.campaigns.wykorzystano')) ?></span><strong><?= $money($selected['spent_minor']??0) ?></strong></div><div><span><?= e(t('admin.campaigns.pozostao')) ?></span><strong><?= $money(max(0,(int)$selected['budget_minor']-(int)($selected['spent_minor']??0))) ?></strong></div></div><?php endif;?>
    <?php
      $campaignFormData=$selected??[];
      $campaignFormType=$campaignType;
      $campaignFormAction=$selected?'/admin/campaigns/update':'/admin/campaigns';
      $campaignFormSubmit=$selected?t('admin.campaigns.zapisz_kampanie'):t('admin.campaigns.utworz_kampanie');
      require __DIR__ . '/partials/campaign_form.php';
    ?>
  </section>
  <?php if($tab==='survey'): require __DIR__ . '/partials/campaign_surveys.php'; endif; ?>

  <section class="admin-panel-block">
    <div class="admin-section-head"><div><p class="kicker"><?= e(t('admin.campaigns.kontrola_zlecen')) ?></p><h2><?= e(t('admin.campaigns.wszystkie_kampanie')) ?></h2><p><?= e(t('admin.campaigns.stan_budzet_i_wynik_bez_nazw_technicznych')) ?></p></div><span><?= e(str_replace('{count}', (string)count($campaigns), t('admin.common.items_count'))) ?></span></div>
    <?php if($campaigns===[]):?><div class="empty-state"><h3><?= e(t('admin.campaigns.nie_ma_jeszcze_kampanii')) ?></h3><p><?= e(t('admin.campaigns.pierwsze_zlecenie_utworzysz_w_formularzu_powyzej')) ?></p></div><?php else:?><div class="admin-table-wrap"><table class="zs-admin-table zs-campaign-table"><thead><tr><th><?= e(t('admin.campaigns.kampania')) ?></th><th><?= e(t('admin.campaigns.za_co_paci_reklamodawca')) ?></th><th><?= e(t('admin.campaigns.stan')) ?></th><th><?= e(t('admin.campaigns.budzet')) ?></th><th><?= e(t('admin.campaign_report.wynik')) ?></th><th><?= e(t('admin.campaign_report.dziaanie')) ?></th></tr></thead><tbody>
      <?php foreach($campaigns as $campaign):$rowTab=$typeTabs[(string)$campaign['type']]??'banner';$remaining=max(0,(int)$campaign['budget_minor']-(int)($campaign['spent_minor']??0));?>
      <tr><td><strong><?= e((string)$campaign['name']) ?></strong><small><?= e((string)($campaign['client_name']??'')) ?></small></td><td><?= e((string)($types[$campaign['type']]??$campaign['type'])) ?><small><?= e(str_replace(['{amount}','{points}'], [$money($campaign['event_cost_minor']??0),(string)(int)($campaign['talent_points']??0)], t('admin.campaigns.effect_price_and_reward'))) ?></small></td><td><span class="status-pill <?= ($campaign['runtime_ready']??false)?'is-ready':'is-muted' ?>"><?= e((string)($statuses[$campaign['status']]??$campaign['status'])) ?></span><small><?= e(!empty($campaign['budget_confirmed'])?t('admin.campaigns.order_confirmed'):t('admin.campaigns.czeka_na_potwierdzenie_budzetu')) ?></small></td><td><strong><?= $money($remaining) ?></strong><small><?= e(str_replace('{amount}', $money($campaign['budget_minor']), t('admin.campaigns.remaining_of'))) ?></small></td><td><strong><?= e(str_replace('{count}', (string)(int)($campaign['verified_events_count']??0), t('admin.campaigns.effects_count'))) ?></strong><small><?= e(str_replace('{count}', (string)(int)($campaign['duplicate_attempts_count']??0), t('admin.campaigns.free_duplicates_count'))) ?></small></td><td><a class="text-link" href="/admin/campaigns?tab=<?= e($rowTab) ?>&id=<?= (int)$campaign['id'] ?>"><?= e(t('author.dashboard.edit')) ?></a> · <a class="text-link" href="/admin/campaigns/report?id=<?= (int)$campaign['id'] ?>"><?= e(t('admin.campaigns.raport')) ?></a></td></tr>
      <?php endforeach;?></tbody></table></div><?php endif;?>
  </section>
<?php endif;?>
</div>
