<!doctype html>
<?php
$currentLanguage = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$brandName = t('brand.name', $currentLanguage);
$pageTitle = (string)($title ?? mb_strtoupper($brandName, 'UTF-8'));
$content = (string)($content ?? '');
?>
<html lang="<?= e($currentLanguage) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?></title>
  <link rel="icon" href="<?= e(asset_url('/assets/img/logo/logo-mark.svg')) ?>" type="image/svg+xml">
  <?php if (!empty($seo_meta['description'])): ?>
    <meta name="description" content="<?= e((string)$seo_meta['description']) ?>">
  <?php endif; ?>
  <?php if (!empty($seo_meta['keywords'])): ?>
    <meta name="keywords" content="<?= e((string)$seo_meta['keywords']) ?>">
  <?php endif; ?>
  <?php if (!empty($seo_meta['robots'])): ?>
    <meta name="robots" content="<?= e((string)$seo_meta['robots']) ?>">
  <?php endif; ?>
  <?php if (!empty($seo_meta['canonical'])): ?>
    <link rel="canonical" href="<?= e((string)$seo_meta['canonical']) ?>">
  <?php endif; ?>
  <?php foreach (($seo_meta['alternates'] ?? []) as $hrefLang => $href): ?>
    <link rel="alternate" hreflang="<?= e((string)$hrefLang) ?>" href="<?= e((string)$href) ?>">
  <?php endforeach; ?>
  <?php if (!empty($seo_meta['json_ld']) && is_array($seo_meta['json_ld'])): ?>
    <script type="application/ld+json"><?= json_encode($seo_meta['json_ld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/slowo-system.css')) ?>">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
