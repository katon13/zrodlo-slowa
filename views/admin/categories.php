<section class="admin-page-head">
  <p class="kicker">Redakcja</p>
  <h1>Kategorie</h1>
  <p>Zarządzaj strukturą tematyczną serwisu i menu głównym.</p>
</section>

<div id="ajax-notice-container">
  <?php if (!empty($flash_success)): ?><div class="notice success"><?= e($flash_success) ?></div><?php endif; ?>
  <?php if (!empty($flash_error)): ?><div class="notice error"><?= e($flash_error) ?></div><?php endif; ?>
</div>

<section class="admin-panel-block">
  <form id="add-category-form" class="zs-compact-form" method="post" action="/admin/categories">
    <?= csrf_field() ?>
    <div class="zs-form-inner">
      <label>Nowa kategoria</label>
      <input name="name" required placeholder="np. Kultura">
      <button class="btn-red" type="submit">Dodaj</button>
    </div>
  </form>
</section>

<div class="zs-admin-table-container">
  <table class="zs-admin-table" id="categories-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Nazwa</th>
        <th>Slug</th>
        <th class="text-center">W menu</th>
        <th class="text-center">Kolejność</th>
        <th class="text-center">Aktywna</th>
        <th class="text-center">Artykuły</th>
        <th class="text-right">Akcje</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($categories as $c): ?>
        <tr id="category-row-<?= (int)$c['id'] ?>">
          <td><small class="text-muted">#<?= (int)$c['id'] ?></small></td>
          <td>
            <form id="edit-form-<?= (int)$c['id'] ?>" action="/admin/categories/update" method="post" class="zs-inline-edit-form ajax-form">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <div class="zs-category-edit-main">
                <input type="text" name="name" value="<?= e($c['name']) ?>" class="zs-table-input" placeholder="Nazwa PL">
                <button type="button" class="btn-toggle-translations" onclick="toggleTranslations(<?= (int)$c['id'] ?>)" title="Tłumaczenia">🌐</button>
              </div>
              
              <div id="translations-<?= (int)$c['id'] ?>" class="zs-category-translations-box" style="display: none;">
                <?php foreach (['en', 'de', 'fr', 'it', 'es'] as $lang): ?>
                  <div class="zs-trans-row">
                    <span class="zs-trans-label"><?= strtoupper($lang) ?>:</span>
                    <input type="text" name="translations[<?= $lang ?>][name]" value="<?= e($c['translations'][$lang]['name'] ?? '') ?>" class="zs-table-input-sm" placeholder="Nazwa <?= strtoupper($lang) ?>">
                  </div>
                <?php endforeach; ?>
              </div>
            </form>
          </td>
          <td><small class="text-muted"><?= e($c['slug']) ?></small></td>
          <td class="text-center">
            <input type="checkbox" name="show_in_menu" form="edit-form-<?= (int)$c['id'] ?>" <?= $c['show_in_menu'] ? 'checked' : '' ?>>
            <br>
            <span class="zs-badge <?= $c['show_in_menu'] ? 'badge-green' : 'badge-gray' ?>">
              <?= $c['show_in_menu'] ? 'W MENU' : 'UKRYTA' ?>
            </span>
          </td>
          <td class="text-center">
            <input type="number" name="menu_order" form="edit-form-<?= (int)$c['id'] ?>" value="<?= (int)$c['menu_order'] ?>" class="zs-table-input-num">
          </td>
          <td class="text-center">
            <input type="checkbox" name="is_active" form="edit-form-<?= (int)$c['id'] ?>" <?= $c['is_active'] ? 'checked' : '' ?>>
            <br>
            <span class="zs-badge <?= $c['is_active'] ? 'badge-red' : 'badge-gray' ?>">
              <?= $c['is_active'] ? 'AKTYWNA' : 'NIEAKTYWNA' ?>
            </span>
          </td>
          <td class="text-center">
            <strong><?= (int)($c['articles_count'] ?? 0) ?></strong>
          </td>
          <td class="text-right">
            <div class="zs-action-group">
              <button type="submit" form="edit-form-<?= (int)$c['id'] ?>" class="btn-outline btn-small">Zapisz</button>
              
              <form action="/admin/categories/delete" method="post" class="ajax-form delete-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn-outline btn-small delete-btn <?= (int)($c['articles_count'] ?? 0) > 0 ? 'btn-disabled' : '' ?>" <?= (int)($c['articles_count'] ?? 0) > 0 ? 'disabled title="Kategoria zawiera artykuły"' : '' ?>>Usuń</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const noticeContainer = document.getElementById('ajax-notice-container');

    function showNotice(message, type = 'success') {
        const div = document.createElement('div');
        div.className = `notice ${type}`;
        div.textContent = message;
        noticeContainer.innerHTML = '';
        noticeContainer.appendChild(div);
        
        if (type === 'success') {
            setTimeout(() => { div.style.opacity = '0'; setTimeout(() => div.remove(), 500); }, 3000);
        }
    }

    // Obsługa wszystkich formularzy AJAX
    document.querySelectorAll('.ajax-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Specjalna obsługa usuwania
            if (this.classList.contains('delete-form')) {
                const btn = this.querySelector('.delete-btn');
                if (!btn.classList.contains('confirm-delete')) {
                    btn.dataset.originalText = btn.textContent;
                    btn.textContent = 'Na pewno?';
                    btn.classList.add('confirm-delete', 'btn-danger');
                    
                    setTimeout(() => {
                        btn.textContent = btn.dataset.originalText || 'Usuń';
                        btn.classList.remove('confirm-delete', 'btn-danger');
                    }, 3000);
                    return;
                }
            }

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            fetch(this.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                showNotice(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    if (this.classList.contains('delete-form')) {
                        const row = this.closest('tr');
                        row.style.opacity = '0.3';
                        row.style.pointerEvents = 'none';
                    } else if (this.id === 'add-category-form') {
                        location.reload(); // Najprościej przy dodawaniu nowej
                    } else {
                        // Opcjonalnie: aktualizuj badge wizualnie (choć przeładowanie checkboxów z formy wystarczy)
                    }
                }
            })
            .catch(err => showNotice('Błąd połączenia: ' + err, 'error'))
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    });

    // Przeładowanie przy dodawaniu nowej kategorii (nie przez AJAX, bo to zmienia strukturę tabeli)
    document.getElementById('add-category-form').addEventListener('submit', function(e) {
        // Jeśli chcemy pełny AJAX tutaj, musielibyśmy wstawiać wiersze do DOM. 
        // Na razie zostawmy dodawanie tradycyjne lub reload po AJAX.
    });
});

