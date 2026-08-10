<?php
$slotCampaigns = is_array($placement_campaigns ?? null) ? $placement_campaigns : [];
$slotLanguage = (string)($current_language ?? 'pl');
?>
<?php if ($slotCampaigns !== []): ?>
<aside class="zs-campaign-slot" aria-label="<?= e(t('campaign.slot.promoted', $slotLanguage)) ?>">
  <?php foreach ($slotCampaigns as $slotCampaign): ?>
    <article class="zs-campaign-placement" data-campaign-delivery="<?= (int)$slotCampaign['id'] ?>">
      <span class="zs-campaign-placement-label"><?= e(t('campaign.slot.promoted', $slotLanguage)) ?></span>
      <?php if ((string)$slotCampaign['type'] === 'ad_click' && !empty($slotCampaign['creative_path'])): ?>
        <a href="/campaign/go?id=<?= (int)$slotCampaign['id'] ?>" class="zs-campaign-banner-link">
          <img src="<?= e((string)$slotCampaign['creative_path']) ?>" alt="<?= e((string)$slotCampaign['name']) ?>">
        </a>
      <?php elseif ((string)$slotCampaign['type'] === 'ad_view' && !empty($slotCampaign['creative_path'])): ?>
        <video controls muted playsinline preload="metadata" data-campaign-start>
          <source src="<?= e((string)$slotCampaign['creative_path']) ?>" type="<?= e((string)$slotCampaign['creative_mime']) ?>">
        </video>
        <div class="zs-campaign-placement-copy"><strong><?= e((string)$slotCampaign['name']) ?></strong><a href="<?= e(public_language_url($slotLanguage, '/campaign?id=' . (int)$slotCampaign['id'])) ?>"><?= e(t('campaign.slot.video', $slotLanguage)) ?></a></div>
      <?php else: ?>
        <div class="zs-campaign-placement-copy"><strong><?= e((string)$slotCampaign['name']) ?></strong><p><?= e((string)($slotCampaign['description'] ?? '')) ?></p><a href="<?= e(public_language_url($slotLanguage, (string)$slotCampaign['action_url'])) ?>"><?= e(t('campaign.slot.open', $slotLanguage)) ?></a></div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</aside>
<script>
(function(){
  const token=document.querySelector('meta[name="csrf-token"]')?.content||'';
  function send(id,type){
    const body=new URLSearchParams({campaign_id:String(id),event_type:type});
    fetch('/campaign/delivery',{method:'POST',headers:{'X-CSRF-TOKEN':token,'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:body.toString(),keepalive:true}).catch(()=>{});
  }
  document.querySelectorAll('[data-campaign-delivery]').forEach(card=>{
    const id=Number(card.dataset.campaignDelivery||0);
    if(id>0)send(id,'impression');
    const video=card.querySelector('[data-campaign-start]');
    if(video)video.addEventListener('play',()=>send(id,'start'),{once:true});
  });
})();
</script>
<?php endif; ?>
