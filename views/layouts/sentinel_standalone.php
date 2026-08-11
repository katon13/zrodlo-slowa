<!doctype html>
<?php
$currentLanguage = (string)($current_language ?? (function_exists('public_language') ? public_language() : 'pl'));
$pageTitle = (string)($title ?? '3DORS');
$content = (string)($content ?? '');
?>
<html lang="<?= e($currentLanguage) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <title><?= e($pageTitle) ?></title>
  <link rel="icon" href="<?= e(asset_url('/assets/img/logo/logo-mark.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url('/assets/css/slowo-system.css')) ?>">
</head>
<body class="zs-sentinel-standalone lang-<?= e($currentLanguage) ?>">
  <main>
    <?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
    <?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>
    <?= $content ?>
  </main>
</body>
</html>
