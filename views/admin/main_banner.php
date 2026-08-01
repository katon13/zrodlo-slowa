<?php
/** @var array<string,mixed> $banner */
/** @var array<int,string> $languages */
/** @var array<string,string> $language_labels */
$banner = is_array($banner ?? null) ? $banner : [];
$languages = is_array($languages ?? null) ? array_values(array_filter(array_map('strval', $languages))) : ['pl', 'en', 'de', 'fr', 'it', 'es'];
if (!in_array('pl', $languages, true)) {
    array_unshift($languages, 'pl');
}
$languageLabels = is_array($language_labels ?? null) ? $language_labels : [];
$translations = is_array($banner['translations'] ?? null) ? $banner['translations'] : [];
$buttonUrl = (string)($banner['button_url'] ?? '/register');
$imagePath = (string)($banner['image_path'] ?? '/assets/img/banners/main-banner-editorial-soft-bg.webp');
$isActive = !empty($banner['is_active']);
$activeLang = isset($_GET['lang']) && in_array((string)$_GET['lang'], $languages, true) ? (string)$_GET['lang'] : 'pl';
$pl = is_array($translations['pl'] ?? null) ? $translations['pl'] : [];
?>

<section class="admin-page-head zs-banner-admin-head">
  <p class="kicker">Strona główna</p>
  <h1>BANER GŁÓWNY</h1>
  <p>Stały górny baner strony głównej. To nie jest artykuł. Wersja PL jest źródłem, a pozostałe wersje można wygenerować przez AI i później edytować ręcznie.</p>
</section>

<section class="admin-panel-block zs-banner-editor-shell">
  <div class="zs-banner-editor-top">
    <div>
      <p class="kicker">Źródło i tłumaczenia</p>
      <h2>Redakcja Baneru Głównego</h2>
      <p class="muted">Najpierw zapisz wersję PL, potem użyj przycisku tłumaczenia AI. Link i obraz są wspólne dla wszystkich języków.</p>
    </div>
    <div class="zs-banner-status <?= $isActive ? 'is-active' : 'is-inactive' ?>">
      <?= $isActive ? 'Baner aktywny' : 'Baner nieaktywny' ?>
    </div>
  </div>

  <form method="post" action="/admin/main-banner" class="zs-banner-editor-form">
    <?= csrf_field() ?>

    <div class="zs-banner-common-grid">
      <label>
        <span>Link przycisku</span>
        <input name="button_url" value="<?= e($buttonUrl) ?>" placeholder="/register">
      </label>
      <label>
        <span>Obraz / tło</span>
        <input name="image_path" value="<?= e($imagePath) ?>" placeholder="/assets/img/banners/main-banner-editorial-soft-bg.webp">
      </label>
      <label class="zs-banner-checkbox">
        <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <span>Pokazuj baner na stronie głównej</span>
      </label>
    </div>

    <?php require __DIR__ . '/../partials/translation_status_legend.php'; ?>

    <div id="translations" class="zs-banner-language-tabs" role="tablist" aria-label="Wersje językowe baneru">
      <?php foreach ($languages as $lang): ?>
        <?php
          $label = (string)($languageLabels[$lang] ?? strtoupper($lang));
          $languageRow = is_array($translations[$lang] ?? null) ? $translations[$lang] : [];
          $languageState = $lang === 'pl'
              ? 'is-source'
              : (trim((string)($languageRow['updated_at'] ?? '')) !== '' ? 'is-translated' : 'is-missing');
          $languageStateLabel = $lang === 'pl'
              ? 'oryginał'
              : ($languageState === 'is-translated' ? 'tłumaczenie' : 'brak tłumaczenia');
        ?>
        <button type="button" class="zs-banner-lang-tab <?= e($languageState) ?> <?= $lang === $activeLang ? 'is-active' : '' ?>" data-banner-lang-tab="<?= e($lang) ?>" title="<?= e(strtoupper($lang) . ': ' . $languageStateLabel) ?>">
          <span><?= e(strtoupper($lang)) ?></span>
          <small><?= e($label) ?> · <?= e($languageStateLabel) ?></small>
        </button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($languages as $lang): ?>
      <?php
        $row = is_array($translations[$lang] ?? null) ? $translations[$lang] : [];
        $label = (string)($languageLabels[$lang] ?? strtoupper($lang));
      ?>
      <section class="zs-banner-language-panel <?= $lang === $activeLang ? 'is-active' : '' ?>" data-banner-lang-panel="<?= e($lang) ?>">
        <div class="zs-banner-language-head">
          <div>
            <p class="kicker"><?= $lang === 'pl' ? 'Wersja źródłowa' : 'Wersja językowa' ?></p>
            <h2><?= e($label) ?></h2>
          </div>
          <?php if ($lang !== 'pl'): ?>
            <span class="zs-banner-ai-note">Tę wersję może uzupełnić AI. Po wygenerowaniu możesz ją normalnie poprawić.</span>
          <?php endif; ?>
        </div>

        <label class="zs-banner-field">
          <span>Etykieta / kicker</span>
          <input name="translations[<?= e($lang) ?>][kicker]" value="<?= e((string)($row['kicker'] ?? '')) ?>">
        </label>

        <label class="zs-banner-field zs-banner-field-title">
          <span>Tytuł</span>
          <input name="translations[<?= e($lang) ?>][title]" value="<?= e((string)($row['title'] ?? '')) ?>">
        </label>

        <label class="zs-banner-field">
          <span>Lead</span>
          <textarea name="translations[<?= e($lang) ?>][lead_text]" rows="4"><?= e((string)($row['lead_text'] ?? '')) ?></textarea>
        </label>

        <label class="zs-banner-field">
          <span>Opis dodatkowy</span>
          <textarea name="translations[<?= e($lang) ?>][body_text]" rows="5"><?= e((string)($row['body_text'] ?? '')) ?></textarea>
        </label>

        <label class="zs-banner-field">
          <span>Tekst przycisku</span>
          <input name="translations[<?= e($lang) ?>][button_label]" value="<?= e((string)($row['button_label'] ?? '')) ?>">
        </label>
      </section>
    <?php endforeach; ?>

    <div class="zs-banner-actions">
      <button class="btn-red" type="submit">Zapisz Baner Główny</button>
      <a class="btn-outline" href="/" target="_blank" rel="noopener">Podgląd strony głównej</a>
    </div>
  </form>

  <form method="post" action="/admin/main-banner/translate-ai" class="zs-banner-ai-box">
    <?= csrf_field() ?>
    <div>
      <p class="kicker">AI</p>
      <h2>Tłumacz Baner Główny</h2>
      <p>AI pobierze zapisaną wersję PL i utworzy wersje językowe. Potem każdą wersję możesz poprawić ręcznie w panelu powyżej.</p>
      <label class="zs-banner-field">
        <span>Dodatkowa instrukcja dla AI, opcjonalnie</span>
        <textarea name="translation_instructions" rows="3" placeholder="Np. zachowaj spokojny, redakcyjny ton ŹRÓDŁA SŁOWA."></textarea>
      </label>
    </div>
    <button class="btn-red" type="submit">Tłumacz baner główny</button>
  </form>
