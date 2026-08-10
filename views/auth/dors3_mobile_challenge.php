<?php
$dors3Language = function_exists('public_language') ? public_language() : 'pl';
$dors3StatusLabels = [];
foreach (['pending', 'approved', 'rejected', 'expired', 'cancelled'] as $machineStatus) {
    $dors3StatusLabels[$machineStatus] = \App\Services\Dors3UiText::option('statuses', $machineStatus, $dors3Language);
}
?>
<section class="auth-card">
  <p class="kicker"><?= e(dors3_t('auth.kicker', [], $dors3Language)) ?></p>
  <h1><?= e(dors3_t('auth.title', ['variant' => strtoupper((string)$application_variant)], $dors3Language)) ?></h1>
  <p><?= e(dors3_t('auth.description', [], $dors3Language)) ?></p>
  <dl>
    <dt><?= e(dors3_t('auth.request', [], $dors3Language)) ?></dt><dd><code><?= e((string)$approval_request_id) ?></code></dd>
    <dt><?= e(dors3_t('auth.valid_until', [], $dors3Language)) ?></dt><dd><time datetime="<?= e(date(DATE_ATOM, (int)$expires_at)) ?>"><?= e(date('H:i:s', (int)$expires_at)) ?></time></dd>
  </dl>
  <p id="dors3-mobile-status" role="status"><?= e(dors3_t('auth.waiting', [], $dors3Language)) ?></p>
  <form id="dors3-mobile-complete" method="post" action="/login/3dors-mobile/complete">
    <?= csrf_field() ?>
    <button type="submit"><?= e(dors3_t('auth.check', [], $dors3Language)) ?></button>
  </form>
</section>

<script>
(() => {
  const statusNode = document.getElementById('dors3-mobile-status');
  const form = document.getElementById('dors3-mobile-complete');
  const endpoint = <?= json_encode('/auth/3dors/mobile/status/' . (string)$approval_request_id, JSON_UNESCAPED_SLASHES) ?>;
  const statusLabels = <?= json_encode($dors3StatusLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const statusTemplate = <?= json_encode(dors3_t('auth.status', ['status' => '__STATUS__'], $dors3Language), JSON_UNESCAPED_UNICODE) ?>;
  const temporaryProblem = <?= json_encode(dors3_t('common.temporary_problem', [], $dors3Language), JSON_UNESCAPED_UNICODE) ?>;
  let stopped = false;
  async function poll() {
    if (stopped) return;
    try {
      const response = await fetch(endpoint, {credentials: 'same-origin', cache: 'no-store'});
      const data = await response.json();
      statusNode.textContent = data.status === 'pending'
        ? statusLabels.pending
        : statusTemplate.replace('__STATUS__', statusLabels[data.status] || statusLabels.pending);
      if (data.status === 'approved') {
        stopped = true;
        form.submit();
        return;
      }
      if (['rejected', 'expired', 'cancelled'].includes(data.status)) {
        stopped = true;
        return;
      }
    } catch (_) {
      statusNode.textContent = temporaryProblem;
    }
    window.setTimeout(poll, 2000);
  }
  poll();
})();
</script>