function toggleTranslations(id) {
    const box = document.getElementById('translations-' + id);
    if (box.style.display === 'none') {
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}
</script>

<style>
.zs-category-edit-main { display: flex; gap: 5px; align-items: center; }
.btn-toggle-translations { background: none; border: 1px solid #ddd; border-radius: 3px; cursor: pointer; padding: 2px 5px; font-size: 1rem; transition: background 0.2s; }
.btn-toggle-translations:hover { background: #f0f0f0; }
.zs-category-translations-box { margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #eee; border-radius: 4px; }
.zs-trans-row { display: flex; align-items: center; gap: 5px; margin-bottom: 5px; }
.zs-trans-row:last-child { margin-bottom: 0; }
.zs-trans-label { font-size: 0.7rem; font-weight: bold; width: 30px; color: #666; }
.zs-table-input-sm { flex: 1; padding: 3px 6px; border: 1px solid #ddd; border-radius: 3px; font-size: 0.8rem; }
.zs-admin-table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 0.9rem; }
.zs-admin-table th, .zs-admin-table td { padding: 12px; border-bottom: 1px solid #eee; text-align: left; }
.zs-admin-table th { font-weight: 600; text-transform: uppercase; letter-spacing: 1px; font-size: 0.75rem; color: #666; }
.zs-table-input { width: 100%; padding: 4px 8px; border: 1px solid #ddd; border-radius: 3px; font-family: inherit; }
.zs-table-input-num { width: 60px; padding: 4px; border: 1px solid #ddd; border-radius: 3px; text-align: center; }
.zs-badge { font-size: 0.65rem; font-weight: 700; padding: 2px 6px; border-radius: 3px; display: inline-block; margin-top: 4px; }
.badge-green { background: #e6f4ea; color: #1e7e34; }
.badge-red { background: #fce8e8; color: #c62828; }
.badge-gray { background: #f5f5f5; color: #757575; }
.zs-action-group { display: flex; gap: 5px; justify-content: flex-end; align-items: center; }
.btn-small { padding: 4px 8px; font-size: 0.75rem; }
.btn-disabled { opacity: 0.5; cursor: not-allowed; }
.text-center { text-align: center !important; }
.text-right { text-align: right !important; }
.text-muted { color: #999; }
.btn-danger { background-color: #c62828 !important; color: white !important; border-color: #c62828 !important; }
#ajax-notice-container { margin-bottom: 20px; transition: all 0.5s ease; }
.notice { padding: 12px 16px; border-radius: 4px; margin-bottom: 10px; font-weight: 500; }
.notice.success { background: #e6f4ea; color: #1e7e34; border-left: 4px solid #1e7e34; }
.notice.error { background: #fce8e8; color: #c62828; border-left: 4px solid #c62828; }
</style>
