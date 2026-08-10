<?php $lang = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl')); ?>
<section class="zs-campaign-public-hero">
  <p class="eyebrow"><?= e(t('campaign.index.eyebrow', $lang)) ?></p>
  <h1><?= e(t('campaign.index.heading', $lang)) ?></h1>
  <p class="lead"><?= e(t('campaign.index.lead', $lang)) ?></p>
  <div class="zs-campaign-public-principles">
    <span><?= e(t('campaign.principle.verified', $lang)) ?></span>
    <span><?= e(t('campaign.principle.no_duplicates', $lang)) ?></span>
    <span><?= e(t('campaign.principle.talent', $lang)) ?></span>
  </div>
</section>

<section class="zs-campaign-public-grid" aria-label="<?= e(t('campaign.index.available', $lang)) ?>">
  <?php foreach ($campaigns as $campaign): ?>
    <article class="zs-campaign-public-card" data-campaign-delivery="<?= (int)$campaign['id'] ?>">
      <div class="zs-campaign-card-top"><span><?= e(t('campaign.type.' . (string)$campaign['type'], $lang)) ?></span><strong><?= (int)($campaign['talent_points'] ?? 0) ?> TT</strong></div>
      <?php if (!empty($campaign['creative_path']) && str_starts_with((string)($campaign['creative_mime'] ?? ''), 'image/')): ?><img class="zs-campaign-card-media" src="<?= e((string)$campaign['creative_path']) ?>" alt=""><?php endif; ?>
      <h2><a href="<?= e(public_language_url($lang, (string)($campaign['action_url'] ?? ('/campaign?id=' . (int)$campaign['id'])))) ?>"><?= e((string)$campaign['name']) ?></a></h2>
      <p><?= e(mb_strimwidth((string)($campaign['description'] ?? ''), 0, 190, '…')) ?></p>
      <div class="zs-campaign-card-proof"><span><?= e(t('campaign.card.proof_label', $lang)) ?></span><p><?= e(t((string)($campaign['proof_key'] ?? 'campaign.proof.pending'), $lang)) ?></p></div>
      <a class="btn-red" href="<?= e(public_language_url($lang, (string)($campaign['action_url'] ?? ('/campaign?id=' . (int)$campaign['id'])))) ?>"><?= e(t('campaign.card.action', $lang)) ?></a>
    </article>
  <?php endforeach; ?>
  <?php if ($campaigns === []): ?>
    <div class="zs-campaign-empty"><h2><?= e(t('campaign.empty.title', $lang)) ?></h2><p><?= e(t('campaign.empty.message', $lang)) ?></p></div>
  <?php endif; ?>
</section>

<script>
(function(){
  const token=document.querySelector('meta[name="csrf-token"]')?.content||'';
  document.querySelectorAll('[data-campaign-delivery]').forEach(card=>{
    const body=new URLSearchParams({campaign_id:String(card.dataset.campaignDelivery||0),event_type:'impression'});
    fetch('/campaign/delivery',{method:'POST',headers:{'X-CSRF-TOKEN':token,'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:body.toString(),keepalive:true}).catch(()=>{});
  });
})();
</script>

<section class="zs-campaign-advertiser-note">
  <div><p class="eyebrow"><?= e(t('campaign.advertiser.eyebrow', $lang)) ?></p><h2><?= e(t('campaign.advertiser.title', $lang)) ?></h2></div>
  <p><?= e(t('campaign.advertiser.message', $lang)) ?></p>
</section>
