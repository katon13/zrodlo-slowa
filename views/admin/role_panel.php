<?php
$isChiefEditor = ($panel_code ?? '') === 'chief_editor';
$isEditor = ($panel_code ?? '') === 'editor';
$isProofreader = ($panel_code ?? '') === 'proofreader';
$isModerator = ($panel_code ?? '') === 'moderator';
$isEditorialPanel = $isChiefEditor || $isEditor;

$displayDescription = $panel['description'] ?? 'Precyzyjny zakres danych dla przypisanej roli.';
$publicLanguages = $languages['public_enabled'] ?? ['pl', 'en', 'de', 'fr', 'it', 'es'];
$shortLanguageLabels = $languages['short_labels'] ?? [];
$articleTranslationsMap = $article_translations_map ?? [];
$articleLabelOptions = [
    'Hot News' => ['label' => 'Pilne', 'color' => '#ef4444'],
    'Important' => ['label' => 'Ważne', 'color' => '#f59e0b'],
    'Discussion' => ['label' => 'Dyskusja', 'color' => '#3b82f6'],
    'Opinion' => ['label' => 'Opinia', 'color' => '#8b5cf6'],
    'Analysis' => ['label' => 'Analiza', 'color' => '#10b981'],
    'Exclusive' => ['label' => 'Ekskluzywne', 'color' => '#ec4899'],
    'Interview' => ['label' => 'Wywiad', 'color' => '#6366f1'],
    'Reportage' => ['label' => 'Reportaż', 'color' => '#14b8a6'],
    'Sponsored' => ['label' => 'Sponsorowane', 'color' => '#6b7280'],
    'Breaking' => ['label' => 'Ostatnia chwila', 'color' => '#dc2626'],
    'Editor\'s Pick' => ['label' => 'Wybór redakcji', 'color' => '#f97316'],
];
?>

<section class="admin-page-head zs-operator-page-head">
  <p class="kicker"><?php echo $isEditorialPanel ? 'PANEL PRACY' : 'SNAJPER SŁOWA — kafelek roli'; ?></p>
  <h1><?php echo e($panel['title'] ?? 'Panel roli'); ?></h1>
  <p><?php echo e($displayDescription); ?></p>
</section>

