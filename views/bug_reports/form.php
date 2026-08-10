<?php
$lang = (string)($current_language ?? 'pl');
$bugRewardPoints = max(0, (int)($bug_reward_points ?? 0));
?>
<section class="zs-bug-report-page">
  <header class="zs-bug-report-head">
    <p class="kicker"><?= e(t('bug_report.kicker', $lang)) ?></p>
    <h1><?= e(t('bug_report.title', $lang)) ?></h1>
    <p><?= e(t('bug_report.intro', $lang)) ?></p>
    <?php if ($bugRewardPoints > 0): ?>
      <div class="zs-bug-reward-value">
        <span><?= e(t('bug_report.reward.label', $lang)) ?></span>
        <strong><?= $bugRewardPoints ?> TT</strong>
        <small><?= e(t('bug_report.reward.when', $lang)) ?></small>
      </div>
    <?php endif; ?>
  </header>

  <?php if (empty($_SESSION['user_id'])): ?>
    <div class="paywall-note"><p><?= e(t('bug_report.login_required', $lang)) ?></p><a class="btn-red" href="<?= e(public_language_url($lang, '/login')) ?>"><?= e(t('campaign.action.login', $lang)) ?></a></div>
  <?php else: ?>
    <form method="post" action="<?= e(public_language_url($lang, '/report-bug')) ?>" enctype="multipart/form-data" class="form-card zs-bug-report-form" data-bug-report-form>
      <?= csrf_field() ?>
      <label class="field"><span><?= e(t('bug_report.field.page', $lang)) ?></span><input type="url" name="page_url" required maxlength="1000" value="<?= e((string)($suggested_url ?? '')) ?>" placeholder="https://..."></label>
      <label class="field"><span><?= e(t('bug_report.field.title', $lang)) ?></span><textarea name="description" required rows="5" maxlength="5000"></textarea></label>
      <label class="field"><span><?= e(t('bug_report.field.steps', $lang)) ?></span><textarea name="details" required rows="4" maxlength="10000"></textarea></label>
      <div class="zs-upload-module">
        <span class="field-label"><?= e(t('bug_report.field.attachment', $lang)) ?></span>
        <input type="file" name="attachment" id="bug-attachment" accept="image/jpeg,image/png,image/webp" required hidden>
        <button type="button" class="zs-upload-dropzone zs-simple-dropzone" data-bug-file-select>
          <span><?= e(t('bug_report.attachment_hint', $lang)) ?></span>
          <strong><?= e(t('bug_report.field.attachment', $lang)) ?></strong>
          <img alt="<?= e(t('bug_report.attachment_preview', $lang)) ?>" data-bug-preview hidden>
        </button>
        <div class="zs-upload-status" data-bug-file-name role="status" aria-live="polite"></div>
      </div>
      <button class="btn-red" type="submit"><?= e(t('bug_report.submit', $lang)) ?></button>
    </form>
  <?php endif; ?>
</section>
<script>
(function(){
  const input=document.getElementById('bug-attachment');
  const button=document.querySelector('[data-bug-file-select]');
  if(!input||!button)return;
  const form=button.closest('form');
  const name=document.querySelector('[data-bug-file-name]');
  const preview=document.querySelector('[data-bug-preview]');
  button.addEventListener('click',()=>input.click());
  const previewFile=()=>{
    const file=input.files&&input.files[0];
    if(!file){button.classList.remove('has-preview');preview.hidden=true;return;}
    if(!['image/jpeg','image/png','image/webp'].includes(file.type)){
      input.value='';
      button.classList.remove('has-preview');
      preview.hidden=true;
      name.textContent=<?= json_encode(t('bug_report.attachment_invalid', $lang), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      return;
    }
    input.setCustomValidity('');
    name.textContent=file.name;
    preview.src=URL.createObjectURL(file);
    preview.hidden=false;
    button.classList.add('has-preview');
  };
  button.addEventListener('dragover',event=>{event.preventDefault();button.classList.add('dragover');});
  button.addEventListener('dragleave',()=>button.classList.remove('dragover'));
  button.addEventListener('drop',event=>{event.preventDefault();button.classList.remove('dragover');if(!event.dataTransfer?.files?.length)return;const transfer=new DataTransfer();transfer.items.add(event.dataTransfer.files[0]);input.files=transfer.files;previewFile();});
  input.addEventListener('change',previewFile);
  input.addEventListener('invalid',event=>{event.preventDefault();name.textContent=<?= json_encode(t('bug_report.attachment_required', $lang), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;button.focus();});
  form?.addEventListener('submit',()=>{if(input.files&&input.files.length>0)input.setCustomValidity('');});
})();
</script>
