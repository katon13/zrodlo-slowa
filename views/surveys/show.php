<?php $lang = (string)($current_language ?? 'pl'); ?>
<section class="admin-page-head">
  <p class="kicker"><?= e(t('survey.type.' . (string)$survey['type'], $lang)) ?></p>
  <h1><?= e($survey['title']) ?></h1>
  <p><?= nl2br(e((string)($survey['description'] ?? ''))) ?></p>
</section>

<section class="form-card">
  <div class="wallet-row"><span><?= e(t('survey.reward', $lang)) ?></span><strong><?= (int)($survey_reward_points ?? 0) ?> TT</strong></div>
  <div class="wallet-row"><span><?= e(t('survey.answers', $lang)) ?></span><strong><?= (int)($survey['responses_count'] ?? 0) ?><?= !empty($survey['max_responses']) ? ' / ' . (int)$survey['max_responses'] : '' ?></strong></div>
</section>

<?php if (!empty($already_answered)): ?>
  <section class="notice success"><?= e(t('survey.completed', $lang)) ?></section>
<?php elseif (empty($_SESSION['user_id'])): ?>
  <section class="paywall-note">
    <h2><?= e(t('survey.login_title', $lang)) ?></h2>
    <p><?= e(t('survey.login_message', $lang)) ?></p>
    <p><a class="btn-red" href="<?= e(public_language_url($lang, '/login')) ?>"><?= e(t('campaign.action.login', $lang)) ?></a></p>
  </section>
<?php elseif (empty($questions)): ?>
  <section class="notice error"><?= e(t('survey.no_questions', $lang)) ?></section>
<?php else: ?>
  <section>
    <form method="post" action="<?= e(public_language_url($lang, '/survey/submit')) ?>" class="zs-survey-form vertical">
      <?= csrf_field() ?>
      <input type="hidden" name="survey_id" value="<?= (int)$survey['id'] ?>">
      <input type="hidden" name="answer_seconds" value="0" data-answer-seconds>
      <?php foreach ($questions as $q): ?>
        <?php $options = json_decode((string)($q['options_json'] ?? '[]'), true) ?: []; ?>
        <div style="margin-bottom: 32px; padding-bottom: 24px; border-bottom: 1px solid var(--zs-line);">
          <span class="survey-q-title"><?= e($q['question_text']) ?><?= (int)$q['is_required'] === 1 ? ' <span style="color: var(--zs-red);">*</span>' : '' ?></span>
          
          <div style="display: flex; flex-direction: column; gap: 12px;">
          <?php if ($q['question_type'] === 'text'): ?>
            <textarea name="answers[<?= (int)$q['id'] ?>]" rows="4" placeholder="<?= e(t('survey.text_placeholder', $lang)) ?>"></textarea>
          <?php elseif ($q['question_type'] === 'multiple_choice'): ?>
            <?php foreach ($options as $option): ?>
              <label style="flex-direction: row !important; align-items: center; gap: 10px; cursor: pointer;">
                <input type="checkbox" name="answers[<?= (int)$q['id'] ?>][]" value="<?= e($option) ?>" style="width: 20px !important; height: 20px !important;">
                <span style="text-transform: none !important; font-size: 15px !important; font-weight: 400 !important; color: var(--zs-black) !important; letter-spacing: 0 !important;"><?= e($option) ?></span>
              </label>
            <?php endforeach; ?>
          <?php else: ?>
            <?php foreach ($options as $option): ?>
              <label style="flex-direction: row !important; align-items: center; gap: 10px; cursor: pointer;">
                <input type="radio" name="answers[<?= (int)$q['id'] ?>]" value="<?= e($option) ?>" style="width: 20px !important; height: 20px !important;">
                <span style="text-transform: none !important; font-size: 15px !important; font-weight: 400 !important; color: var(--zs-black) !important; letter-spacing: 0 !important;"><?= e($option) ?></span>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div style="padding-top: 10px;">
        <button class="btn-red" type="submit" style="padding: 18px 40px; font-size: 16px;"><?= e(t('survey.submit', $lang)) ?></button>
      </div>
    </form>
  </section>
<?php endif; ?>

<script>
(function(){
  const started = Date.now();
  const input = document.querySelector('[data-answer-seconds]');
  if (!input) return;
  const form = input.closest('form');
  if (!form) return;
  form.addEventListener('submit', function(){
    input.value = String(Math.max(0, Math.floor((Date.now() - started) / 1000)));
  });
})();
</script>
