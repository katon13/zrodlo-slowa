<?php $lang = (string)($current_language ?? 'pl'); ?>
<section class="admin-page-head">
  <p class="kicker"><?= e(t('survey.index.kicker', $lang)) ?></p>
  <h1><?= e(t('survey.index.title', $lang)) ?></h1>
  <p><?= e(t('survey.index.intro', $lang)) ?></p>
</section>

<section class="articles-grid">
  <?php if (empty($surveys)): ?>
    <div class="form-card"><p><?= e(t('survey.index.empty', $lang)) ?></p></div>
  <?php else: ?>
    <?php foreach ($surveys as $survey): ?>
      <article class="article-card">
        <div class="kicker"><?= e(t('survey.type.' . (string)$survey['type'], $lang)) ?></div>
        <h2><?= e($survey['title']) ?></h2>
        <p><?= e(mb_strimwidth((string)($survey['description'] ?? ''), 0, 180, '…', 'UTF-8')) ?></p>
        <div class="wallet-row"><span><?= e(t('survey.reward', $lang)) ?></span><strong><?= (int)($survey_reward_points ?? 0) ?> TT</strong></div>
        <div class="wallet-row"><span><?= e(t('survey.answers', $lang)) ?></span><strong><?= (int)($survey['responses_count'] ?? 0) ?><?= !empty($survey['max_responses']) ? ' / ' . (int)$survey['max_responses'] : '' ?></strong></div>
        <p><a class="btn-red" href="<?= e(public_language_url($lang, '/survey?id=' . (int)$survey['id'])) ?>"><?= e(t('survey.take_part', $lang)) ?></a></p>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
