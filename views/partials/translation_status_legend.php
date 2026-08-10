<?php
$translationLegendMode = (string)($translation_legend_mode ?? 'versions');
?>

<?php if ($translationLegendMode === 'workflow'): ?>
  <div class="zs-language-legend zs-language-legend-workflow" aria-label="<?= e(t('ui.partials.translation_status_legend.legenda_etapow_pracy_nad_tumaczeniem')) ?>">
    <span><i class="is-pending"></i><?= e(t('ui.partials.translation_status_legend.szkic_do_korekty')) ?></span>
    <span><i class="is-translated"></i><?= e(t('ui.partials.translation_status_legend.zatwierdzone_opublikowane')) ?></span>
    <span><i class="is-error"></i><?= e(t('ui.partials.translation_status_legend.bad_odrzucone')) ?></span>
  </div>
  <p class="zs-language-legend-note"><?= e(t('ui.partials.translation_status_legend.ai_tylko_przygotowuje_szkic_wydawca_sprawdza_tresc_i_po_a0e04734')) ?></p>
<?php else: ?>
  <div class="zs-language-legend" aria-label="<?= e(t('ui.partials.translation_status_legend.legenda_statusow_wersji_jezykowych')) ?>">
    <span><i class="is-source"></i><?= e(t('ui.partials.translation_status_legend.orygina')) ?></span>
    <span><i class="is-translated"></i><?= e(t('ui.partials.translation_status_legend.tumaczenie_zapisane')) ?></span>
    <span><i class="is-missing"></i><?= e(t('ui.partials.translation_status_legend.brak_tumaczenia')) ?></span>
    <span><i class="needs-review"></i><?= e(t('ui.partials.translation_status_legend.wydawca_musi_sprawdzic')) ?></span>
    <span><i class="is-error"></i><?= e(t('ui.partials.translation_status_legend.bad_odrzucone')) ?></span>
  </div>
  <p class="zs-language-legend-note"><?= e(t('ui.partials.translation_status_legend.zielony_oznacza_ze_tumaczenie_istnieje_pomaranczowa_kro_19b228d6')) ?></p>
<?php endif; ?>
