<?php
$money=static fn(mixed $minor):string=>number_format(((int)$minor)/100,2,',',' ').' PLN';
$type=(string)$campaign['type'];
$effectLabel=match($type){'ad_click'=>'potwierdzonych przejść','ad_view'=>'ukończonych obejrzeń','sponsored_article'=>'potwierdzonych przeczytań',default=>'ukończonych ankiet'};
$eventLabel=static fn(string $event):string=>match($event){'click'=>'Przejście z banera','view'=>'Obejrzenie filmu','sponsored_read'=>'Przeczytanie artykułu','survey_completed'=>'Ukończenie ankiety',default=>'Działanie użytkownika'};
?>
<section class="admin-page-head zs-campaign-report-head"><p class="kicker">Raport dla reklamodawcy</p><h1><?= e((string)$campaign['name']) ?></h1><p>Pokazujemy rezultat, wykorzystany budżet i pozostałą kwotę. Odrzucone działania oraz powtórzenia nie obciążają reklamodawcy.</p><div class="zs-campaign-trust-row"><span><?= e((string)($campaign['client_name']??'Zleceniodawca')) ?></span><span><?= e((string)($campaign['order_reference']??'Bez numeru zlecenia')) ?></span><span><?= !empty($campaign['budget_confirmed'])?'Budżet potwierdzony':'Budżet czeka na potwierdzenie' ?></span></div></section>

<section class="zs-operator-overview" aria-label="Wynik kampanii">
  <article class="is-ready"><span>Potwierdzony wynik</span><strong><?= (int)$summary['verified'] ?></strong><small><?= e($effectLabel) ?></small></article>
  <article><span>Wykorzystany budżet</span><strong><?= $money($summary['spent_minor']) ?></strong><small>pozostało <?= $money($summary['remaining_minor']) ?></small></article>
  <article><span>Przyznane użytkownikom</span><strong><?= (int)$summary['rewarded_points'] ?> TT</strong><small>TT są oddzielone od budżetu PLN</small></article>
  <article><span>Unikalni uczestnicy</span><strong><?= (int)$summary['unique_users'] ?></strong><small>tylko potwierdzone wyniki</small></article>
</section>

<section class="admin-panel-block"><div class="admin-section-head"><div><p class="kicker">Skuteczność</p><h2>Co wydarzyło się w kampanii</h2></div><span>Stan na teraz</span></div>
  <div class="zs-campaign-business-metrics">
    <?php if($type==='ad_click'):?><article><span>Wyświetlenia banera</span><strong><?= (int)$summary['impressions'] ?></strong></article><article><span>Potwierdzone przejścia</span><strong><?= (int)$summary['verified'] ?></strong></article><article><span>Skuteczność kliknięć</span><strong><?= number_format((float)$summary['ctr_percent'],2,',',' ') ?>%</strong></article><?php endif;?>
    <?php if($type==='ad_view'):?><article><span>Wyświetlenia</span><strong><?= (int)$summary['impressions'] ?></strong></article><article><span>Rozpoczęte filmy</span><strong><?= (int)$summary['starts'] ?></strong></article><article><span>Ukończone obejrzenia</span><strong><?= (int)$summary['verified'] ?></strong></article><article><span>Średni czas</span><strong><?= (int)$summary['average_watch_seconds'] ?> s</strong></article><?php endif;?>
    <?php if($type==='sponsored_article'):?><article><span>Wejścia do artykułu</span><strong><?= (int)$summary['starts'] ?></strong></article><article><span>Potwierdzone przeczytania</span><strong><?= (int)$summary['verified'] ?></strong></article><article><span>Ukończenie</span><strong><?= number_format((float)$summary['completion_percent'],2,',',' ') ?>%</strong></article><article><span>Stawka za przeczytanie</span><strong><?= $money($campaign['event_cost_minor']) ?></strong></article><?php endif;?>
    <?php if($type==='survey_ad'):?><article><span>Rozpoczęte ankiety</span><strong><?= (int)$summary['starts'] ?></strong></article><article><span>Ukończone ankiety</span><strong><?= (int)$summary['verified'] ?></strong></article><article><span>Ukończenie</span><strong><?= number_format((float)$summary['completion_percent'],2,',',' ') ?>%</strong></article><article><span>Stawka za ukończenie</span><strong><?= $money($campaign['event_cost_minor']) ?></strong></article><?php endif;?>
  </div>
  <div class="zs-human-note"><strong>Szacowany wynik serwisu: <?= $money($summary['estimated_margin_minor']) ?></strong><p>To wpływ kampanii pomniejszony o wartość przyznanych TT. Budżet reklamodawcy i TT pozostają rozliczane osobno.</p></div>
</section>

<section class="admin-panel-block"><div class="admin-section-head"><div><p class="kicker">Ostatnie wyniki</p><h2>Rozliczone działania</h2></div><span><?= count($recent) ?> pozycji</span></div>
  <?php if($recent===[]):?><div class="empty-state"><h3>Jeszcze bez wyników</h3><p>Raport uzupełni się po pierwszym potwierdzonym działaniu użytkownika.</p></div><?php else:?><div class="admin-table-wrap"><table class="zs-admin-table"><thead><tr><th>Data</th><th>Działanie</th><th>Użytkownik</th><th>Wynik</th><th>Rozliczenie</th></tr></thead><tbody><?php foreach($recent as $event):?><tr><td><?= e((string)$event['created_at']) ?></td><td><strong><?= e($eventLabel((string)$event['event_type'])) ?></strong><?php if((int)$event['watch_seconds']>0):?><small><?= (int)$event['watch_seconds'] ?> s</small><?php endif;?></td><td><?= e((string)($event['user_name']?:$event['email']?:'Użytkownik')) ?></td><td><span class="status-pill <?= $event['verification_status']==='verified'?'is-ready':'is-muted' ?>"><?= $event['verification_status']==='verified'?'Potwierdzone':'Bez rozliczenia' ?></span></td><td><strong><?= $money($event['cost_minor']) ?></strong><small><?= (int)$event['talent_points_snapshot'] ?> TT</small></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>
<p><a class="btn-line" href="/admin/campaigns?tab=<?= e(match($type){'ad_view'=>'video','sponsored_article'=>'article','survey_ad'=>'survey',default=>'banner'}) ?>&id=<?= (int)$campaign['id'] ?>">Wróć do kampanii</a></p>
