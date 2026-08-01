<section class="admin-page-head">
  <p class="kicker">3DORS · DRZWI 1</p>
  <h1>Odblokowanie panelu administracyjnego</h1>
  <p>Sesja administratora została zablokowana po 15 minutach bezczynności. Zwykła część serwisu nadal działa.</p>
</section>

<section class="admin-panel-block" style="max-width:680px">
  <h2>Ponownie potwierdź tożsamość</h2>
  <form method="post" action="/admin/security/unlock" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="return_path" value="<?= e((string)($return_path ?? '/admin')) ?>">
    <label for="dors3-unlock-password">Aktualne hasło administratora</label>
    <input id="dors3-unlock-password" type="password" name="password" required autocomplete="current-password">
    <button class="btn btn-primary" type="submit">Odblokuj panel</button>
  </form>
</section>
