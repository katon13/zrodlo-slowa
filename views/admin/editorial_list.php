<?php
$statusLabels = [
    'draft' => t('article.status.draft'),
    'submitted' => t('admin.editorial_list.tekst_przyszed_do_redakcji'),
    'review' => t('article.status.review'),
    'approved' => t('admin.editorial_list.zaakceptowany_przez_redakcje'),
    'published' => t('article.status.published'),
    'rejected' => t('article.status.rejected'),
    'archived' => t('article.status.archived'),
];
$publicLanguages = is_array($languages['public_enabled'] ?? null)
    ? array_values($languages['public_enabled'])
    : ['pl', 'en', 'de', 'fr', 'it', 'es'];
$articleTranslationsMap = is_array($article_translations_map ?? null) ? $article_translations_map : [];
?>

<section class="admin-page-head">
  <p class="kicker"><?= t('editorial.editing.kicker') ?></p>
  <h1><?= t('editorial.editing.title') ?></h1>
  <p><?= t('editorial.editing.description') ?></p>
  <p><a class="btn-line compact" href="/admin/bug-reports"><?= e(t('admin.editorial_list.sprawdz_zgoszenia_bedow')) ?></a></p>
</section>

<section class="admin-panel-block">
  <div class="admin-section-head">
    <div>
      <p class="kicker"><?= e(t('admin.editorial_list.warsztat_wydawcy')) ?></p>
      <h2><?= e(t('editorial.editing.order_title')) ?></h2>
      <p><?= t('editorial.editing.order_description') ?></p>
    </div>
    <button type="button" class="zs-btn" id="save-order-btn"><?= t('editorial.editing.save_order') ?></button>
  </div>
  
  <div id="editorial-order-container" class="admin-notice-info u-mt-4" style="padding: 1rem; border: 1px dashed #cbd5e0; border-radius: 8px;">
    <p style="font-size: 0.9rem; margin-bottom: 1rem; color: #718096;"><?= e(t('admin.editorial_list.przeciagnij_teksty_ponizej_aby_ustalic_ich_kolejnosc_publiczna')) ?></p>
    <div id="sortable-list" class="sortable-list">
      <?php foreach ($articles as $a): ?>
        <div class="sortable-item" data-id="<?= (int)$a['id'] ?>" draggable="true">
          <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" class="drag-handle"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
          <span class="sort-title"><?= e($a['title']) ?></span>
          <span class="sort-meta">#<?= (int)$a['id'] ?> | <?= e($a['author_name'] ?? '') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="admin-panel-block editorial-list-section u-mt-8">
  <div id="editorial-notice" class="u-mt-4">
    <?php if (!empty($flash_success)): ?>
      <div class="notice success"><?= e($flash_success) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash_error)): ?>
      <div class="notice error"><?= e($flash_error) ?></div>
    <?php endif; ?>
  </div>

  <div class="admin-section-head u-mt-6">
    <div>
      <p class="kicker"><?= e(t('admin.editorial_list.zasoby_redakcyjne')) ?></p>
      <h2><?= e(t('admin.articles.lista_tekstow')) ?></h2>
    </div>
    <span><?= e(str_replace('{count}', (string)count($articles), t('admin.common.items_count'))) ?></span>
  </div>
  <?php require __DIR__ . '/../partials/translation_status_legend.php'; ?>

  <?php if (empty($articles)): ?>
    <div class="zs-empty-state">
      <p class="zs-empty-title"><?= e(t('admin.editorial_list.brak_tekstow_do_wyswietlenia')) ?></p>
      <p class="zs-empty-desc"><?= e(t('admin.editorial_list.poczekaj_na_teksty_zaakceptowane_przez_redakcje_gowna')) ?></p>
    </div>
  <?php else: ?>
    <div class="editorial-articles-list">
      <?php foreach ($articles as $a): ?>
        <?php
          $id = (int)$a['id'];
          $status = (string)($a['status'] ?? 'draft');
          $updatedAt = (string)($a['updated_at'] ?? '');
          $mainImage = (string)($a['main_image'] ?? '');
          $proofreadAt = (string)($a['proofread_at'] ?? '');
          $sourceLanguage = strtolower((string)($a['source_language'] ?? 'pl'));
          $translationsForArticle = is_array($articleTranslationsMap[$id] ?? null) ? $articleTranslationsMap[$id] : [];
        ?>
        <div class="editorial-article-card" id="article-<?= $id ?>" data-status="<?= e($status) ?>">
          <div class="editorial-card-main">
            <div class="editorial-card-thumb">
              <?php if ($mainImage): ?>
                <img src="<?= e($mainImage) ?>" alt="">
              <?php else: ?>
                <div class="thumb-placeholder"><?= e(t('admin.editorial_list.brak_zdjecia')) ?></div>
              <?php endif; ?>
            </div>
            
            <div class="editorial-card-info">
              <div class="info-top">
                <span class="admin-label">ID #<?= $id ?> | <?= e($statusLabels[$status] ?? $status) ?></span>
                <?php if (!empty($a['response_to_article_id'])): ?>
                  <span class="zs-status-badge review"><?= e(str_replace('{id}', (string)(int)$a['response_to_article_id'], t('admin.editorial_edit.response_heading'))) ?></span>
                <?php endif; ?>
                <?php if ($proofreadAt !== ''): ?>
                  <span class="zs-status-badge review editorial-proofread-badge"><?= e(t('admin.dashboard.korekta')) ?></span>
                <?php endif; ?>
              </div>
              <h3><?= e($a['title']) ?></h3>
              <div class="editorial-card-meta">
                <span><?= e(t('admin.editorial_list.autor')) ?> <strong><?= e($a['author_name'] ?? '—') ?></strong></span>
                <span><?= e(t('admin.editorial_list.kategoria')) ?> <strong><?= e($a['category_name'] ?? '—') ?></strong></span>
                <?php if (!empty($a['response_to_article_id'])): ?>
                  <span><?= e(t('admin.articles.talent')) ?> <strong><?= $a['response_reward_qualified'] === null ? t('admin.editorial_list.oczekuje_na_publikacje') : (!empty($a['response_reward_qualified']) ? ((int)$a['response_reward_points'] . ' TT · snapshot') : '0 TT · snapshot niekwalifikowany') ?></strong></span>
                  <?php $depositStatusLabels = ['not_required' => t('response.deposit_status.not_required'), 'held' => t('response.deposit_status.held'), 'forfeited' => t('response.deposit_status.forfeited'), 'refunded' => t('response.deposit_status.refunded')]; ?>
                  <span><?= e(t('admin.editorial_list.kaucja')) ?> <strong><?= $a['response_deposit_status'] === null ? 'niepobrana' : e($depositStatusLabels[(string)$a['response_deposit_status']] ?? (string)$a['response_deposit_status']) ?><?php if ($a['response_deposit_points'] !== null): ?> · <?= (int)$a['response_deposit_points'] ?> TT<?php endif; ?></strong></span>
                <?php endif; ?>
              </div>
              <div class="editorial-card-dates">
                <small><?= e(t('admin.editorial_list.change_prefix')) ?> <?= e($updatedAt ? date('d.m.Y H:i', strtotime($updatedAt)) : '—') ?></small>
                <?php if ($proofreadAt !== ''): ?>
                  <small><?= e(str_replace('{date}', date('d.m.Y H:i', strtotime($proofreadAt)), t('admin.editorial_list.proofreading_date'))) ?></small>
                <?php endif; ?>
              </div>
            </div>

            <div class="editorial-card-side">
              <div class="editorial-side-stats">
                <div class="ranking-item">
                  <label><?= e(t('editorial.editing.display_order')) ?></label>
                  <span><?= (int)($a['display_order'] ?? 0) ?></span>
                </div>
                <div class="ranking-item">
                  <label><?= e(t('admin.editorial_list.waga')) ?></label>
                  <span><?= (int)($a['editorial_weight'] ?? 0) ?></span>
                </div>
                <div class="ranking-item">
                  <label><?= e(t('admin.editorial_list.odsony')) ?></label>
                  <span><?= (int)($a['view_count'] ?? 0) ?></span>
                </div>
              </div>

              <div class="zs-language-status-block">
                <div class="zs-language-statuses" aria-label="<?= e(t('admin.editorial_list.status_wersji_jezykowych')) ?>">
                  <?php foreach ($publicLanguages as $language): ?>
                    <?php
                      $language = strtolower((string)$language);
                      $isSourceLanguage = $language === $sourceLanguage;
                      $translation = !$isSourceLanguage && is_array($translationsForArticle[$language] ?? null)
                          ? $translationsForArticle[$language]
                          : null;
                      $translationStatus = is_array($translation) ? (string)($translation['status'] ?? 'draft') : '';
                      $hasCompleteTranslation = is_array($translation)
                          && trim((string)($translation['title'] ?? '')) !== ''
                          && trim((string)($translation['body'] ?? '')) !== '';
                      $needsReview = $hasCompleteTranslation
                          && !in_array($translationStatus, ['approved', 'published'], true);
                      $editUrl = '/admin/editorial/edit?id=' . $id . '&translation_lang=' . rawurlencode($language)
                          . '#translation-' . rawurlencode($language);
                    ?>
                    <?php if ($isSourceLanguage): ?>
                      <a class="zs-status-badge zs-language-badge is-source" href="<?= e($editUrl) ?>" title="<?= e(strtoupper($language) . t('admin.editorial_list.orygina')) ?>"><?= e(strtoupper($language)) ?></a>
                    <?php elseif (in_array($translationStatus, ['error', 'rejected'], true)): ?>
                      <a class="zs-status-badge zs-language-badge is-error" href="<?= e($editUrl) ?>" title="<?= e(strtoupper($language) . t('admin.editorial_list.bad_lub_odrzucone')) ?>"><?= e(strtoupper($language)) ?></a>
                    <?php elseif ($hasCompleteTranslation): ?>
                      <a class="zs-status-badge zs-language-badge is-translated<?= $needsReview ? ' needs-review' : '' ?>" href="<?= e($editUrl) ?>" title="<?= e(strtoupper($language) . t('admin.editorial_list.tumaczenie_zapisane')) ?>">
                        <?= e(strtoupper($language)) ?>
                        <?php if ($needsReview): ?><span class="zs-language-review-dot" aria-hidden="true"></span><?php endif; ?>
                      </a>
                    <?php elseif (is_array($translation)): ?>
                      <a class="zs-status-badge zs-language-badge is-incomplete" href="<?= e($editUrl) ?>" title="<?= e(strtoupper($language) . t('admin.editorial_list.niekompletne_tumaczenie')) ?>"><?= e(strtoupper($language)) ?></a>
                    <?php else: ?>
                      <span class="zs-status-badge zs-language-badge is-missing" title="<?= e(strtoupper($language) . t('admin.editorial_list.brak_tumaczenia')) ?>"><?= e(strtoupper($language)) ?></span>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="editorial-pricing-row u-mt-2">
                <div class="ranking-item">
                  <label><?= e(t('admin.editorial_list.wycena_tekstu')) ?></label>
                  <span class="price-val"><?= number_format(($a['price_minor'] ?? 0) / 100, 2, ',', ' ') ?> PLN</span>
                </div>
                <div class="ranking-badges">
                  <?php if (!empty($a['is_premium'])): ?>
                    <span class="zs-status-badge premium"><?= e(t('admin.editorial_list.premium')) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($a['is_unique'])): ?>
                    <span class="zs-status-badge unique"><?= e(t('admin.editorial_list.unikalny')) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($a['is_featured'])): ?>
                    <span class="zs-status-badge featured"><?= e(t('admin.editorial_list.promocja')) ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="editorial-side-actions">
                <a href="/admin/editorial/edit?id=<?= $id ?>" class="zs-btn-small" title="<?= e(t('admin.editorial_list.aktualizuj_tresc_i_ustawienia')) ?>"><?= e(t('author.dashboard.edit')) ?></a>
                <a href="/article?id=<?= $id ?>&lang=<?= e($sourceLanguage) ?>" class="zs-btn-small btn-outline" target="_blank" rel="noopener"><?= e(t('editorial.editing.preview')) ?></a>
                <button type="button" class="zs-btn-small btn-outline btn-toggle-featured" data-id="<?= $id ?>" data-val="<?= !empty($a['is_featured']) ? 0 : 1 ?>">
                        <?= e(t(!empty($a['is_featured']) ? 'admin.editorial_list.unpin' : 'admin.editorial_list.promote')) ?>
                </button>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<style>
