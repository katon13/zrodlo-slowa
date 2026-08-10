<?php $lang = (string)($current_language ?? 'pl'); ?>
<section class="zs-bug-report-page">
  <header class="zs-bug-report-head">
    <p class="kicker"><?= e(t('bug_report.kicker', $lang)) ?></p>
    <h1><?= e(t('bug_report.title', $lang)) ?></h1>
    <p><?= e(t('bug_report.intro', $lang)) ?></p>
  </header>

  <?php if (empty($_SESSION['user_id'])): ?>
    <div class="paywall-note"><p><?= e(t('bug_report.login_required', $lang)) ?></p><a class="btn-red" href="<?= e(public_language_url($lang, '/login')) ?>"><?= e(t('campaign.action.login', $lang)) ?></a></div>
  <?php else: ?>
    <form method="post" action="<?= e(public_language_url($lang, '/report-bug')) ?>" enctype="multipart/form-data" class="form-card zs-bug-report-form" data-bug-report-form>
      <?= csrf_field() ?>
      <label class="field"><span><?= e(t('bug_report.field.page', $lang)) ?></span><input type="url" name="page_url" required maxlength="1000" value="<?= e((string)($suggested_url ?? '')) ?>" placeholder="https://..."></label>
      <label class="field"><span><?= e(t('bug_report.field.title', $lang)) ?></span><textarea name="description" required rows="5" maxlength="5000"></textarea></label>
      <label class="field"><span><?= e(t('bug_report.field.steps', $lang)) ?></span><textarea name="details" rows="4" maxlength="10000"></textarea></label>
      <div class="zs-upload-module">
        <span class="field-label"><?= e(t('bug_report.field.attachment', $lang)) ?></span>
        <input type="file" name="attachment" id="bug-attachment" accept="image/jpeg,image/png,image/webp" hidden>
        <button type="button" class="zs-upload-dropzone zs-simple-dropzone" data-bug-file-select>
          <span><?= e(t('bug_report.attachment_hint', $lang)) ?></span>
          <strong><?= e(t('bug_report.field.attachment', $lang)) ?></strong>
          <img alt="<?= e(t('bug_report.attachment_preview', $lang)) ?>" data-bug-preview hidden>
        </button>
        <div class="zs-upload-status" data-bug-file-name></div>
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
  const name=document.querySelector('[data-bug-file-name]');
  const preview=document.querySelector('[data-bug-preview]');
  button.addEventListener('click',()=>input.click());
  const previewFile=()=>{
    const file=input.files&&input.files[0];
    if(!file)return;
    name.textContent=file.name;
    preview.src=URL.createObjectURL(file);
    preview.hidden=false;
  };
  button.addEventListener('dragover',event=>{event.preventDefault();button.classList.add('dragover');});
  button.addEventListener('dragleave',()=>button.classList.remove('dragover'));
  button.addEventListener('drop',event=>{event.preventDefault();button.classList.remove('dragover');if(!event.dataTransfer?.files?.length)return;const transfer=new DataTransfer();transfer.items.add(event.dataTransfer.files[0]);input.files=transfer.files;previewFile();});
  input.addEventListener('change',previewFile);
})();
</script>
