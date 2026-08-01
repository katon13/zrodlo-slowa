<?php
namespace App\Services;

use App\Core\Database;

final class CategoryService
{
    public function __construct(private readonly Database $db) {}
    public function all(): array { return $this->db->all('SELECT * FROM categories ORDER BY menu_order ASC, name ASC'); }

    public function allForAdmin(): array
    {
        $categories = $this->db->all('
            SELECT c.*, 
            (SELECT COUNT(*) FROM article_categories WHERE category_id = c.id) as articles_count
            FROM categories c 
            ORDER BY c.menu_order ASC, c.name ASC
        ');

        foreach ($categories as &$category) {
            $translations = $this->db->all('SELECT language, name, slug, description FROM category_translations WHERE category_id = :id', ['id' => $category['id']]);
            $category['translations'] = [];
            foreach ($translations as $t) {
                $category['translations'][$t['language']] = $t;
            }
        }

        return $categories;
    }

    public function allForMenu(): array
    {
        $lang = function_exists('public_language') ? public_language() : 'pl';
        
        $categories = $this->db->all('
            SELECT id, name, slug 
            FROM categories 
            WHERE is_active = 1 AND show_in_menu = 1 
            ORDER BY menu_order ASC, name ASC
        ');

        if ($lang === 'pl') {
            return $categories;
        }

        foreach ($categories as &$category) {
            $trans = $this->db->one('SELECT name, slug FROM category_translations WHERE category_id = :id AND language = :lang', [
                'id' => $category['id'],
                'lang' => $lang
            ]);
            if ($trans) {
                $category['name'] = $trans['name'];
                $category['slug'] = $trans['slug'];
            }
        }

        return $categories;
    }

    public function getMenuCategories(): array
    {
        return $this->allForMenu();
    }

    public function create(string $name): int
    {
        $name = trim($name);
        if ($name === '') throw new \InvalidArgumentException('Nazwa kategorii jest wymagana.');
        $slug = $this->generateSlug($name);
        $maxOrder = (int)$this->db->one('SELECT MAX(menu_order) as max_order FROM categories')['max_order'];
        
        return $this->db->insert('
            INSERT INTO categories(name, slug, type, show_in_menu, menu_order, is_active, created_at) 
            VALUES(:name, :slug, \'category\', 1, :order, 1, NOW())
        ', [
            'name' => $name, 
            'slug' => $slug,
            'order' => $maxOrder + 10
        ]);
    }

    public function update(int $id, array $data): void
    {
        if ($id <= 0 || !$this->db->one('SELECT id FROM categories WHERE id=:id LIMIT 1', ['id' => $id])) {
            throw new \RuntimeException('Nie znaleziono kategorii.');
        }

        $fields = [];
        $params = ['id' => $id];

        if (isset($data['name'])) {
            $data['name'] = trim((string)$data['name']);
            if ($data['name'] === '') {
                throw new \InvalidArgumentException('Nazwa kategorii jest wymagana.');
            }
            $fields[] = 'name = :name';
            $params['name'] = $data['name'];
            $fields[] = 'slug = :slug';
            $params['slug'] = $this->generateSlug($data['name']);
        }

        if (isset($data['show_in_menu'])) {
            $fields[] = 'show_in_menu = :show_in_menu';
            $params['show_in_menu'] = (int)$data['show_in_menu'];
        }

        if (isset($data['menu_order'])) {
            $fields[] = 'menu_order = :menu_order';
            $params['menu_order'] = (int)$data['menu_order'];
        }

        if (isset($data['is_active'])) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int)$data['is_active'];
        }

        if (!empty($fields)) {
            $sql = 'UPDATE categories SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $this->db->query($sql, $params);
        }

        if (isset($data['translations']) && is_array($data['translations'])) {
            $this->saveTranslations($id, $data['translations']);
        }
    }

    public function saveTranslations(int $categoryId, array $translations): void
    {
        foreach ($translations as $lang => $trans) {
            $lang = strtolower(trim((string)$lang));
            if (!preg_match('/^[a-z]{2}$/', $lang) || !is_array($trans)) {
                continue;
            }
            $name = trim((string)($trans['name'] ?? ''));
            if ($name === '') continue;
            
            $slug = trim((string)($trans['slug'] ?? '')) ?: $this->generateSlug($name);
            $description = trim((string)($trans['description'] ?? '')) ?: null;

            $exists = $this->db->one('SELECT id FROM category_translations WHERE category_id = :id AND language = :lang', [
                'id' => $categoryId,
                'lang' => $lang
            ]);

            if ($exists) {
                $this->db->query('
                    UPDATE category_translations 
                    SET name = :name, slug = :slug, description = :desc, updated_at = NOW() 
                    WHERE id = :tid
                ', [
                    'name' => $name,
                    'slug' => $slug,
                    'desc' => $description,
                    'tid' => $exists['id']
                ]);
            } else {
                $this->db->query('
                    INSERT INTO category_translations (category_id, language, name, slug, description, created_at, updated_at)
                    VALUES (:cid, :lang, :name, :slug, :desc, NOW(), NOW())
                ', [
                    'cid' => $categoryId,
                    'lang' => $lang,
                    'name' => $name,
                    'slug' => $slug,
                    'desc' => $description
                ]);
            }
        }
    }

    public function delete(int $id): bool
    {
        if ($id <= 0 || !$this->db->one('SELECT id FROM categories WHERE id=:id LIMIT 1', ['id' => $id])) {
            throw new \RuntimeException('Nie znaleziono kategorii.');
        }
        $count = (int)$this->db->one('SELECT COUNT(*) as cnt FROM article_categories WHERE category_id = :id', ['id' => $id])['cnt'];
        if ($count > 0) {
            return false;
        }

        $this->db->query('DELETE FROM categories WHERE id = :id', ['id' => $id]);
        return true;
    }

    private function generateSlug(string $name): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name) ?: $name), '-')) ?: 'kategoria';
    }
}