</section>

<?php if (!$isActive): ?>
  <section class="notice warning" style="margin-top: 24px;">Baner jest teraz nieaktywny i nie będzie widoczny na stronie głównej.</section>
<?php endif; ?>

<section class="hero main-banner zs-banner-preview" style="margin-top: 24px;">
  <div>
    <div class="kicker"><?= e((string)($pl['kicker'] ?? '')) ?></div>
    <h1><?= e((string)($pl['title'] ?? '')) ?></h1>
    <p class="lead"><?= e((string)($pl['lead_text'] ?? '')) ?></p>
    <p class="lead lead-small"><?= e((string)($pl['body_text'] ?? '')) ?></p>
    <a class="read-more" href="<?= e($buttonUrl) ?>"><?= e((string)($pl['button_label'] ?? '')) ?> <span>→</span></a>
  </div>
  <?php if ($imagePath !== ''): ?>
    <img class="hero-image" src="<?= e($imagePath) ?>" alt="">
  <?php endif; ?>
</section>

<script>
document.querySelectorAll('[data-banner-lang-tab]').forEach((button) => {
  button.addEventListener('click', () => {
    const lang = button.getAttribute('data-banner-lang-tab');
    document.querySelectorAll('[data-banner-lang-tab]').forEach((tab) => tab.classList.toggle('is-active', tab === button));
    document.querySelectorAll('[data-banner-lang-panel]').forEach((panel) => {
      panel.classList.toggle('is-active', panel.getAttribute('data-banner-lang-panel') === lang);
    });
  });
});
</script>