</head>
<body class="lang-<?= e($currentLanguage) ?>" data-detected-lang="<?= e(public_language()) ?>">
<header class="site-header">
  <div class="header-inner">
    <a class="logo" href="<?= e(public_language_url($currentLanguage, '/')) ?>" aria-label="<?= e(mb_strtoupper($brandName, 'UTF-8')) ?>">
      <img src="<?= e(asset_url('/assets/img/logo/logo-mark.svg')) ?>" alt="">
      <span class="logo-text"><?= e(mb_strtoupper($brandName, 'UTF-8')) ?></span>
    </a>
    <nav class="main-nav">
      <a href="<?= e(public_language_url($currentLanguage, '/articles?cat=najnowsze')) ?>"><?= e(t('layout.menu.latest', $currentLanguage)) ?></a>
      <button class="main-topics-toggle" data-topics-toggle aria-expanded="false" aria-controls="topics-panel"><?= e(mb_strtoupper(t('layout.menu.topics', $currentLanguage), 'UTF-8')) ?></button>
      <a href="<?= e(public_language_url($currentLanguage, '/surveys')) ?>"><?= e(t('layout.menu.polls', $currentLanguage)) ?></a>
      <a href="<?= e(public_language_url($currentLanguage, '/campaigns')) ?>"><?= e(t('layout.menu.ads', $currentLanguage)) ?></a>
      <a href="<?= e(public_language_url($currentLanguage, '/jak-zarabiac')) ?>"><?= e(t('layout.menu.how_to_earn', $currentLanguage)) ?></a>
    </nav>
    <div class="header-actions">
      <?php if (!empty($tt_rate_label)): ?>
        <a href="<?= e(public_language_url($currentLanguage, '/wallet')) ?>" class="header-rate-indicator" title="<?= e(t('layout.header.tt_rate_label', $currentLanguage)) ?>">
          <span class="rate-label"><?= e(t('layout.header.tt_rate_label', $currentLanguage)) ?></span>
          <span class="rate-value"><?= e($tt_rate_label) ?></span>
        </a>
      <?php endif; ?>
      <?php require __DIR__ . '/../partials/language_switcher.php'; ?>
      <a href="<?= e(public_language_url($currentLanguage, '/articles')) ?>" class="nav-icon" title="<?= e(t('layout.search', $currentLanguage)) ?>" aria-label="<?= e(t('layout.search', $currentLanguage)) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
      </a>
      <a href="<?= e(public_language_url($currentLanguage, '/authors')) ?>" class="nav-icon" title="<?= e(t('layout.menu.authors', $currentLanguage)) ?>" aria-label="<?= e(t('layout.menu.authors', $currentLanguage)) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19l7-7 3 3-7 7-3-3z"></path><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path><path d="M2 2l7.586 7.586"></path><circle cx="11" cy="11" r="2"></circle></svg>
      </a>
      <?php if (!empty($_SESSION['user_id'])): ?>
        <a href="<?= e(public_language_url($currentLanguage, '/wallet')) ?>" class="nav-icon" title="<?= e(t('wallet.title', $currentLanguage)) ?>" aria-label="<?= e(t('wallet.title', $currentLanguage)) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"></path><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"></path><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"></path></svg>
        </a>
        <?php $writingPanelPath = ($_SESSION['role'] ?? '') === 'commentator' ? '/opinie' : '/author'; ?>
        <?php $writingPanelLabel = ($_SESSION['role'] ?? '') === 'commentator' ? t('layout.menu.opinions', $currentLanguage) : t('layout.header.author_panel', $currentLanguage); ?>
        <a href="<?= e(public_language_url($currentLanguage, $writingPanelPath)) ?>" class="nav-icon" title="<?= e($writingPanelLabel) ?>" aria-label="<?= e($writingPanelLabel) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
        </a>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
          <a href="<?= e(public_language_url($currentLanguage, '/admin')) ?>" class="nav-icon" title="<?= e(t('layout.menu.admin', $currentLanguage)) ?>" aria-label="<?= e(t('layout.menu.admin', $currentLanguage)) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
          </a>
        <?php endif; ?>
        <form action="<?= e(public_language_url($currentLanguage, '/logout')) ?>" method="post" class="inline"><?= csrf_field() ?><button class="btn-logout" type="submit"><?= e(t('layout.auth.logout', $currentLanguage)) ?></button></form>
        
        <?php $unreadNotificationCount = max(0, (int)($unread_notifications_count ?? 0)); ?>
        <a href="<?= e(public_language_url($currentLanguage, '/account/settings')) ?>" class="header-avatar-link" title="<?= e(t('account.settings.title', $currentLanguage)) ?>">
          <?php if (!empty($current_user_avatar)): ?>
            <img src="<?= e($current_user_avatar) ?>?t=<?= strtotime($current_user_avatar_updated_at ?? 'now') ?>" alt="" class="header-avatar-img">
          <?php else: ?>
            <div class="header-avatar-fallback">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            </div>
          <?php endif; ?>
          <span class="zs-notification-badge" data-notification-badge aria-label="<?= e(t('notifications.unread_label', $currentLanguage)) ?>" <?= $unreadNotificationCount === 0 ? 'hidden' : '' ?>><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span>
        </a>
      <?php else: ?>
        <a href="<?= e(public_language_url($currentLanguage, '/login')) ?>"><?= e(t('layout.auth.login', $currentLanguage)) ?></a>
        <a class="btn-red" href="<?= e(public_language_url($currentLanguage, '/register')) ?>"><?= e(t('layout.auth.join', $currentLanguage)) ?></a>
      <?php endif; ?>
    </div>
  </div>
  <div id="topics-panel" class="main-topics-panel" data-topics-panel aria-hidden="true">
    <div class="header-inner">
      <nav class="main-topics-list">
        <?php foreach ($menu_categories ?? [] as $cat): ?>
          <a href="<?= e(public_language_url($currentLanguage, '/articles?cat=' . (string)$cat['slug'])) ?>" class="main-topics-link"><?= e($cat['name']) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </div>
</header>
<main class="page">
  <?php if (empty($hide_global_flashes)): ?>
    <?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
    <?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>
  <?php endif; ?>
  <?= $content ?>