<section class="admin-section zs-operator-panel zs-role-operator-panel">
  <div class="zs-role-info-grid">
    <div class="zs-info-item">
        <label>Cel danych</label>
        <span><?php echo ($panel['target'] ?? '') === 'articles' ? 'Teksty redakcyjne' : 'Wypłaty finansowe'; ?></span>
    </div>
    <div class="zs-info-item">
        <label>Limit widoku</label>
        <span><?php echo (int)($snajper_limit ?? 50); ?> pozycji</span>
    </div>
    <div class="zs-info-item">
        <label>Tryb</label>
        <span>Snajper Słowa aktywny</span>
    </div>
    <?php if ($isEditorialPanel): ?>
    <div class="zs-info-item">
        <label>Cel techniczny</label>
        <span class="zs-id-technical"><?php echo e($panel['target'] ?? ''); ?></span>
    </div>
    <?php endif; ?>
  </div>

  <?php 
    $articleStatusMap = [
        'draft' => 'Roboczy',
        'submitted' => 'Tekst przyszedł od autora',
        'review' => 'Redaktor pracuje nad tekstem',
        'approved' => 'Zaakceptowany przez redakcję',
        'published' => 'Opublikowany',
        'rejected' => 'Odrzucony',
        'archived' => 'Niepubliczny',
    ];
    $payoutStatusMap = [
        'pending' => 'Oczekuje',
        'paid' => 'Wypłacono',
        'failed' => 'Błąd',
        'cancelled' => 'Anulowano',
    ];
    $accessLabels = [
        'free' => 'bezpłatny',
        'paid' => 'płatny',
    ];
    $pricingLabels = [
        'not_priced' => 'bez wyceny',
        'priced' => 'wyceniony',
        'free' => 'darmowy',
        'blocked' => 'wstrzymany',
    ];
  ?>

  <?php if (empty($rows)): ?>
    <div class="zs-empty-state">
        <?php if ($isChiefEditor): ?>
            <p class="zs-empty-title">Brak tekstów oczekujących na decyzję redakcji.</p>
            <p class="zs-empty-desc">Kiedy autorzy dodadzą teksty do oceny, pojawią się tutaj.</p>
        <?php elseif ($isEditor): ?>
            <p class="zs-empty-title">Obecnie nie ma żadnych tekstów do edycji.</p>
            <p class="zs-empty-desc">Gdy pojawią się treści wymagające pracy redakcyjnej, zobaczysz je tutaj.</p>
        <?php elseif ($isModerator): ?>
            <p class="zs-empty-title">Brak tekstów w moderacji.</p>
            <p class="zs-empty-desc">Lista artykułów pojawi się tutaj, gdy w systemie będą teksty.</p>
        <?php else: ?>
            <p class="zs-empty-title">Brak danych do wyświetlenia.</p>
            <p class="zs-empty-desc">Obecnie nie ma żadnych pozycji w tej sekcji.</p>
        <?php endif; ?>
    </div>
  <?php else: ?>
    <?php if (($panel['target'] ?? '') === 'payouts'): ?>
        <table class="zs-admin-table">
          <thead>
            <tr><th>ID</th><th>Użytkownik</th><th>Kwota</th><th>Status</th><th>Data</th></tr>
          </thead>
          <tbody>
            <?php foreach (($rows ?? []) as $row): ?>
              <tr id="article-<?php echo (int)($row['id'] ?? 0); ?>">
                <td><small class="zs-id-technical">#<?php echo (int)$row['id']; ?></small></td>
                <td><strong><?php echo e(($row['display_name'] ?? '') ?: ($row['email'] ?? '')); ?></strong></td>
                <td class="zs-amount-cell"><?php echo number_format(((int)($row['amount_minor'] ?? 0))/100, 2, ',', ' '); ?> <?php echo e($row['currency'] ?? 'PLN'); ?></td>
                <td>
                  <span class="zs-status-badge <?php echo e($row['status'] ?? ''); ?>">
                    <?php echo e($payoutStatusMap[$row['status'] ?? ''] ?? ($row['status'] ?? '')); ?>
                  </span>
                </td>
                <td><small class="zs-date-human"><?php echo date('d.m.Y H:i', strtotime($row['requested_at'] ?? 'now')); ?></small></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
    <?php else: ?>
        <table class="zs-admin-table">
          <thead>
            <?php if ($isModerator): ?>
              <tr>
                <th style="width: 15%;">ARTYKUŁ</th>
                <th style="width: 15%;">MODERACJA</th>
                <th style="width: 70%;">WYCENA / ETYKIETA</th>
              </tr>
            <?php elseif ($isProofreader): ?>
              <tr><th>ID</th><th>Tytuł i autor</th><th>Status tekstu</th><th>Status korekty</th><th>Aktualizacja</th><th>Akcja</th></tr>
            <?php else: ?>
              <tr><th>ID</th><th>Tytuł i Autor</th><th>Status</th><th><?php echo $isChiefEditor ? 'Blokada autora' : 'Premium'; ?></th><th>Aktualizacja</th></tr>
            <?php endif; ?>
          </thead>
          <tbody>
            <?php foreach (($rows ?? []) as $row): ?>
              <tr id="article-<?php echo (int)($row['id'] ?? 0); ?>">
                <?php if (!$isModerator): ?>
                  <td><small class="zs-id-technical">#<?php echo (int)$row['id']; ?></small></td>
                <?php endif; ?>
                <td id="author-<?php echo (int)($row['author_id'] ?? 0); ?>-article-<?php echo (int)($row['id'] ?? 0); ?>">
                    <div class="zs-title-cell">
                        <?php if ($isModerator): ?>
                            <small class="zs-id-technical">#<?php echo (int)$row['id']; ?></small>
                        <?php endif; ?>
                        <strong><?php echo e($row['title'] ?? ''); ?></strong>
                        <div class="zs-subtitle"><?php echo e($row['author_name'] ?? ''); ?> | <?php echo !empty($row['created_at']) ? date('d.m.Y', strtotime($row['created_at'])) : '--.--.----'; ?></div>
                        <div class="zs-actions u-mt-2">
                           <a href="/article?id=<?php echo (int)$row['id']; ?>" class="zs-btn-mini btn-outline" target="_blank">Podgląd</a>
                        </div>
                    </div>
                </td>
                <?php if ($isProofreader): ?>
                  <td>
                    <span class="zs-status-badge <?php echo e((string)($row['status'] ?? 'draft')); ?>"><?php echo e($articleStatusMap[$row['status'] ?? 'draft'] ?? (string)($row['status'] ?? 'draft')); ?></span>
                  </td>
                  <td>
                    <?php if (!empty($row['proofread_at'])): ?>
                      <span class="zs-status-badge review">KOREKTA</span>
                      <small class="zs-date-human"><?php echo date('d.m.Y H:i', strtotime($row['proofread_at'])); ?></small>
                    <?php else: ?>
                      <span class="zs-status-badge submitted">DO KOREKTY</span>
                    <?php endif; ?>
                  </td>
                  <td><small class="zs-date-human"><?php echo !empty($row['updated_at']) ? date('d.m.Y H:i', strtotime($row['updated_at'])) : '--.--.---- --:--'; ?></small></td>
                  <td><a class="zs-btn-small" href="/admin/proofreader/edit?id=<?php echo (int)$row['id']; ?>">KORYGUJ</a></td>
                <?php elseif ($isModerator): ?>
                  <td>
                    <?php $articleId = (int)($row['id'] ?? 0); ?>
                    <?php
                      $currentStatus = (string)($row['status'] ?? 'approved');
                      $moderatorStatusOptions = match ($currentStatus) {
                          'approved' => [
                              'approved' => 'Zaakceptowany przez redakcję',
                              'published' => 'Opublikowany',
                              'archived' => 'Archiwum',
                          ],
                          'published' => [
                              'published' => 'Opublikowany',
                              'archived' => 'Archiwum',
                          ],
                          'archived' => [
                              'archived' => 'Archiwum',
                              'draft' => 'Przywróć jako szkic',
                          ],
                          default => [$currentStatus => $currentStatus],
                      };
                    ?>
                    <form id="status-form-<?php echo $articleId; ?>" method="post" action="/admin/articles/status" class="zs-inline-form zs-moderator-status-form ajax-form">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="id" value="<?php echo $articleId; ?>">
                      <input type="hidden" name="return_to" value="moderator">
                      <label class="zs-moderator-label" for="article-status-<?php echo $articleId; ?>">Status tekstu</label>
                      <select id="article-status-<?php echo $articleId; ?>" name="status" class="zs-status-select">
                        <?php foreach ($moderatorStatusOptions as $value => $label): ?>
                          <option value="<?php echo e($value); ?>" <?php echo $currentStatus === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="zs-btn-red zs-btn-compact">ZATWIERDŹ STATUS</button>
                    </form>
                    <div class="zs-update-info">
                        <small class="zs-date-human">Aktualizacja: <?php echo !empty($row['updated_at']) ? date('d.m.Y H:i', strtotime($row['updated_at'])) : '--.--.---- --:--'; ?></small>
                    </div>
                  </td>
                  <td>
                    <?php
                      $accessMode = (string)($row['access_mode'] ?? 'free');
                      $pricingStatus = (string)($row['pricing_status'] ?? (($accessMode === 'paid') ? 'priced' : 'free'));
                      $priceMinor = (int)($row['price_minor'] ?? 0);
                      $priceValue = number_format($priceMinor / 100, 2, ',', '');
                      $priceDisplay = number_format($priceMinor / 100, 2, ',', ' ');
                      $authorShare = ((int)($revenue_split_policy['author_basis_points'] ?? 4000)) / 100;
                      $platformShare = ((int)($revenue_split_policy['platform_basis_points'] ?? 4000)) / 100;
                      $safetyFundShare = ((int)($revenue_split_policy['safety_fund_basis_points'] ?? 2000)) / 100;
                      $articleId = (int)($row['id'] ?? 0);
                      $translationsForArticle = $articleTranslationsMap[$articleId] ?? [];
                    ?>
                    <div class="zs-moderator-workspace">
                      <div class="zs-moderator-line zs-moderator-pricing-line">
                        <div class="zs-moderator-line-label">WYCENA I ETYKIETA ARTYKUŁU</div>
                        <div class="zs-moderator-valuation-summary" id="valuation-summary-<?php echo $articleId; ?>">
                          <span class="zs-status-badge zs-access-badge <?php echo e($accessMode); ?>"><?php echo e($accessLabels[$accessMode] ?? $accessMode); ?></span>
                          <span class="zs-status-badge zs-pricing-badge <?php echo e($pricingStatus); ?>"><?php echo e($pricingLabels[$pricingStatus] ?? $pricingStatus); ?></span>
                          <?php
                            $savedArticleLabel = (string)($row['article_label'] ?? '');
                            $savedLabelMeta = $articleLabelOptions[$savedArticleLabel] ?? [
                                'label' => $savedArticleLabel,
                                'color' => '#6b7280',
                            ];
                          ?>
                          <span
                            class="zs-article-label-badge<?php echo $savedArticleLabel === '' ? ' is-hidden' : ''; ?>"
                            id="label-badge-<?php echo $articleId; ?>"
                            style="--article-label-color: <?php echo e((string)$savedLabelMeta['color']); ?>"
                          ><?php echo e((string)$savedLabelMeta['label']); ?></span>
                          <strong class="zs-valuation-price<?php echo $accessMode !== 'paid' || $priceMinor <= 0 ? ' is-hidden' : ''; ?>" id="valuation-price-<?php echo $articleId; ?>"><?php echo e($priceDisplay); ?> <?php echo e($row['currency'] ?? 'PLN'); ?></strong>
                          <small class="zs-valuation-share" id="valuation-share-<?php echo $articleId; ?>">Autor <?php echo number_format($authorShare, 0); ?>% / Serwis <?php echo number_format($platformShare, 0); ?>% / Safety Fund <?php echo number_format($safetyFundShare, 0); ?>%</small>
                        </div>
                        <form id="valuation-form-<?php echo $articleId; ?>" method="post" action="/admin/articles/valuation" class="zs-moderator-valuation-form ajax-form" data-article-id="<?php echo $articleId; ?>">
                          <?php echo csrf_field(); ?>
                          <input type="hidden" name="id" value="<?php echo $articleId; ?>">
                          <input type="hidden" name="return_to" value="moderator">
                          <input type="hidden" name="currency" value="PLN">
                          <label>
                            <span>Etykieta artykułu</span>
                            <select name="article_label" class="zs-article-label-select" data-article-id="<?php echo $articleId; ?>">
                              <option value="">-- wybierz --</option>
                              <?php foreach ($articleLabelOptions as $labelValue => $labelMeta): ?>
                                <option
                                  value="<?php echo e($labelValue); ?>"
                                  data-color="<?php echo e((string)$labelMeta['color']); ?>"
                                  data-label="<?php echo e((string)$labelMeta['label']); ?>"
                                  <?php echo $savedArticleLabel === $labelValue ? 'selected' : ''; ?>
                                ><?php echo e((string)$labelMeta['label']); ?></option>
                              <?php endforeach; ?>
                            </select>
                          </label>

                          <label>
                            <span>Dostęp</span>
                            <select name="access_mode">
                              <option value="free" <?php echo $accessMode === 'free' ? 'selected' : ''; ?>>darmowy</option>
                              <option value="paid" <?php echo $accessMode === 'paid' ? 'selected' : ''; ?>>płatny</option>
                            </select>
                          </label>
                          <label>
                            <span>Cena PLN</span>
                            <input type="text" name="price" value="<?php echo e($priceValue); ?>" placeholder="9,90" inputmode="decimal">
                          </label>
                          <div class="zs-setting-description">Podział ustala globalna polityka Safety Fund. Zmiana wymaga zatwierdzenia w 3DORS Admin.</div>
                          <div class="zs-moderator-flags">
                            <label class="zs-check-inline">
                              <input type="checkbox" name="is_premium" value="1" <?php echo !empty($row['is_premium']) ? 'checked' : ''; ?>> Premium
                            </label>
                            <label class="zs-check-inline">
                              <input type="checkbox" name="is_unique" value="1" <?php echo !empty($row['is_unique']) ? 'checked' : ''; ?>> Unikalny
                            </label>
                          </div>
                          <label class="zs-moderator-note-field">
                            <span>Notatka moderatora</span>
                            <textarea name="editor_valuation_note" rows="2"><?php echo e((string)($row['editor_valuation_note'] ?? '')); ?></textarea>
                          </label>
                          <div class="zs-moderator-update-action">
                            <button type="submit" class="zs-btn-outline zs-btn-compact">AKTUALIZUJ DANE</button>
                            <small>Aktualizacja bez zmiany statusu.</small>
                          </div>
                        </form>
                      </div>

                      <div class="zs-moderator-line zs-moderator-translation-line">
                        <div class="zs-moderator-line-label">TŁUMACZENIA</div>
                        <div class="zs-language-status-block">
                          <div class="zs-language-statuses" aria-label="Status wersji językowych">
                            <?php
                              $sourceLanguage = strtolower(trim((string)($row['source_language'] ?? 'pl')));
                              $translationStatusLabels = [
                                  'draft' => 'szkic',
                                  'ai_draft' => 'szkic AI — wymaga sprawdzenia',
                                  'editor_review' => 'w korekcie',
                                  'approved' => 'zatwierdzone',
                                  'published' => 'opublikowane',
                                  'rejected' => 'odrzucone',
                                  'error' => 'błąd',
                              ];
                            ?>
                            <?php foreach ($publicLanguages as $language): ?>
                              <?php
                                $lang = strtolower((string)$language);
                                $label = $shortLanguageLabels[$lang] ?? strtoupper($lang);
                                $isSourceLanguage = $lang === $sourceLanguage;
                                $translation = !$isSourceLanguage && isset($translationsForArticle[$lang])
                                    ? $translationsForArticle[$lang]
                                    : null;
                                $translationStatus = is_array($translation)
                                    ? (string)($translation['status'] ?? 'draft')
                                    : '';
                                $hasCompleteTranslation = is_array($translation)
                                    && trim((string)($translation['title'] ?? '')) !== ''
                                    && trim((string)($translation['body'] ?? '')) !== '';
                                $requiresReview = $hasCompleteTranslation
                                    && !in_array($translationStatus, ['approved', 'published'], true);
                              ?>

                              <?php if ($isSourceLanguage): ?>
                                <a class="zs-status-badge zs-language-badge is-source" href="/article?id=<?php echo (int)$row['id']; ?>&lang=<?php echo e($sourceLanguage); ?>" target="_blank" rel="noopener" title="Oryginalny tekst: <?php echo e($label); ?>">
                                  <?php echo e($label); ?>
                                </a>
                              <?php elseif (in_array($translationStatus, ['error', 'rejected'], true)): ?>
                                <span class="zs-status-badge zs-language-badge is-error" title="<?php echo e($label); ?>: <?php echo e($translationStatusLabels[$translationStatus] ?? $translationStatus); ?>">
                                  <?php echo e($label); ?>
                                </span>
                              <?php elseif ($hasCompleteTranslation): ?>
                                <a class="zs-status-badge zs-language-badge is-translated<?php echo $requiresReview ? ' needs-review' : ''; ?>" href="/article?id=<?php echo (int)$row['id']; ?>&lang=<?php echo e($lang); ?>&preview_lang=<?php echo e($lang); ?>" target="_blank" rel="noopener" title="<?php echo e($label); ?>: tłumaczenie zapisane (<?php echo e($translationStatusLabels[$translationStatus] ?? $translationStatus); ?>)">
                                  <?php echo e($label); ?>
                                  <?php if ($requiresReview): ?><span class="zs-language-review-dot" aria-hidden="true"></span><?php endif; ?>
                                </a>
                              <?php elseif (is_array($translation)): ?>
                                <span class="zs-status-badge zs-language-badge is-incomplete" title="<?php echo e($label); ?>: niekompletne tłumaczenie">
                                  <?php echo e($label); ?>
                                </span>
                              <?php else: ?>
                                <span class="zs-status-badge zs-language-badge is-missing" title="<?php echo e($label); ?>: brak tłumaczenia">
                                  <?php echo e($label); ?>
                                </span>
                              <?php endif; ?>
                            <?php endforeach; ?>
                          </div>
                          <?php require __DIR__ . '/../partials/translation_status_legend.php'; ?>
                        </div>
                        <form class="zs-ai-translate-form" method="post" action="/admin/articles/translations/ai-package">
                          <?php echo csrf_field(); ?>
                          <input type="hidden" name="article_id" value="<?php echo (int)$row['id']; ?>">
                          <input type="hidden" name="translation_instructions" value="">
                          <button type="submit" class="zs-btn-outline zs-btn-compact zs-ai-translate-btn">GENERUJ TŁUMACZENIA</button>
                          <small class="zs-ai-translate-status" aria-live="polite"></small>
                        </form>
                      </div>
                    </div>
                  </td>
                <?php else: ?>
                  <td>
                    <span class="zs-status-badge <?php echo e($row['status'] ?? ''); ?>">
                      <?php echo e($articleStatusMap[$row['status'] ?? ''] ?? ($row['status'] ?? '')); ?>
                    </span>
                  </td>
                  <td>
                      <?php if ($isChiefEditor): ?>
                        <?php
                          $blockUntilRaw = (string)($row['article_submit_blocked_until'] ?? '');
                          $blockActive = $blockUntilRaw !== '' && strtotime($blockUntilRaw) > time();
                        ?>
                        <div class="zs-author-block-box">
                          <?php if ($blockActive): ?>
                            <div class="zs-author-block-active">Zablokowany do: <?php echo date('d.m.Y H:i', strtotime($blockUntilRaw)); ?></div>
                            <?php if (!empty($row['article_submit_block_reason'])): ?>
                              <small class="zs-muted"><?php echo e((string)$row['article_submit_block_reason']); ?></small>
                            <?php endif; ?>
                          <?php else: ?>
                            <small class="zs-muted">Autor może przesyłać teksty.</small>
                          <?php endif; ?>
                          <form method="post" action="/admin/authors/submit-block" class="ajax-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="author_id" value="<?php echo (int)($row['author_id'] ?? 0); ?>">
                            <select name="duration" aria-label="Czas blokady przesyłania tekstów">
                              <option value="24h">24h</option>
                              <option value="7d">7 dni</option>
                              <option value="30d">30 dni</option>
                            </select>
                            <input type="text" name="reason" placeholder="Powód, opcjonalnie">
                            <button class="zs-btn-small" type="submit">Zablokuj</button>
                          </form>
                          <?php if ($blockActive): ?>
                            <form method="post" action="/admin/authors/submit-block">
                              <?php echo csrf_field(); ?>
                              <input type="hidden" name="author_id" value="<?php echo (int)($row['author_id'] ?? 0); ?>">
                              <input type="hidden" name="duration" value="clear">
                              <button class="zs-btn-small" type="submit">Zdejmij blokadę</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      <?php elseif (($row['access_mode'] ?? 'free') === 'paid' || !empty($row['is_premium'])): ?>
                          <span class="zs-status-badge paid">PŁATNY</span>
                      <?php else: ?>
                          <span class="zs-status-badge">DARMOWY</span>
                      <?php endif; ?>
                  </td>
                  <td><small class="zs-date-human"><?php echo date('d.m.Y H:i', strtotime($row['updated_at'] ?? 'now')); ?></small></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
    <?php endif; ?>
  <?php endif; ?>

  <div class="zs-pagination-bar">
    <?php $prev = max(1, (int)($snajper_page ?? 1) - 1); $next = (int)($snajper_page ?? 1) + 1; ?>
    <a class="zs-btn-small" href="/admin/role-panel?panel=<?php echo e($panel_code ?? ''); ?>&page=<?php echo $prev; ?>">&laquo; Poprzednia strona</a>
    <span class="zs-pagination-info">Strona <?php echo (int)($snajper_page ?? 1); ?></span>
    <a class="zs-btn-small" href="/admin/role-panel?panel=<?php echo e($panel_code ?? ''); ?>&page=<?php echo $next; ?>">Następna strona &raquo;</a>
  </div>