<style>
.zs-banner-admin-head { max-width: 920px; }
.zs-banner-editor-shell { padding: 34px; }
.zs-banner-editor-top { display:flex; justify-content:space-between; gap:24px; align-items:flex-start; border-bottom:1px solid rgba(0,0,0,.08); padding-bottom:24px; margin-bottom:24px; }
.zs-banner-status { border:1px solid rgba(0,0,0,.12); padding:10px 14px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; font-size:.76rem; white-space:nowrap; }
.zs-banner-status.is-active { color:#9b0016; border-color:#9b0016; }
.zs-banner-status.is-inactive { color:#777; }
.zs-banner-common-grid { display:grid; grid-template-columns:minmax(220px,1fr) minmax(220px,1fr) auto; gap:18px; align-items:end; margin-bottom:28px; }
.zs-banner-common-grid label span, .zs-banner-field span { display:block; font-size:.78rem; text-transform:uppercase; letter-spacing:.14em; color:#666; font-weight:800; margin-bottom:8px; }
.zs-banner-common-grid input, .zs-banner-field input, .zs-banner-field textarea { width:100%; border:1px solid rgba(0,0,0,.16); background:#fff; padding:12px 13px; font:inherit; color:#222; }
.zs-banner-field textarea { resize:vertical; line-height:1.55; }
.zs-banner-checkbox { display:flex; gap:10px; align-items:center; padding:12px 0; font-weight:800; }
.zs-banner-checkbox span { margin:0; letter-spacing:0; text-transform:none; color:#222; font-size:1rem; }
.zs-banner-language-tabs { display:flex; gap:8px; flex-wrap:wrap; border-bottom:1px solid rgba(0,0,0,.08); margin: 8px 0 0; }
.zs-banner-lang-tab { border:1px solid rgba(0,0,0,.12); border-bottom:none; background:#fff; padding:11px 16px; cursor:pointer; display:flex; align-items:baseline; gap:8px; transform:translateY(1px); }
.zs-banner-lang-tab span { color:#9b0016; font-weight:900; letter-spacing:.12em; }
.zs-banner-lang-tab small { color:#666; font-weight:700; }
.zs-banner-lang-tab.is-source { border-color:#9bb7d1; background:#edf5fc; }
.zs-banner-lang-tab.is-source span { color:#164e78; }
.zs-banner-lang-tab.is-translated { border-color:#a9d8b4; background:#effaf2; }
.zs-banner-lang-tab.is-translated span { color:#176b34; }
.zs-banner-lang-tab.is-missing { border-color:#dedede; background:#f6f6f6; }
.zs-banner-lang-tab.is-missing span,
.zs-banner-lang-tab.is-missing small { color:#777; }
.zs-banner-lang-tab.is-active { border-color:#9b0016; box-shadow: inset 0 3px 0 #9b0016; }
.zs-banner-language-panel { display:none; padding:30px 0 8px; }
.zs-banner-language-panel.is-active { display:block; }
.zs-banner-language-head { display:flex; justify-content:space-between; gap:18px; align-items:flex-start; margin-bottom:20px; }
.zs-banner-language-head h2 { margin:0; }
.zs-banner-ai-note { max-width:360px; color:#666; font-size:.94rem; line-height:1.45; }
.zs-banner-field { display:block; margin-bottom:18px; }
.zs-banner-field-title input { font-family: Georgia, 'Times New Roman', serif; font-size:2.1rem; line-height:1.1; letter-spacing:.01em; padding:16px; }
.zs-banner-actions { display:flex; gap:12px; flex-wrap:wrap; align-items:center; border-top:1px solid rgba(0,0,0,.08); padding-top:24px; margin-top:14px; }
.zs-banner-ai-box { margin-top:28px; border:1px solid rgba(155,0,22,.24); background:rgba(155,0,22,.035); padding:24px; display:grid; grid-template-columns:1fr auto; gap:22px; align-items:end; }
.zs-banner-ai-box h2 { margin-top:0; }
.zs-banner-preview { border:1px solid rgba(0,0,0,.08); padding:24px; }
@media (max-width: 900px) {
  .zs-banner-editor-top, .zs-banner-language-head, .zs-banner-ai-box { display:block; }
  .zs-banner-common-grid { grid-template-columns:1fr; }
  .zs-banner-ai-box button { margin-top:12px; }
  .zs-banner-field-title input { font-size:1.6rem; }
}
</style>
