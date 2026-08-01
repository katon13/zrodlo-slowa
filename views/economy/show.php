<?php
$flows = $money_flows ?? [];
?>
<section class="economy-hero">
  <p class="kicker">Pisz. Publikuj. Zarabiaj.</p>
  <h1>Jak zarabia się w ŹRÓDLE SŁOWA</h1>
  <p class="lead">System pokazuje prostą drogę wartości: kto płaci, za co płaci, komu trafiają środki i gdzie zostaje ślad w portfelu.</p>
</section>

<section class="money-principle-grid">
  <article class="money-principle-card">
    <span>01</span>
    <h2>Tekst ma wartość</h2>
    <p>Redakcja ocenia tekst, ustala cenę i status: darmowy, płatny, premium albo unikalny.</p>
  </article>
  <article class="money-principle-card is-red">
    <span>02</span>
    <h2>Autor dostaje 70%</h2>
    <p>Po zakupie tekstu system księguje udział autora i udział serwisu. Autor widzi przychód w portfelu.</p>
  </article>
  <article class="money-principle-card">
    <span>03</span>
    <h2>Użytkownik też zarabia</h2>
    <p>Bonusy za aktywność, ankiety, reklamy, kliknięcia, PPV i live trafiają do historii portfela.</p>
  </article>
</section>

<section class="admin-panel-block money-map-block">
  <div class="admin-section-head">
    <div>
      <p class="kicker">Mapa pieniędzy</p>
      <h2>Za co, komu i gdzie idą środki</h2>
    </div>
    <span>ledger = ślad każdej operacji</span>
  </div>
  <div class="money-flow-list">
    <?php foreach ($flows as $flow): ?>
      <article class="money-flow-row">
        <div class="money-flow-title">
          <strong><?= e((string)($flow['label'] ?? '')) ?></strong>
          <small><?= e((string)($flow['note'] ?? '')) ?></small>
        </div>
        <div><span>Płaci</span><b><?= e((string)($flow['payer'] ?? '')) ?></b></div>
        <div><span>Za co</span><b><?= e((string)($flow['action'] ?? '')) ?></b></div>
        <div><span>Dostaje</span><b><?= e((string)($flow['receiver'] ?? '')) ?></b></div>
        <div>
          <span>Zapis</span>
          <?php if (isset($flow['wallet']) && is_array($flow['wallet'])): ?>
            <?php foreach ($flow['wallet'] as $wVal): ?>
              <code><?= e((string)$wVal) ?></code>
            <?php endforeach; ?>
          <?php else: ?>
            <code><?= e((string)($flow['wallet'] ?? '')) ?></code>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section class="premium-strip premium-strip-wide">
  <div>
    <strong>Najkrótszy schemat</strong><br>
    Autor pisze → redakcja wycenia → czytelnik kupuje → autor dostaje 70% → serwis 30% → wszystko zostaje zapisane w portfelu i ledgerze.
  </div>
  <a class="read-more" href="/register">Dołącz <span>→</span></a>
</section>
