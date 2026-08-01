<?php
$article = is_array($article ?? null) ? $article : [];
$has_access = (bool)($has_access ?? false);
$current_language = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$uiLanguage = strtolower($current_language);
$currentLanguage = $uiLanguage;

$isPaid = (($article['access_mode'] ?? 'free') === 'paid');
$isPremium = (int)($article['is_premium'] ?? 0) === 1;
$isUnique = (int)($article['is_unique'] ?? 0) === 1;
$pricingStatus = (string)($article['pricing_status'] ?? ($isPaid ? 'priced' : 'free'));
$priceMinor = (int)($article['price_minor'] ?? 0);
$sourceCurrency = strtoupper((string)($article['currency'] ?? 'PLN'));
if (!preg_match('/^[A-Z]{3}$/', $sourceCurrency)) {
    $sourceCurrency = 'PLN';
}

$currencyService = class_exists('App\\Services\\CurrencyRateService') ? new \App\Services\CurrencyRateService() : null;
$displayCurrency = strtoupper((string)($effective_display_currency ?? $sourceCurrency));
if (!preg_match('/^[A-Z]{3}$/', $displayCurrency)) {
    $displayCurrency = $sourceCurrency;
}

$onePlnInDisplayCurrency = null;
if ($currencyService && $displayCurrency !== 'PLN') {
    $onePlnInDisplayCurrency = $currencyService->convertPlnToCurrency(1.0, $displayCurrency);
    if ($onePlnInDisplayCurrency === null || $onePlnInDisplayCurrency <= 0) {
        $displayCurrency = $sourceCurrency;
        $onePlnInDisplayCurrency = null;
    }
}
$plnPerDisplayUnit = ($displayCurrency === 'PLN' || !$onePlnInDisplayCurrency) ? 1.0 : (1.0 / $onePlnInDisplayCurrency);

$formatMoney = static function (int $minor, string $currency) use ($currencyService, $uiLanguage): string {
    $amount = $minor / 100;
    $currency = strtoupper($currency);
    if ($currencyService) {
        return $currencyService->formatSimple($amount, $currency, $uiLanguage);
    }
    $decimalSeparator = in_array($uiLanguage, ['pl', 'de', 'fr', 'it', 'es'], true) ? ',' : '.';
    return number_format($amount, 2, $decimalSeparator, ' ') . ' ' . $currency;
};

$formatPlnMinorInDisplayCurrency = static function (int $minor) use ($currencyService, $uiLanguage, $displayCurrency, $sourceCurrency, $formatMoney): string {
    $amountPln = $minor / 100;
    if ($displayCurrency === 'PLN') {
        return $formatMoney($minor, 'PLN');
    }
    if ($currencyService) {
        $converted = $currencyService->convertPlnToCurrency($amountPln, $displayCurrency);
        if ($converted !== null) {
            return $currencyService->formatSimple($converted, $displayCurrency, $uiLanguage);
        }
    }
    return $formatMoney($minor, $sourceCurrency);
};

$formatArticleMoney = static function (int $minor) use ($currencyService, $uiLanguage, $displayCurrency, $sourceCurrency, $formatMoney): string {
    $amount = $minor / 100;
    if ($displayCurrency === $sourceCurrency) {
        return $formatMoney($minor, $sourceCurrency);
    }
    if ($sourceCurrency === 'PLN' && $currencyService) {
        $converted = $currencyService->convertPlnToCurrency($amount, $displayCurrency);
        if ($converted !== null) {
            return $currencyService->formatSimple($converted, $displayCurrency, $uiLanguage);
        }
    }
    return $formatMoney($minor, $sourceCurrency);
};

$supportAmounts = [500, 1000, 2000, 5000];
$authorShare = (float)($article['author_share_percent'] ?? 70);
$platformShare = (float)($article['platform_share_percent'] ?? 30);
$primaryMedia = $media[0]['path'] ?? null;
$editorialLabel = \App\Services\ArticleLabelPresenter::display(
    (string)($article['article_label'] ?? ''),
    $uiLanguage
);
$label = $editorialLabel
    ?? ($isUnique ? t('article.unique.badge', $uiLanguage) : ($isPremium ? t('article.premium.badge', $uiLanguage) : ($isPaid ? t('article.paid.badge', $uiLanguage) : t('article.type.text', $uiLanguage))));
$currencyInfo = str_replace('{currency}', $displayCurrency, t('article.support.currency_info', $uiLanguage));
if (trim($currencyInfo) === '') {
    $currencyInfo = str_replace('PLN', $displayCurrency, t('article.support.pln_info', $uiLanguage));
}
?>