</section>

<div class="zs-panel-footer">
  <a href="/admin" class="zs-link-aux">Powrót do dashboardu</a>
  <span class="zs-sep">|</span>
  <a href="/admin/roles" class="zs-link-aux">Role i uprawnienia</a>
</div>



<script>
document.addEventListener('DOMContentLoaded', function() {
  function showLocalMessage(element, text, type, preferredContainer = null) {
    const container = preferredContainer || element.parentNode;
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

  // Universal AJAX form handler
  document.querySelectorAll('.ajax-form, .zs-ai-translate-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"], .zs-btn-small, .zs-ai-translate-btn');
      const originalText = button ? button.textContent : 'Zapisz';

      if (button) {
        button.disabled = true;
        button.textContent = 'Zapisywanie...';
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
          const messageContainer = form.classList.contains('zs-moderator-valuation-form') ? form : null;
          showLocalMessage(button || form, data.message || 'Zapisano', 'success', messageContainer);

          // Update last update date if exists in the same row
          const row = form.closest('tr');
          if (row) {
            const dateEl = row.querySelector('.zs-date-human');
            if (dateEl && dateEl.textContent.includes('Aktualizacja:')) {
                const now = new Date();
                const formatted = now.getDate().toString().padStart(2, '0') + '.' +
                                (now.getMonth() + 1).toString().padStart(2, '0') + '.' +
                                now.getFullYear() + ' ' +
                                now.getHours().toString().padStart(2, '0') + ':' +
                                now.getMinutes().toString().padStart(2, '0');
                dateEl.textContent = 'Aktualizacja: ' + formatted;
            }
          }

          if (form.classList.contains('zs-moderator-valuation-form')) {
            const accessMode = form.querySelector('[name="access_mode"]')?.value || 'free';
            const priceValue = form.querySelector('[name="price"]')?.value || '0,00';
            const summary = document.getElementById('valuation-summary-' + form.dataset.articleId);
            const accessBadge = summary?.querySelector('.zs-access-badge');
            const pricingBadge = summary?.querySelector('.zs-pricing-badge');
            const price = document.getElementById('valuation-price-' + form.dataset.articleId);
            const share = document.getElementById('valuation-share-' + form.dataset.articleId);

            if (accessBadge) {
              accessBadge.textContent = accessMode === 'paid' ? 'płatny' : 'bezpłatny';
              accessBadge.className = 'zs-status-badge zs-access-badge ' + accessMode;
            }
            if (pricingBadge) {
              pricingBadge.textContent = accessMode === 'paid' ? 'wyceniony' : 'darmowy';
              pricingBadge.className = 'zs-status-badge zs-pricing-badge ' + (accessMode === 'paid' ? 'priced' : 'free');
            }
            if (price) {
              price.textContent = priceValue.replace('.', ',') + ' PLN';
              price.classList.toggle('is-hidden', accessMode !== 'paid');
            }
            if (share) {
              share.textContent = 'Autor <?= e(number_format(((int)($revenue_split_policy['author_basis_points'] ?? 4000)) / 100, 0)) ?>% / Serwis <?= e(number_format(((int)($revenue_split_policy['platform_basis_points'] ?? 4000)) / 100, 0)) ?>% / Safety Fund <?= e(number_format(((int)($revenue_split_policy['safety_fund_basis_points'] ?? 2000)) / 100, 0)) ?>%';
            }
          }
        } else {
          const messageContainer = form.classList.contains('zs-moderator-valuation-form') ? form : null;
          showLocalMessage(button || form, data.message || 'Błąd zapisu', 'error', messageContainer);
        }
      } catch (error) {
        if (button) {
          button.disabled = false;
          button.textContent = originalText;
        }
        const messageContainer = form.classList.contains('zs-moderator-valuation-form') ? form : null;
        showLocalMessage(button || form, 'Błąd połączenia', 'error', messageContainer);
      }
    });
  });

  // Article labels badge update
  document.querySelectorAll('.zs-article-label-select').forEach(function(select) {
    select.addEventListener('change', function() {
      const articleId = this.dataset.articleId;
      const selectedOption = this.options[this.selectedIndex];
      const badge = document.getElementById('label-badge-' + articleId);

      if (selectedOption.value) {
        badge.innerText = selectedOption.dataset.label || selectedOption.value;
        badge.style.setProperty('--article-label-color', selectedOption.dataset.color || '#6b7280');
        badge.classList.remove('is-hidden');
      } else {
        badge.classList.add('is-hidden');
      }
    });
  });

  // Access mode Suggestion
  document.querySelectorAll('select[name="access_mode"]').forEach(function(select) {
    select.addEventListener('change', function() {
      const row = this.closest('tr');
      if (!row) return;
      const priceInput = document.querySelector(`input[name="price"][form="${select.getAttribute('form')}"]`) || row.querySelector('input[name="price"]');
      if (this.value === 'paid') {
        if (priceInput && (priceInput.value === '0,00' || priceInput.value === '0' || priceInput.value === '')) {
          priceInput.value = '10,00';
          priceInput.style.backgroundColor = '#fff0f0';
          setTimeout(() => { priceInput.style.backgroundColor = ''; }, 1000);
        }
      } else {
        if (priceInput) priceInput.value = '0,00';
      }
    });
  });
});
</script>
