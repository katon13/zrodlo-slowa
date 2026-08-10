<?php
$authorArticleStatusLabels = [
    'draft' => t('article.status.draft'),
    'submitted' => t('article.status.submitted'),
    'review' => t('article.status.review'),
    'approved' => t('article.status.approved'),
    'rejected' => t('article.status.rejected'),
    'published' => t('article.status.published'),
    'archived' => t('article.status.archived'),
];
?>

<section class="author-hero-panel">
  <div class="author-hero-with-avatar">
    <?php if (!empty($current_user_avatar)): ?>
      <div class="author-hero-avatar">
        <img src="<?= e($current_user_avatar) ?>?t=<?= strtotime($current_user_avatar_updated_at ?? 'now') ?>" alt="">
      </div>
    <?php endif; ?>
    <div>
      <p class="kicker"><?= t('author.dashboard.permission_writing') ?></p>
      <h1><?= t('author.dashboard.title') ?></h1>
      <p><?= t('author.dashboard.desc') ?></p>
    </div>
  </div>
  <div class="author-balance-card">
    <span><?= t('author.dashboard.author_balance') ?></span>
    <?php if (!empty($author_state['wallet_enabled']) && !empty($wallet)): ?>
      <strong><?= number_format(($wallet['available_minor'] ?? 0)/100, 2, ',', ' ') ?> PLN</strong>
    <?php else: ?>
      <strong><?= t('author.dashboard.inactive') ?></strong>
      <small><?= t('author.dashboard.wallet_not_active') ?></small>
    <?php endif; ?>
  </div>
</section>

<section class="author-permissions-strip">
  <span class="<?= !empty($author_state['can_write']) ? 'is-on' : 'is-off' ?>"><?= t('author.dashboard.permission_writing') ?></span>
  <span class="<?= !empty($author_state['talent_enabled']) ? 'is-on' : 'is-off' ?>"><?= t('author.dashboard.permission_talent') ?></span>
  <span class="<?= !empty($author_state['wallet_enabled']) ? 'is-on' : 'is-off' ?>"><?= t('author.dashboard.permission_wallet') ?></span>
  <span class="<?= !empty($author_state['payout_enabled']) ? 'is-on' : 'is-off' ?>"><?= t('author.dashboard.permission_payout') ?></span>
</section>

<?php if (!empty($author_state['is_article_submit_blocked'])): ?>
<section class="pending-author-notice">
  <p class="kicker"><?= e(t('ui.author.dashboard.blokada_redakcyjna')) ?></p>
  <h2><?= e(t('ui.author.dashboard.wysyanie_tekstow_do_redakcji_jest_czasowo_zablokowane')) ?></h2>
  <p><?= e(t('ui.author.dashboard.blokada_obowiazuje_do')) ?> <strong><?= e((string)($author_state['article_submit_blocked_until'] ?? '')) ?></strong>.</p>
</section>
<?php endif; ?>

<?php if (!empty($author_state['is_pending_author'])): ?>
<section class="pending-author-notice">
  <p class="kicker"><?= t('author.dashboard.inactive') ?></p>
  <h2><?= t('author.dashboard.pending_approval_title') ?></h2>
  <p><?= t('author.dashboard.pending_approval_desc') ?></p>
</section>
<?php elseif (empty($author_state['can_write'])): ?>
<section class="pending-author-notice">
  <p class="kicker"><?= t('author.dashboard.permission_writing') ?> <?= t('author.dashboard.inactive') ?></p>
  <h2><?= t('author.dashboard.writing_inactive_title') ?></h2>
  <p><?= t('author.dashboard.writing_inactive_desc') ?></p>
</section>
<?php endif; ?>

<?php if (!empty($author_state['can_write'])): ?>
<section class="author-action-strip">
  <div>
    <h2><?= t('author.dashboard.add_article_title') ?></h2>
    <p><?= t('author.dashboard.add_article_desc') ?></p>
  </div>
  <a class="btn-red" href="/author/articles/create"><?= t('author.dashboard.add_article_title') ?></a>
</section>
<?php endif; ?>

<section class="author-section-head">
  <h2><?= t('author.dashboard.my_articles') ?></h2>
  <span><?= e(str_replace('{count}', (string)count($articles), t('author.dashboard.items_count'))) ?></span>
</section>