<div class="article-layout">
  <article>
    <div class="breadcrumb"><?= e(t('article.breadcrumb.home', $uiLanguage)) ?> › <?= e(t('article.breadcrumb.texts', $uiLanguage)) ?> › <?= $isPaid ? e($label) : e(t('article.type.free', $uiLanguage)) ?></div>
    <div class="kicker"><?= $isPaid ? '🔒 ' . e($label) : e($label) ?></div>
    <?php require __DIR__ . '/../partials/article_language_switcher.php'; ?>
    <?php if (!empty($article_translation_fallback)): ?>
      <div class="alert alert-info article-translation-fallback"><?= e(t('article.translation.fallback_notice', $uiLanguage)) ?></div>
    <?php endif; ?>
    <h1 class="article-title"><?= e($article['title']) ?></h1>

    <?php if (!empty($article['lead'])): ?>
      <p class="lead"><?= e($article['lead']) ?></p>
    <?php endif; ?>

    <div class="article-meta">
      <?php
        $authorAvatar = $article['author_avatar_path'] ?? null;
        $authorAvatarUpdated = $article['author_avatar_updated_at'] ?? null;
        $authorName = $article['author_name'] ?? 'A';
        $authorInitials = 'A';
        if (!empty($authorName)) {
            $words = explode(' ', $authorName);
            $authorInitials = mb_strtoupper(mb_substr($words[0], 0, 1, 'UTF-8'), 'UTF-8');
            if (isset($words[1])) {
                $authorInitials .= mb_strtoupper(mb_substr($words[1], 0, 1, 'UTF-8'), 'UTF-8');
            }
        }
      ?>
      <div class="avatar" style="width: 40px; height: 40px; border-radius: 50%; overflow: hidden; background: #f0f0f0; border: 1px solid #ddd; display: inline-flex; align-items: center; justify-content: center; margin-right: 12px; flex-shrink: 0; vertical-align: middle;">
        <?php if ($authorAvatar): ?>
          <img src="<?= e($authorAvatar) ?>?t=<?= strtotime($authorAvatarUpdated ?: 'now') ?>" alt="<?= e($authorName) ?>" style="width: 100%; height: 100%; object-fit: cover;">
        <?php else: ?>
          <span style="color: #999; display: flex; align-items: center; justify-content: center;">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
          </span>
        <?php endif; ?>
      </div>
      <div>
        <strong><?= e($article['author_name']) ?></strong>
        <?php if (!empty($article['published_at'])): ?><span>·</span><span><?= e($article['published_at']) ?></span><?php endif; ?>
        <?php if ($editorialLabel !== null): ?>
          <span class="zs-public-article-label" aria-label="Etykieta artykułu: <?= e($editorialLabel) ?>"><?= e($editorialLabel) ?></span>
        <?php elseif ($isPaid): ?>
          <span>·</span><span><?= e($label) ?></span>
        <?php endif; ?>
        <?php
          if (!empty($article['published_at']) && !empty($article['updated_at'])) {
              $pubTime = strtotime($article['published_at']);
              $updTime = strtotime($article['updated_at']);
              if ($updTime > $pubTime + 60) {
                  echo '<span>·</span><span class="zs-updated-at" style="color: #718096; font-size: 0.9em;">Aktualizowano: ' . date('d.m.Y H:i', $updTime) . '</span>';
              }
          }
        ?>
      </div>
    </div>

    <?php $pos = (int)($media[0]['image_position'] ?? 50); ?>
    <img class="article-hero" src="<?= e($primaryMedia ?: '/assets/img/articles/article-state.svg') ?>" alt="" style="object-position: center <?= $pos ?>%">

    <?php if ($has_access): ?>
      <?php if (!empty($access_grant['expires_at'])): ?>
        <div class="alert alert-info"><?= e(t('article.access.active_until', $uiLanguage)) ?> <strong><?= e($access_grant['expires_at']) ?></strong>.</div>
      <?php endif; ?>
      <div class="article-body dropcap" data-article-reading-body><?= nl2br(e($article['body'])) ?></div>
    <?php else: ?>
      <div class="paywall-note">
        <div class="kicker"><?= e(t('article.paywall.editorial_access', $uiLanguage)) ?></div>
        <h2><?= e(t('article.paywall.locked', $uiLanguage)) ?></h2>
        <?php if ($pricingStatus === 'priced' && $priceMinor > 0): ?>
          <p><span class="price"><?= e($formatArticleMoney($priceMinor)) ?></span> · <?= e(t('article.paywall.access_after_purchase', $uiLanguage)) ?></p>
          <?php $splitNotice = str_replace(['{author}', '{platform}'], [number_format($authorShare, 0), number_format($platformShare, 0)], t('article.paywall.split_notice', $uiLanguage)); ?>
          <p><?= e($splitNotice) ?></p>
          <?php if (!empty($_SESSION['user_id'])): ?>
            <form method="post" action="<?= e(public_language_url($current_language, '/article/buy')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="_lang" value="<?= e($current_language) ?>">
              <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
              <button class="btn-red" type="submit"><?= e(t('article.paywall.buy_access', $uiLanguage)) ?></button>
            </form>
          <?php else: ?>
            <p><a href="/login" class="btn-red"><?= e(t('article.paywall.login_to_buy', $uiLanguage)) ?></a></p>
          <?php endif; ?>
        <?php else: ?>
          <p><?= e(t('article.paywall.waiting_pricing', $uiLanguage)) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </article>

  <aside>
    <?php if ($isPaid): ?>
      <section class="premium-card">
        <div class="kicker"><?= e(t('article.premium.kicker', $uiLanguage)) ?></div>
        <h3><?= e($label) ?></h3>
        <p class="description"><?= e(t('article.premium.description_new', $uiLanguage)) ?></p>
        <div class="price-box">
          <span class="price"><?= e($formatArticleMoney($priceMinor)) ?></span>
        </div>
        
        <div class="shares-box">
          <div class="share-item">
            <span><?= e(t('article.premium.author_share', $uiLanguage)) ?></span>
            <strong><?= number_format($authorShare, 0) ?>%</strong>
          </div>
          <div class="share-item">
            <span><?= e(t('article.premium.platform_share', $uiLanguage)) ?></span>
            <strong><?= number_format($platformShare, 0) ?>%</strong>
          </div>
        </div>

        <?php if ($has_access && !empty($access_grant['expires_at'])): ?>
          <div class="access-info">
            <?= e(t('article.access.active_until', $uiLanguage)) ?> <strong><?= e($access_grant['expires_at']) ?></strong>
          </div>
        <?php endif; ?>

        <?php if (!empty($article['editor_valuation_note'])): ?>
          <p class="admin-note"><?= e(t('article.editorial_note', $uiLanguage)) ?>: <?= e($article['editor_valuation_note']) ?></p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="card support-box">
      <div class="support-content">
        <div class="support-header">
          <h2><?= e(t('article.support.title', $uiLanguage)) ?></h2>
          <p class="description"><?= e(t('article.support.description', $uiLanguage)) ?></p>
        </div>

        <?php if (!empty($_SESSION['user_id'])): ?>
          <form method="post" action="<?= e(public_language_url($current_language, '/article/support')) ?>" class="support-form-ajax" data-pln-per-display-unit="<?= e((string)$plnPerDisplayUnit) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_lang" value="<?= e($current_language) ?>">
            <input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>">
            <input type="hidden" name="amount_minor" id="support-amount-minor" value="1000">

            <div class="form-section">
              <label class="uppercase-label"><?= e(t('article.support.amount', $uiLanguage)) ?></label>
              <div class="amount-chips">
                <?php foreach ($supportAmounts as $amountMinor): ?>
                  <button type="button" class="chip<?= $amountMinor === 1000 ? ' active' : '' ?>" data-amount="<?= $amountMinor ?>"><?= e($formatPlnMinorInDisplayCurrency($amountMinor)) ?></button>
                <?php endforeach; ?>
              </div>
              <div class="custom-amount-wrapper">
                <input type="number" id="custom-amount-input" class="input-minimal" placeholder="<?= e(t('article.support.custom_amount', $uiLanguage)) ?>" min="1" step="0.1">
                <span class="currency-suffix"><?= e($displayCurrency) ?></span>
              </div>
            </div>

            <div class="form-section">
              <label class="uppercase-label"><?= e(t('article.support.optional_message', $uiLanguage)) ?></label>
              <textarea name="note" class="input-minimal" rows="2" placeholder="<?= e(t('article.support.message', $uiLanguage)) ?>"></textarea>
            </div>

            <div class="support-notice-container" style="display: none;"></div>

            <div class="support-actions">
              <button class="btn-red btn-full" type="submit"><?= e(t('article.support.submit', $uiLanguage)) ?></button>
              <p class="pln-info"><?= e($currencyInfo) ?></p>
            </div>
          </form>
        <?php else: ?>
          <div class="login-prompt">
            <p><a href="/login" class="btn-line btn-full"><?= e(t('article.support.login', $uiLanguage)) ?></a></p>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="related">
      <h3><?= e(t('article.related.title', $uiLanguage)) ?></h3>
      <article class="related-item"><div><div class="kicker">Analizy</div><strong>Budżet obywatelski: więcej niż konsultacje</strong></div><img src="/assets/img/articles/thumb-report.svg" alt=""></article>
      <article class="related-item"><div><div class="kicker">Rozmowy</div><strong>O zaufaniu do instytucji</strong></div><img src="/assets/img/articles/thumb-society.svg" alt=""></article>
    </section>
  </aside>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const supportForm = document.querySelector('.support-form-ajax');
    if (!supportForm) return;

    const chips = supportForm.querySelectorAll('.chip');
    const amountHidden = supportForm.querySelector('#support-amount-minor');
    const customInput = supportForm.querySelector('#custom-amount-input');
    const noticeContainer = supportForm.querySelector('.support-notice-container');
    const plnPerDisplayUnit = parseFloat(supportForm.dataset.plnPerDisplayUnit || '1') || 1;

    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            amountHidden.value = chip.dataset.amount;
            customInput.value = '';
        });
    });

    customInput.addEventListener('input', () => {
        chips.forEach(c => c.classList.remove('active'));
        const val = parseFloat(customInput.value);
        if (!isNaN(val) && val > 0) {
            amountHidden.value = Math.round(val * plnPerDisplayUnit * 100);
        }
    });

    supportForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const submitBtn = supportForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        noticeContainer.style.display = 'none';

        const formData = new FormData(supportForm);
        fetch(supportForm.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            noticeContainer.textContent = data.message;
            noticeContainer.className = 'support-notice-container notice ' + (data.success ? 'success' : 'error');
            noticeContainer.style.display = 'block';
            if (data.success) {
                supportForm.reset();
                chips.forEach(c => c.classList.remove('active'));
                supportForm.querySelectorAll('.chip[data-amount="1000"]').forEach(c => c.classList.add('active'));
                amountHidden.value = "1000";
            }
        })
        .catch(err => {
            noticeContainer.textContent = '<?= e(t('article.support.error', $uiLanguage)) ?>';
            noticeContainer.className = 'support-notice-container notice error';
            noticeContainer.style.display = 'block';
        })
        .finally(() => {
            submitBtn.disabled = false;
        });
    });
});
</script>
<?php if (is_array($article_read_proof ?? null)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var body = document.querySelector('[data-article-reading-body]');
    if (!body || typeof window.fetch !== 'function') return;
    var proof = <?= json_encode($article_read_proof, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var visibleSeconds = 0;
    var maxProgress = 0;
    var submitting = false;
    var completed = false;

    function measureProgress() {
        var rect = body.getBoundingClientRect();
        var reached = Math.max(0, window.innerHeight - rect.top);
        maxProgress = Math.max(maxProgress, Math.min(100, Math.round((reached / Math.max(1, rect.height)) * 100)));
    }

    function submitProof() {
        if (completed || submitting || !csrf) return;
        if (document.visibilityState !== 'visible') return;
        if (visibleSeconds < Number(proof.min_visible_seconds || 30)) return;
        if (maxProgress < Number(proof.min_progress_percent || 60)) return;
        submitting = true;
        var payload = new URLSearchParams();
        payload.set('article_id', String(proof.article_id));
        payload.set('proof_token', String(proof.token));
        payload.set('visible_seconds', String(visibleSeconds));
        payload.set('progress_percent', String(maxProgress));
        payload.set('visible', '1');
        fetch('/api/earnings/article-read', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-TOKEN': csrf.getAttribute('content') || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: payload.toString()
        }).then(function(response) {
            if (!response.ok) throw new Error('article_read_proof_rejected');
            return response.json();
        }).then(function(result) {
            completed = result && result.accepted === true;
            if (completed && result.job_public_id && typeof window.zsTrackEarningsJob === 'function') {
                window.zsTrackEarningsJob(result.job_public_id);
            }
        }).catch(function() {
            submitting = false;
        });
    }

    window.addEventListener('scroll', function() {
        measureProgress();
        submitProof();
    }, {passive: true});
    window.setInterval(function() {
        if (document.visibilityState === 'visible') visibleSeconds++;
        measureProgress();
        submitProof();
    }, 1000);
    measureProgress();
});
</script>
<?php endif; ?>
