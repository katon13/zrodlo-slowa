<?php
$translationLegendMode = (string)($translation_legend_mode ?? 'versions');
?>

<?php if ($translationLegendMode === 'workflow'): ?>
  <div class="zs-language-legend zs-language-legend-workflow" aria-label="Legenda etapów pracy nad tłumaczeniem">
    <span><i class="is-pending"></i>szkic / do korekty</span>
    <span><i class="is-translated"></i>zatwierdzone / opublikowane</span>
    <span><i class="is-error"></i>błąd / odrzucone</span>
  </div>
  <p class="zs-language-legend-note">AI tylko przygotowuje szkic. Wydawca sprawdza treść i podejmuje decyzję o akceptacji oraz publikacji.</p>
<?php else: ?>
  <div class="zs-language-legend" aria-label="Legenda statusów wersji językowych">
    <span><i class="is-source"></i>oryginał</span>
    <span><i class="is-translated"></i>tłumaczenie zapisane</span>
    <span><i class="is-missing"></i>brak tłumaczenia</span>
    <span><i class="needs-review"></i>Wydawca musi sprawdzić</span>
    <span><i class="is-error"></i>błąd / odrzucone</span>
  </div>
  <p class="zs-language-legend-note">Zielony oznacza, że tłumaczenie istnieje. Pomarańczowa kropka oznacza, że Wydawca musi je sprawdzić i zaakceptować przed publikacją.</p>
<?php endif; ?>