<?php if (empty($articles)): ?>
  <section class="empty-state">
    <?php if (!empty($author_state['is_pending_author'])): ?>
      <h3><?= t('author.dashboard.no_articles') ?></h3>
      <p><?= t('author.dashboard.pending_approval_desc') ?></p>
    <?php elseif (empty($author_state['can_write'])): ?>
      <h3><?= t('author.dashboard.no_articles') ?></h3>
      <p><?= t('author.dashboard.writing_inactive_desc') ?></p>
    <?php else: ?>
      <h3><?= t('author.dashboard.no_articles') ?></h3>
      <p><?= t('author.dashboard.add_article_desc') ?></p>
      <a class="btn-line" href="/author/articles/create"><?= t('author.dashboard.add_article_title') ?></a>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="author-articles-list">
    <?php foreach ($articles as $a): ?>
      <article class="author-article-card">
        <?php if (!empty($a['main_image'])): ?>
          <img src="<?= e($a['main_image']) ?>" alt="" class="author-article-thumb" style="object-position: center <?= (int)($a['main_image_position'] ?? 50) ?>%">
        <?php else: ?>
          <div class="author-article-thumb placeholder"><?= e(t('article.type.text')) ?></div>
        <?php endif; ?>

        <div class="author-article-main">
          <div class="author-article-topline">
            <span class="status-pill status-<?= e($a['status']) ?>"><?= e($authorArticleStatusLabels[(string)$a['status']] ?? (string)$a['status']) ?></span>
            <?php if (!empty($a['proofread_at'])): ?>
              <span class="status-pill status-proofread"><?= e(t('admin.dashboard.korekta')) ?></span>
            <?php endif; ?>
          </div>
          <h3><?= e($a['title']) ?></h3>
          <?php if (!empty($a['lead'])): ?>
            <p><?= e(mb_substr((string)$a['lead'], 0, 180)) ?><?= mb_strlen((string)$a['lead']) > 180 ? '…' : '' ?></p>
          <?php endif; ?>
        </div>

        <div class="author-article-actions">
          <?php if (!empty($author_state['can_write'])): ?>
          <a class="btn-line" href="/author/articles/edit?id=<?= (int)$a['id'] ?>"><?= t('author.dashboard.edit') ?></a>
          <?php if (in_array($a['status'], ['draft','rejected'], true)): ?>
            <?php if (empty($author_state['is_article_submit_blocked'])): ?>
              <form class="inline ajax-form" method="post" action="<?= e(public_language_url($current_language, '/author/articles/submit')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="_lang" value="<?= e($current_language) ?>">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn-red" type="submit"><?= t('author.dashboard.submit_to_moderation') ?></button>
              </form>
            <?php else: ?>
              <span class="status-pill status-rejected"><?= e(t('ui.author.dashboard.wysyanie_zablokowane')) ?></span>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ((string)$a['status'] === 'approved' && !empty($author_state['can_publish'])): ?>
            <form class="inline ajax-form" method="post" action="<?= e(public_language_url($current_language, '/author/articles/publish')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="_lang" value="<?= e($current_language) ?>">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="btn-red" type="submit"><?= e(t('ui.author.dashboard.publikuj_przez_3dors_author')) ?></button>
            </form>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<style>
.status-proofread { border-color: #b91c1c; color: #b91c1c; background: #fffafa; }

/* AJAX Messages */
.zs-local-msg { font-size: 11px; margin-top: 4px; padding: 2px 6px; border-radius: 4px; transition: opacity 0.5s; display: block; width: 100%; clear: both; }
.zs-local-msg.success { background: #f0fff4; color: #2f855a; border: 1px solid #c6f6d5; }
.zs-local-msg.error { background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const uiText = <?= json_encode([
      'send' => t('author.dashboard.ajax.send'),
      'sending' => t('author.dashboard.ajax.sending'),
      'sent' => t('author.dashboard.ajax.sent'),
      'approval_required' => t('author.dashboard.ajax.approval_required'),
      'submitted' => t('article.status.submitted'),
      'save_error' => t('author.dashboard.ajax.save_error'),
      'connection_error' => t('author.article.connection_error'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  function showLocalMessage(element, text, type) {
    let container = element.parentNode;
    let msg = container.querySelector('.zs-local-msg');
    if (!msg) {
      msg = document.createElement('div');
      msg.className = 'zs-local-msg';
      container.appendChild(msg);
    }
    msg.textContent = text;
    msg.className = 'zs-local-msg ' + type;
    msg.style.opacity = '1';
    setTimeout(() => { 
      msg.style.opacity = '0'; 
      setTimeout(() => msg.remove(), 500); 
    }, 4000);
  }

  document.querySelectorAll('.ajax-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      const originalText = button ? button.textContent : uiText.send;
      
      if (button) {
        button.disabled = true;
        button.textContent = uiText.sending;
      }

      try {
        const formData = new FormData(form);
        const response = await fetch(form.action, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': '<?php echo csrf_token(); ?>'
          },
          body: formData
        });

        const data = await response.json();
        
        if (button) {
          button.disabled = false;
          button.textContent = originalText;
        }

        if (data.success) {
          showLocalMessage(button, data.message || uiText.sent, 'success');
          const card = form.closest('.author-article-card');
          if (card) {
            const pill = card.querySelector('.status-pill');
            if (pill) {
              if (data.approval_required) {
                pill.textContent = uiText.approval_required;
                pill.className = 'status-pill status-draft';
              } else {
                pill.textContent = uiText.submitted;
                pill.className = 'status-pill status-submitted';
              }
            }
            form.remove();
          }
        } else {
          showLocalMessage(button, data.message || uiText.save_error, 'error');
        }
      } catch (error) {
        if (button) {
          button.disabled = false;
          button.textContent = originalText;
          showLocalMessage(button, uiText.connection_error, 'error');
        }
      }
    });
  });
});
</script>
