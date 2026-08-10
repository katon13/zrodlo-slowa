<?php
$lang = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$isClick = (string)$campaign['type'] === 'ad_click';
$isView = (string)$campaign['type'] === 'ad_view';
$rewardText = str_replace('{points}', (string)(int)($campaign['talent_points'] ?? 0), t('campaign.detail.reward_value', $lang));
?>
<article class="zs-campaign-detail" data-campaign-delivery="<?= (int)$campaign['id'] ?>">
  <header class="zs-campaign-detail-head">
    <p class="breadcrumb"><a href="<?= e(public_language_url($lang, '/campaigns')) ?>"><?= e(t('campaign.detail.back', $lang)) ?></a> / <?= e(t('campaign.type.' . (string)$campaign['type'], $lang)) ?></p>
    <div class="zs-campaign-detail-title"><div><p class="kicker"><?= e(t('campaign.detail.eyebrow', $lang)) ?></p><h1><?= e((string)$campaign['name']) ?></h1></div><span><?= e($rewardText) ?></span></div>
    <p class="lead"><?= e((string)($campaign['description'] ?? '')) ?></p>
  </header>

  <div class="zs-campaign-detail-grid">
    <section class="zs-campaign-action-panel">
      <?php if ($isClick && !empty($campaign['creative_path'])): ?>
        <a class="zs-campaign-detail-creative" href="/campaign/go?id=<?= (int)$campaign['id'] ?>"><img src="<?= e((string)$campaign['creative_path']) ?>" alt="<?= e((string)$campaign['name']) ?>"></a>
      <?php elseif ($isView && !empty($campaign['creative_path'])): ?>
        <video class="zs-campaign-detail-creative" controls playsinline preload="metadata" data-campaign-view-media>
          <source src="<?= e((string)$campaign['creative_path']) ?>" type="<?= e((string)$campaign['creative_mime']) ?>">
        </video>
      <?php endif; ?>
      <p class="eyebrow"><?= e(t('campaign.detail.action_label', $lang)) ?></p>
      <h2><?= e($isClick ? t('campaign.detail.click_title', $lang) : t('campaign.detail.view_title', $lang)) ?></h2>
      <p><?= e($isClick ? t('campaign.detail.click_message', $lang) : t('campaign.detail.view_message', $lang)) ?></p>

      <?php if (!empty($_SESSION['user_id'])): ?>
        <?php if ($isClick): ?>
          <a class="btn-red" href="/campaign/go?id=<?= (int)$campaign['id'] ?>"><?= e(t('campaign.action.click', $lang)) ?></a>
        <?php elseif ($isView && is_array($view_proof ?? null)): ?>
          <form method="post" action="/campaign/view" class="zs-campaign-primary-action" data-campaign-view-form data-min-seconds="<?= (int)$view_proof['min_seconds'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
            <input type="hidden" name="proof_token" value="<?= e((string)$view_proof['token']) ?>">
            <input type="hidden" name="visible_seconds" value="0" data-visible-seconds>
            <input type="hidden" name="visible" value="0" data-visible-state>
            <div class="zs-campaign-view-progress"><span data-view-progress style="width:0%"></span></div>
            <p data-view-counter><?= e(str_replace('{seconds}', (string)(int)$view_proof['min_seconds'], t('campaign.action.view_wait', $lang))) ?></p>
            <button class="btn-red" type="submit" disabled data-view-submit><?= e(t('campaign.action.view_confirm', $lang)) ?></button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <a class="btn-red" href="<?= e(public_language_url($lang, '/login')) ?>"><?= e(t('campaign.action.login', $lang)) ?></a>
      <?php endif; ?>
    </section>

    <aside class="zs-campaign-trust-panel">
      <p class="eyebrow"><?= e(t('campaign.detail.trust_eyebrow', $lang)) ?></p>
      <h2><?= e(t('campaign.detail.trust_title', $lang)) ?></h2>
      <ol>
        <li><strong>01</strong><span><?= e(t('campaign.detail.trust_proof', $lang)) ?></span></li>
        <li><strong>02</strong><span><?= e(t('campaign.detail.trust_budget', $lang)) ?></span></li>
        <li><strong>03</strong><span><?= e(t('campaign.detail.trust_reward', $lang)) ?></span></li>
      </ol>
      <p class="zs-campaign-proof-copy"><?= e(t((string)($campaign['proof_key'] ?? 'campaign.proof.pending'), $lang)) ?></p>
    </aside>
  </div>
</article>
<script>
(function(){
  const root=document.querySelector('[data-campaign-delivery]');
  if(!root)return;
  const token=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const send=eventType=>{const body=new URLSearchParams({campaign_id:String(root.dataset.campaignDelivery),event_type:eventType});fetch('/campaign/delivery',{method:'POST',headers:{'X-CSRF-TOKEN':token,'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:body.toString(),keepalive:true}).catch(()=>{});};
  send('impression');
  root.querySelector('[data-campaign-view-media]')?.addEventListener('play',()=>send('start'),{once:true});
})();
</script>

<?php if ($isView && is_array($view_proof ?? null)): ?>
<script>
(function () {
  const form = document.querySelector('[data-campaign-view-form]');
  if (!form) return;
  const minimum = Math.max(1, Number(form.dataset.minSeconds || 1));
  const secondsInput = form.querySelector('[data-visible-seconds]');
  const visibleInput = form.querySelector('[data-visible-state]');
  const counter = form.querySelector('[data-view-counter]');
  const progress = form.querySelector('[data-view-progress]');
  const submit = form.querySelector('[data-view-submit]');
  const media = document.querySelector('[data-campaign-view-media]');
  let visibleSeconds = 0;
  const waitingTemplate = <?= json_encode(t('campaign.action.view_wait', $lang), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const readyText = <?= json_encode(t('campaign.action.view_ready', $lang), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.setInterval(function () {
    if (document.visibilityState !== 'visible' || !media || media.paused || media.ended) return;
    visibleSeconds += 1;
    secondsInput.value = String(visibleSeconds);
    visibleInput.value = '1';
    const remaining = Math.max(0, minimum - visibleSeconds);
    progress.style.width = String(Math.min(100, Math.round((visibleSeconds / minimum) * 100))) + '%';
    if (remaining === 0) {
      counter.textContent = readyText;
      submit.disabled = false;
    } else {
      counter.textContent = waitingTemplate.replace('{seconds}', String(remaining));
    }
  }, 1000);
  document.addEventListener('visibilitychange', function () {
    visibleInput.value = document.visibilityState === 'visible' ? '1' : '0';
  });
})();
</script>
<?php endif; ?>
