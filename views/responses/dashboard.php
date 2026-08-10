<?php
$responses = is_array($responses ?? null) ? $responses : [];
$eligibility = is_array($eligibility ?? null) ? $eligibility : [];
$submissionDepositPoints = max(0, (int)($submission_deposit_points ?? 0));
$language = public_language();
$statusLabels = [
    'draft' => t('response.status.draft', $language),
    'submitted' => t('response.status.submitted', $language),
    'review' => t('response.status.review', $language),
    'approved' => t('response.status.approved', $language),
    'published' => t('response.status.published', $language),
    'rejected' => t('response.status.rejected', $language),
    'archived' => t('response.status.archived', $language),
];
$depositStatusLabels = [
    'not_required' => t('response.deposit_status.not_required', $language),
    'held' => t('response.deposit_status.held', $language),
    'forfeited' => t('response.deposit_status.forfeited', $language),
    'refunded' => t('response.deposit_status.refunded', $language),
];
?>

<section class="zs-response-hub">
  <header class="zs-response-hub-head">
    <div>
      <p class="kicker"><?= e(t('response.dashboard.kicker', $language)) ?></p>
      <h1><?= e(t('response.dashboard.title', $language)) ?></h1>
      <p><?= e(t('response.dashboard.intro', $language)) ?></p>
    </div>
    <span class="zs-response-principle"><?= e(t('response.dashboard.principle', $language)) ?></span>
  </header>

  <?php if (!empty($flash_success)): ?><div class="inline-notice success"><?= e($flash_success) ?></div><?php endif; ?>
  <?php if (!empty($flash_error)): ?><div class="inline-notice error"><?= e($flash_error) ?></div><?php endif; ?>

  <div class="zs-response-rules">
    <article><strong><?= e(t('response.dashboard.rule_publish_title', $language)) ?></strong><span><?= e(t('response.dashboard.rule_publish_body', $language)) ?></span></article>
    <?php if ($submissionDepositPoints > 0): ?>
      <article><strong><?= e(str_replace('{points}', (string)$submissionDepositPoints, t('response.dashboard.deposit_title', $language))) ?></strong><span><?= e(t('response.dashboard.deposit_body', $language)) ?></span></article>
    <?php endif; ?>
  </div>

  <?php if (empty($eligibility['can_respond'])): ?>
    <div class="zs-response-access-note">
      <strong><?= e(t('response.dashboard.access_title', $language)) ?></strong>
      <p><?= e(t('response.dashboard.access_body', $language)) ?></p>
    </div>
  <?php endif; ?>

  <section class="zs-response-own-list">
    <div class="zs-response-list-head"><div><p class="kicker"><?= e(t('response.dashboard.workflow_kicker', $language)) ?></p><h2><?= e(t('response.dashboard.workflow_title', $language)) ?></h2></div><a class="btn-line" href="<?= e(public_language_url($language, '/articles')) ?>"><?= e(t('response.dashboard.choose_article', $language)) ?></a></div>
    <?php if ($responses === []): ?>
      <div class="zs-response-empty"><strong><?= e(t('response.dashboard.empty_title', $language)) ?></strong><p><?= e(t('response.dashboard.empty_body', $language)) ?></p></div>
    <?php else: ?>
      <div class="zs-response-dashboard-list">
      <?php foreach ($responses as $response): ?>
        <?php $editable = in_array((string)$response['status'], ['draft','rejected','published'], true); ?>
        <article>
          <div><span class="zs-response-status"><?= e($statusLabels[(string)$response['status']] ?? (string)$response['status']) ?></span><h3><?= e((string)$response['title']) ?></h3><p><?= e(t('response.dashboard.response_to', $language)) ?>: <a href="<?= e(public_language_url($language, '/article?id=' . (int)$response['response_to_article_id'])) ?>"><?= e((string)$response['source_title']) ?></a></p><?php if (!empty($response['response_deposit_status'])): ?><small><?= e(t('response.dashboard.deposit_status', $language)) ?>: <?= e($depositStatusLabels[(string)$response['response_deposit_status']] ?? (string)$response['response_deposit_status']) ?><?php if ((int)($response['response_deposit_points'] ?? 0) > 0): ?> · <?= (int)$response['response_deposit_points'] ?> TT<?php endif; ?></small><?php endif; ?></div>
          <div class="zs-response-dashboard-actions">
            <?php if ((string)$response['status'] === 'published'): ?><a class="btn-line compact" href="<?= e(public_language_url($language, '/article?id=' . (int)$response['id'])) ?>"><?= e(t('response.dashboard.view_publication', $language)) ?></a><?php endif; ?>
            <?php if ($editable): ?><a class="btn-red compact" href="<?= e(public_language_url($language, '/opinie/edytuj?id=' . (int)$response['id'])) ?>"><?= e(t('response.dashboard.edit', $language)) ?></a><?php endif; ?>
            <?php if (in_array((string)$response['status'], ['draft','rejected'], true)): ?>
              <form method="post" action="<?= e(public_language_url($language, '/opinie/wyslij')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$response['id'] ?>"><button class="btn-line compact" type="submit"><?= e(t('response.dashboard.submit', $language)) ?></button></form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</section>