.editorial-list-section { width: 100%; max-width: none; }
.editorial-articles-list { display: flex; flex-direction: column; gap: 1rem; margin-top: 1.5rem; width: 100%; }
.editorial-article-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.25rem; display: flex; flex-direction: column; gap: 0.75rem; transition: box-shadow 0.2s; border-left: 4px solid #cbd5e0; position: relative; }
.editorial-article-card:hover { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
.editorial-article-card[data-status="published"] { border-left-color: #48bb78; }
.editorial-article-card[data-status="approved"] { border-left-color: #4299e1; }
.editorial-article-card[data-status="review"] { border-left-color: #ed8936; }
.editorial-article-card[data-status="rejected"] { border-left-color: #e53e3e; }

.editorial-card-main { display: flex; gap: 1.5rem; align-items: stretch; }
.editorial-card-thumb { width: 120px; height: 90px; flex-shrink: 0; background: #f7fafc; border-radius: 6px; overflow: hidden; border: 1px solid #edf2f7; }
.editorial-card-thumb img { width: 100%; height: 100%; object-fit: cover; }
.thumb-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #a0aec0; text-align: center; border: 1px dashed #cbd5e0; line-height: 1; padding: 5px; }
.editorial-card-info { flex-grow: 1; display: flex; flex-direction: column; justify-content: flex-start; }
.info-top { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.25rem; }
.editorial-proofread-badge { font-size: 9px; padding: 1px 4px; line-height: 1; height: 14px; margin-top: 0; }
.editorial-card-info h3 { margin: 0 0 0.5rem 0; font-size: 1.25rem; color: #1a202c; line-height: 1.3; font-weight: 800; letter-spacing: -0.01em; }
.editorial-card-meta { display: flex; gap: 1.25rem; font-size: 0.85rem; color: #4a5568; }
.editorial-card-meta strong { color: #2d3748; }
.editorial-card-dates { margin-top: 0.75rem; color: #718096; display: flex; gap: 1rem; }
.editorial-card-dates small { font-size: 0.75rem; background: #f8fafc; padding: 2px 6px; border-radius: 3px; border: 1px solid #edf2f7; }

.editorial-card-side { width: 400px; display: flex; flex-direction: column; gap: 1rem; border-left: 1px solid #edf2f7; padding-left: 1.5rem; }
.editorial-side-stats { display: flex; gap: 1.5rem; align-items: flex-start; padding-bottom: 0.75rem; border-bottom: 1px solid #f7fafc; }
.ranking-item { display: flex; flex-direction: column; }
.ranking-item label { font-size: 9px; text-transform: uppercase; color: #a0aec0; letter-spacing: 0.05em; margin-bottom: 4px; font-weight: 600; }
.ranking-item span { font-weight: 700; color: #2d3748; font-size: 1rem; }
.price-val { color: #2f855a !important; font-size: 1.1rem !important; }

.editorial-pricing-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
.ranking-badges { display: flex; gap: 0.35rem; flex-wrap: wrap; justify-content: flex-end; }
.ranking-badges .zs-status-badge { font-size: 9px; padding: 3px 6px; font-weight: 700; border-radius: 4px; }
.zs-status-badge.premium { background: #ebf8ff; color: #2b6cb0; border: 1px solid #bee3f8; }
.zs-status-badge.unique { background: #faf5ff; color: #6b46c1; border: 1px solid #e9d8fd; }
.zs-status-badge.featured { background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; }

.editorial-side-actions { display: flex; gap: 0.5rem; margin-top: auto; padding-top: 0.5rem; }
.editorial-side-actions .zs-btn-small { flex: 1; text-align: center; justify-content: center; }

.u-mt-6 { margin-top: 1.5rem; }
.u-mt-8 { margin-top: 2rem; }

.sortable-list { display: flex; flex-direction: column; gap: 0.5rem; }
.sortable-item { display: flex; align-items: center; gap: 1rem; padding: 0.75rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; cursor: grab; }
.sortable-item:active { cursor: grabbing; }
.sortable-item.dragging { opacity: 0.5; background: #ebf8ff; }
.drag-handle { color: #a0aec0; cursor: grab; }
.sort-title { flex-grow: 1; font-weight: 500; font-size: 0.95rem; }
.sort-meta { color: #718096; font-size: 0.85rem; }

.admin-notice-success { background: #f0fff4; color: #2f855a; border: 1px solid #c6f6d5; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; }
.admin-notice-error { background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; }

/* AJAX Notices */
.zs-local-notice { font-size: 11px; margin-top: 4px; padding: 2px 6px; border-radius: 4px; transition: opacity 0.5s; display: block; width: 100%; clear: both; }
.zs-local-notice.success { background: #f0fff4; color: #2f855a; border: 1px solid #c6f6d5; }
.zs-local-notice.error { background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; }
</style>

<script>
const editorialListUi = <?= json_encode([
    'saveError' => t('common.save_error'),
    'statusError' => t('admin.editorial_list.status_change_error'),
    'unpin' => t('admin.editorial_list.unpin'),
    'promote' => t('admin.editorial_list.promote'),
    'promotionBadge' => t('admin.editorial_list.promotion_badge'),
    'saving' => t('admin.role_panel.saving'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('DOMContentLoaded', function() {
    const list = document.getElementById('sortable-list');
    let draggingItem = null;

    list.addEventListener('dragstart', (e) => {
        draggingItem = e.target;
        setTimeout(() => e.target.classList.add('dragging'), 0);
    });

    list.addEventListener('dragend', (e) => {
        e.target.classList.remove('dragging');
        draggingItem = null;
    });

    list.addEventListener('dragover', (e) => {
        e.preventDefault();
        const afterElement = getDragAfterElement(list, e.clientY);
        if (afterElement == null) {
            list.appendChild(draggingItem);
        } else {
            list.insertBefore(draggingItem, afterElement);
        }
    });

    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.sortable-item:not(.dragging)')];
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    // Save Order
    document.getElementById('save-order-btn').addEventListener('click', function() {
        const items = [...list.querySelectorAll('.sortable-item')];
        const order = items.map(item => item.dataset.id);
        const btn = this;
        
        btn.disabled = true;
        btn.innerText = editorialListUi.saving;

        fetch('/admin/editorial/save-order', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': '<?= csrf_token() ?>'
            },
            body: 'order[]=' + order.join('&order[]=')
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showLocalNotice(btn, 'success', data.message);
            } else {
                showLocalNotice(btn, 'error', data.message);
            }
        })
        .catch(() => showLocalNotice(btn, 'error', editorialListUi.saveError))
        .finally(() => {
            btn.disabled = false;
            btn.innerText = '<?= t('editorial.editing.save_order') ?>';
        });
    });

    // Toggle Featured
    document.querySelectorAll('.btn-toggle-featured').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const val = this.dataset.val;
            const originalText = btn.innerText;
            
            btn.disabled = true;
            btn.innerText = '...';

            fetch('/admin/editorial/toggle-featured', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '<?= csrf_token() ?>'
                },
                body: `id=${id}&is_featured=${val}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showLocalNotice(btn, 'success', data.message);
                    // Update button
                    const newVal = val === '1' ? '0' : '1';
                    btn.dataset.val = newVal;
      btn.innerText = val === '1' ? editorialListUi.unpin : editorialListUi.promote;

                    // Update badge
                    const card = document.getElementById('article-' + id);
                    if (card) {
                        const badges = card.querySelector('.ranking-badges');
                        if (badges) {
                            let featuredBadge = badges.querySelector('.zs-status-badge.featured');
                            if (val === '1' && !featuredBadge) {
                                featuredBadge = document.createElement('span');
                                featuredBadge.className = 'zs-status-badge featured';
                                featuredBadge.innerText = editorialListUi.promotionBadge;
                                badges.appendChild(featuredBadge);
                            } else if (val === '0' && featuredBadge) {
                                featuredBadge.remove();
                            }
                        }
                    }
                } else {
                    btn.innerText = originalText;
                    showLocalNotice(btn, 'error', data.message);
                }
            })
            .catch(err => {
                btn.innerText = originalText;
                showLocalNotice(btn, 'error', editorialListUi.statusError);
            })
            .finally(() => {
                btn.disabled = false;
            });
        });
    });

    function showLocalNotice(element, type, msg) {
        let container = element.parentNode;
        let notice = container.querySelector('.zs-local-notice');
        if (!notice) {
            notice = document.createElement('div');
            notice.className = 'zs-local-notice';
            container.appendChild(notice);
        }
        notice.textContent = msg;
        notice.className = 'zs-local-notice ' + type;
        notice.style.display = 'block';
        notice.style.opacity = '1';

        setTimeout(() => {
            notice.style.opacity = '0';
            setTimeout(() => notice.remove(), 500);
        }, 3000);
    }
});
</script>