</main>
<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-logo">
      <a class="logo" href="<?= e(public_language_url($currentLanguage, '/')) ?>" style="justify-content: center;">
        <img src="<?= e(asset_url('/assets/img/logo/logo-mark.svg')) ?>" alt="">
        <span class="logo-text"><?= e(mb_strtoupper($brandName, 'UTF-8')) ?></span>
      </a>
    </div>
    <p><?= e(t('layout.footer.description', $currentLanguage)) ?></p>
    <p><?= e(t('layout.footer.links', $currentLanguage)) ?></p>
    <p><a class="footer-bug-link" href="<?= e(public_language_url($currentLanguage, '/report-bug?from=' . rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/')))) ?>"><?= e(t('bug_report.footer', $currentLanguage)) ?></a></p>
  </div>
</footer>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var toggle = document.querySelector('[data-topics-toggle]');
  var panel = document.querySelector('[data-topics-panel]');
  
  if (toggle && panel) {
    toggle.addEventListener('click', function() {
      var isOpen = panel.classList.contains('is-open');
      if (isOpen) {
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        toggle.setAttribute('aria-expanded', 'false');
      } else {
        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        toggle.setAttribute('aria-expanded', 'true');
      }
    });
  }

  <?php if (($earnings_presence['enabled'] ?? false) === true): ?>
  (function() {
    var csrf = document.querySelector('meta[name="csrf-token"]');
    if (!csrf || typeof window.fetch !== 'function') return;
    var intervalMs = <?= max(30, (int)($earnings_presence['ping_seconds'] ?? 60)) * 1000 ?>;
    var lastKey = 'zs-earnings-presence-last';
    var clearedKey = 'zs-earnings-presence-cleared';
    var cursorKey = 'zs-earnings-notification-cursor:<?= (int)($earnings_presence['user_id'] ?? 0) ?>';
    var timer = null;

    function notificationStack() {
      var stack = document.querySelector('[data-earnings-live-stack]');
      if (stack) return stack;
      stack = document.createElement('div');
      stack.className = 'earnings-live-stack';
      stack.setAttribute('aria-live', 'polite');
      stack.setAttribute('data-earnings-live-stack', '');
      var main = document.querySelector('main.page');
      if (main) main.prepend(stack);
      return stack;
    }

    function updateNotificationBadge(count) {
      var badge = document.querySelector('[data-notification-badge]');
      if (!badge) return;
      count = Math.max(0, parseInt(count || '0', 10));
      badge.hidden = count === 0;
      badge.textContent = count > 99 ? '99+' : String(count);
    }

    function acknowledge(ids) {
      if (!ids.length) return Promise.resolve({ok: true});
      var body = new URLSearchParams();
      ids.forEach(function(id) { body.append('ids[]', String(id)); });
      return fetch('/api/earnings/notifications/ack', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-TOKEN': csrf.getAttribute('content') || '',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      }).then(function(response) {
        if (!response.ok) throw new Error('notification_ack_failed');
        return response.json();
      }).then(function(payload) {
        updateNotificationBadge(payload.unread_count || 0);
        return payload;
      });
    }

    function fetchNotifications() {
      var cursor = Math.max(0, parseInt(sessionStorage.getItem(cursorKey) || '0', 10));
      return fetch('/api/earnings/notifications?after_id=' + encodeURIComponent(String(cursor)) + '&limit=5', {
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      }).then(function(response) {
        if (!response.ok) throw new Error('notification_fetch_failed');
        return response.json();
      }).then(function(payload) {
        var items = Array.isArray(payload.items) ? payload.items : [];
        updateNotificationBadge(payload.unread_count || 0);
        var stack = notificationStack();
        items.forEach(function(item) {
          if (!stack || stack.querySelector('[data-earning-notification-id="' + item.id + '"]')) return;
          var toast = document.createElement('div');
          toast.className = 'earning-toast';
          toast.setAttribute('data-earning-notification-id', String(item.id));
          var message = document.createElement('strong');
          message.textContent = String(item.message || item.title || '');
          var recorded = document.createElement('small');
          recorded.textContent = <?= json_encode($brandName . ' ' . t('wallet.activity_recorded', $currentLanguage), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
          var markRead = document.createElement('button');
          markRead.type = 'button';
          markRead.className = 'earning-toast-read';
          markRead.textContent = <?= json_encode(t('notifications.mark_read', $currentLanguage), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
          markRead.addEventListener('click', function() {
            markRead.disabled = true;
            acknowledge([parseInt(item.id, 10)]).then(function() {
              toast.remove();
            }).catch(function() { markRead.disabled = false; });
          });
          toast.appendChild(message);
          toast.appendChild(recorded);
          toast.appendChild(markRead);
          stack.appendChild(toast);
        });
        sessionStorage.setItem(cursorKey, String(Math.max(cursor, parseInt(payload.next_cursor || cursor, 10))));
        return payload;
      });
    }

    function pollJob(publicId, attempt) {
      if (!publicId || attempt > 60) return;
      fetch('/api/earnings/jobs/status?public_id=' + encodeURIComponent(publicId), {
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      }).then(function(response) {
        if (!response.ok) throw new Error('job_status_failed');
        return response.json();
      }).then(function(payload) {
        if (['completed', 'rejected', 'dead_letter'].indexOf(payload.status) !== -1) {
          if (payload.status === 'completed' && payload.result && payload.result.awarded === true) {
            fetchNotifications().catch(function() {});
          }
          return;
        }
        window.setTimeout(function() { pollJob(publicId, attempt + 1); }, attempt < 10 ? 750 : 2000);
      }).catch(function() {
        window.setTimeout(function() { pollJob(publicId, attempt + 1); }, 2000);
      });
    }

    window.zsTrackEarningsJob = function(publicId) { pollJob(String(publicId || ''), 0); };

    function sendPresence(visible, force) {
      var now = Date.now();
      var last = parseInt(sessionStorage.getItem(lastKey) || '0', 10);
      var wasCleared = sessionStorage.getItem(clearedKey) === '1';
      if (visible && !force && !wasCleared && now - last < intervalMs) {
        schedule(intervalMs - (now - last));
        return;
      }
      var body = new URLSearchParams();
      body.set('visible', visible ? '1' : '0');
      fetch('/api/earnings/presence', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-TOKEN': csrf.getAttribute('content') || '',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      }).then(function(response) {
        return response.ok ? response.json() : null;
      }).then(function(payload) {
        if (payload && payload.job_public_id) pollJob(String(payload.job_public_id), 0);
        var hint = payload ? parseInt(payload.notification_hint_id || '0', 10) : 0;
        var cursor = Math.max(0, parseInt(sessionStorage.getItem(cursorKey) || '0', 10));
        if (hint > cursor) fetchNotifications().catch(function() {});
      }).catch(function() {});
      if (visible) {
        sessionStorage.setItem(lastKey, String(now));
        sessionStorage.removeItem(clearedKey);
        schedule(intervalMs);
      } else {
        sessionStorage.setItem(clearedKey, '1');
        if (timer) window.clearTimeout(timer);
      }
    }

    function schedule(delay) {
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(function() {
        if (document.visibilityState === 'visible') sendPresence(true, false);
      }, Math.max(1000, delay));
    }

    document.addEventListener('visibilitychange', function() {
      sendPresence(document.visibilityState === 'visible', true);
    });
    if (document.visibilityState === 'visible') sendPresence(true, false);
  })();
  <?php endif; ?>


  var languageSwitcher = document.querySelector('[data-language-switcher]');
  if (languageSwitcher) {
    document.addEventListener('click', function(event) {
      if (!languageSwitcher.contains(event.target)) {
        languageSwitcher.removeAttribute('open');
      }
    });
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        languageSwitcher.removeAttribute('open');
      }
    });
  }
});
</script>
</body>
</html>
