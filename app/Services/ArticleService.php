<?php
namespace App\Services;

use App\Core\Database;
use App\Security\ArticleSubmissionPolicy;

final class ArticleService
{
    public function __construct(private readonly Database $db) {}

    public function published(int $limit=20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->all('SELECT a.id,a.author_id,a.title,a.slug,a.`lead`,a.status,a.published_at,a.updated_at,a.source_language,a.access_mode,a.price_minor,a.currency,a.is_premium,a.is_unique,a.article_label,u.display_name AS author_name, u.avatar_path AS author_avatar_path, u.avatar_updated_at AS author_avatar_updated_at, (SELECT path FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image, (SELECT image_position FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image_position FROM articles a JOIN users u ON u.id=a.author_id WHERE a.status=\'published\' AND a.response_to_article_id IS NULL ORDER BY a.published_at DESC, a.id DESC LIMIT ' . $limit);
    }
    public function publishedByCategory(string $categorySlug, int $limit=20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->all('SELECT a.id,a.author_id,a.title,a.slug,a.`lead`,a.status,a.published_at,a.updated_at,a.source_language,a.access_mode,a.price_minor,a.currency,a.is_premium,a.is_unique,a.article_label, u.display_name AS author_name, u.avatar_path AS author_avatar_path, u.avatar_updated_at AS author_avatar_updated_at, (SELECT path FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image, (SELECT image_position FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image_position
            FROM articles a 
            JOIN users u ON u.id=a.author_id 
            JOIN article_categories ac ON ac.article_id=a.id
            JOIN categories c ON c.id=ac.category_id
            WHERE a.status=\'published\' AND a.response_to_article_id IS NULL AND c.slug=:slug
            ORDER BY a.published_at DESC, a.id DESC LIMIT ' . $limit, ['slug' => $categorySlug]);
    }

    public function findPublished(int $id): ?array
    {
        return $this->db->one('SELECT a.*, u.display_name AS author_name, u.avatar_path AS author_avatar_path, u.avatar_updated_at AS author_avatar_updated_at FROM articles a JOIN users u ON u.id=a.author_id WHERE a.id=:id AND a.status=\'published\'', ['id'=>$id]);
    }

    public function findAnyWithAuthor(int $id): ?array
    {
        return $this->db->one('SELECT a.*, u.display_name AS author_name, u.avatar_path AS author_avatar_path, u.avatar_updated_at AS author_avatar_updated_at FROM articles a JOIN users u ON u.id=a.author_id WHERE a.id=:id LIMIT 1', ['id'=>$id]);
    }

    /** @return list<array<string,mixed>> */
    public function publishedResponses(int $articleId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->all(
            'SELECT a.id,a.author_id,a.title,a.slug,a.`lead`,a.published_at,a.updated_at,a.source_language,
                    a.response_reward_qualified,a.response_reward_points,
                    u.display_name AS author_name,u.avatar_path AS author_avatar_path,
                    u.avatar_updated_at AS author_avatar_updated_at
             FROM articles a
             JOIN users u ON u.id=a.author_id
             WHERE a.response_to_article_id=:article AND a.status=\'published\'
             ORDER BY a.published_at ASC,a.id ASC LIMIT ' . $limit,
            ['article' => $articleId]
        );
    }

    public function findForAuthor(int $id, int $authorId): ?array
    {
        return $this->db->one('SELECT a.*, (SELECT MAX(created_at) FROM article_events WHERE article_id=a.id AND event=\'proofread_saved\') AS proofread_at FROM articles a WHERE a.id=:id AND a.author_id=:author', ['id'=>$id, 'author'=>$authorId]);
    }

    public function forAuthor(int $authorId, int $limit = 30, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        return $this->db->all('SELECT a.*, (SELECT MAX(created_at) FROM article_events WHERE article_id=a.id AND event=\'proofread_saved\') AS proofread_at, (SELECT path FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image, (SELECT image_position FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image_position FROM articles a WHERE a.author_id=:id ORDER BY a.updated_at DESC, a.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, ['id'=>$authorId]);
    }

    public function getMedia(int $articleId, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->all('SELECT id,article_id,path,mime,title,image_position,created_at FROM media WHERE article_id=:id ORDER BY id DESC LIMIT ' . $limit, ['id'=>$articleId]);
    }

    public function allForAdmin(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $where = '';
        $params = [];
        if ($status !== null && in_array($status, ['draft','submitted','review','approved','published','rejected','archived'], true)) {
            $where = ' WHERE a.status=:status';
            $params['status'] = $status;
        }
        return $this->db->all('SELECT a.*, u.display_name AS author_name, 
            (SELECT MAX(created_at) FROM article_events WHERE article_id=a.id AND event=\'proofread_saved\') AS proofread_at,
            (SELECT path FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image, 
            (SELECT image_position FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image_position 
            FROM articles a JOIN users u ON u.id=a.author_id' . $where . ' ORDER BY a.updated_at DESC, a.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
    }


    public function allForAdminStatuses(array $statuses, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $allowed = ['draft','submitted','review','approved','published','rejected','archived'];
        $statuses = array_values(array_intersect($allowed, array_map('strval', $statuses)));
        if ($statuses === []) {
            $statuses = ['submitted'];
        }
        $placeholders = [];
        $params = [];
        foreach ($statuses as $i => $status) {
            $key = 's' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }
        return $this->db->all('SELECT a.*, u.display_name AS author_name,
            u.article_submit_blocked_until AS author_submit_blocked_until,
            u.article_submit_block_reason AS author_submit_block_reason,
            u.article_submit_blocked_by AS author_submit_blocked_by,
            (SELECT MAX(created_at) FROM article_events WHERE article_id=a.id AND event=\'proofread_saved\') AS proofread_at,
            (SELECT path FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image,
            (SELECT image_position FROM media WHERE article_id=a.id ORDER BY id DESC LIMIT 1) as main_image_position
            FROM articles a JOIN users u ON u.id=a.author_id
            WHERE a.status IN (' . implode(',', $placeholders) . ')
            ORDER BY CASE a.status
                         WHEN \'submitted\' THEN 1 WHEN \'review\' THEN 2
                         WHEN \'approved\' THEN 3 WHEN \'published\' THEN 4
                         WHEN \'archived\' THEN 5 WHEN \'rejected\' THEN 6
                         WHEN \'draft\' THEN 7 ELSE 99
                     END,
                     a.updated_at DESC, a.id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
    }

    public function allForProofreading(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        return $this->db->all('SELECT a.*, u.display_name AS author_name,
            (SELECT MAX(created_at) FROM article_events WHERE article_id=a.id AND event=\'proofread_saved\') AS proofread_at
            FROM articles a
            JOIN users u ON u.id=a.author_id
            WHERE a.status IN (\'submitted\', \'review\')
            ORDER BY a.updated_at DESC, a.id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset);
    }

    public function findForProofreading(int $id): ?array
    {
        return $this->db->one('SELECT a.*, u.display_name AS author_name,
            (SELECT MAX(created_at) FROM article_events WHERE article_id=a.id AND event=\'proofread_saved\') AS proofread_at
            FROM articles a
            JOIN users u ON u.id=a.author_id
            WHERE a.id=:id AND a.status IN (\'submitted\', \'review\')', ['id'=>$id]);
    }

    public function updateProofreading(int $articleId, int $proofreaderId, array $data): void
    {
        $lead = trim((string)($data['lead'] ?? ''));
        $body = trim((string)($data['body'] ?? ''));

        if ($body === '') {
            throw new \InvalidArgumentException('Treść jest wymagana.');
        }

        $this->db->transaction(function(Database $db) use ($articleId, $proofreaderId, $lead, $body): void {
            $article = $db->one(
                'SELECT * FROM articles
                 WHERE id=:id AND status IN (\'submitted\',\'review\')
                 LIMIT 1 FOR UPDATE',
                ['id' => $articleId]
            );
            if (!$article) {
                throw new \RuntimeException('Tekst nie jest dostępny do korekty.');
            }

            $db->query('UPDATE articles SET `lead`=:lead, body=:body, updated_at=NOW() WHERE id=:id', [
                'id'=>$articleId,
                'lead'=>$lead !== '' ? $lead : null,
                'body'=>$body,
            ]);

            $this->invalidateTranslations($db, $articleId, $proofreaderId);
            $this->snapshot($articleId, (string)$article['title'], $lead !== '' ? $lead : null, $body);
            $this->event($articleId, $proofreaderId, 'proofread_saved', ['label'=>'KOREKTA']);
        });
    }

    public function findForAdmin(int $id): ?array
    {
        return $this->db->one('SELECT a.*, u.display_name AS author_name, (SELECT MAX(created_at) FROM article_events WHERE article_id=a.id AND event=\'proofread_saved\') AS proofread_at FROM articles a JOIN users u ON u.id=a.author_id WHERE a.id=:id', ['id'=>$id]);
    }

    public function updateEditorial(int $articleId, int $adminId, array $data): int
    {
        return $this->db->transaction(function (Database $db) use ($articleId, $adminId, $data): int {
            $article = $db->one('SELECT * FROM articles WHERE id=:id LIMIT 1 FOR UPDATE', ['id' => $articleId]);
            if (!$article) {
                throw new \RuntimeException('Nie znaleziono tekstu.');
            }

            $title = trim((string)($data['title'] ?? $article['title']));
            $lead = trim((string)($data['lead'] ?? $article['lead']));
            $body = trim((string)($data['body'] ?? $article['body']));
            if ($title === '' || $body === '') {
                throw new \InvalidArgumentException('Tytuł i treść są wymagane.');
            }

            $sourceLanguage = in_array($data['source_language'] ?? '', ['pl', 'en', 'de', 'fr', 'it', 'es'], true)
                ? (string)$data['source_language']
                : (string)($article['source_language'] ?? 'pl');
            $displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : (int)$article['display_order'];
            $editorialWeight = isset($data['editorial_weight']) ? (int)$data['editorial_weight'] : (int)$article['editorial_weight'];
            $isFeatured = isset($data['is_featured']) ? (int)$data['is_featured'] : (int)$article['is_featured'];
            $translationInstructions = array_key_exists('translation_instructions', $data)
                ? (string)$data['translation_instructions']
                : null;

            $revisionData = [
                'title' => $title,
                'lead' => $lead !== '' ? $lead : null,
                'body' => $body,
            ];

            if ((string)$article['status'] === 'published') {
                $revisionId = $this->updatePublishedRevision(
                    $db,
                    $article,
                    (int)$article['author_id'],
                    $revisionData,
                    (string)($article['access_mode'] ?? 'free'),
                    (int)($article['price_minor'] ?? 0),
                    $sourceLanguage,
                    $adminId
                );
                $db->query(
                    'UPDATE articles
                     SET display_order=:display_order,
                         editorial_weight=:editorial_weight,
                         is_featured=:is_featured,
                         updated_at=NOW()
                     WHERE id=:id',
                    [
                        'display_order' => $displayOrder,
                        'editorial_weight' => $editorialWeight,
                        'is_featured' => $isFeatured,
                        'id' => $revisionId,
                    ]
                );
                return $revisionId;
            }

            if (!in_array((string)$article['status'], ['draft', 'submitted', 'review', 'approved', 'rejected'], true)) {
                throw new \RuntimeException('Tekstu w tym statusie nie można edytować.');
            }

            $newStatus = in_array((string)$article['status'], ['submitted', 'review', 'approved'], true)
                ? 'review'
                : 'draft';
            $db->query(
                'UPDATE articles
                 SET title=:title, `lead`=:lead, body=:body, source_language=:source,
                     display_order=:display_order, editorial_weight=:editorial_weight,
                     is_featured=:is_featured, status=:status, updated_at=NOW()
                 WHERE id=:id',
                [
                    'id' => $articleId,
                    'title' => $title,
                    'lead' => $lead !== '' ? $lead : null,
                    'body' => $body,
                    'source' => $sourceLanguage,
                    'display_order' => $displayOrder,
                    'editorial_weight' => $editorialWeight,
                    'is_featured' => $isFeatured,
                    'status' => $newStatus,
                ]
            );

            $this->invalidateTranslations($db, $articleId, $adminId, $translationInstructions);
            $this->snapshot($articleId, $title, $lead !== '' ? $lead : null, $body);
            $this->event($articleId, $adminId, 'editorial_updated', ['status' => $newStatus]);
            return $articleId;
        });
    }

    public function createDraft(int $authorId, array $data): int
    {
        ArticleSubmissionPolicy::validate($data);
        $rawAccessMode = (string)($data['access_mode'] ?? 'free');
        $accessMode = in_array($rawAccessMode, ['free', 'paid'], true) ? $rawAccessMode : 'free';
        $priceMinor = max(0, (int)($data['price_minor'] ?? 0));
        $sourceLanguage = in_array($data['source_language'] ?? 'pl', ['pl', 'en', 'de', 'fr', 'it', 'es'], true) ? $data['source_language'] : 'pl';

        return $this->db->transaction(function(Database $db) use ($authorId, $data, $accessMode, $priceMinor, $sourceLanguage) {
            $id = $db->insert('INSERT INTO articles(author_id,title,slug,`lead`,body,status,access_mode,price_minor,source_language,created_at,updated_at) VALUES(:author,:title,:slug,:lead,:body,\'draft\',:access,:price,:source,NOW(),NOW())', [
                'author'=>$authorId, 
                'title'=>$data['title'], 
                'slug'=>$this->uniqueSlug($data['title']), 
                'lead'=>$data['lead'] ?? null, 
                'body'=>$data['body'],
                'access'=>$accessMode,
                'price'=>$priceMinor,
                'source'=>$sourceLanguage
            ]);
            $this->snapshot($id, $data['title'], $data['lead'] ?? null, $data['body']);
            $this->event($id, $authorId, 'created', ['status'=>'draft', 'access_mode'=>$accessMode, 'price_minor'=>$priceMinor]);
            return $id;
        });
    }

    public function createResponseDraft(int $authorId, int $sourceArticleId, array $data): int
    {
        ArticleSubmissionPolicy::validate($data);
        $sourceLanguage = in_array($data['source_language'] ?? 'pl', ['pl', 'en', 'de', 'fr', 'it', 'es'], true)
            ? (string)$data['source_language']
            : 'pl';

        return $this->db->transaction(function (Database $db) use ($authorId, $sourceArticleId, $data, $sourceLanguage): int {
            $source = $db->one(
                'SELECT id,status,revision_of_article_id FROM articles WHERE id=:id FOR SHARE',
                ['id' => $sourceArticleId]
            );
            if ($source === null || (string)$source['status'] !== 'published' || !empty($source['revision_of_article_id'])) {
                throw new \RuntimeException('Odpowiedź publikacją można utworzyć tylko do opublikowanego tekstu głównego.');
            }
            if (!$this->hasAccess($authorId, $sourceArticleId)) {
                throw new \RuntimeException('response_source_access_required');
            }

            $id = $db->insert(
                'INSERT INTO articles(
                    author_id,title,slug,`lead`,body,status,access_mode,price_minor,pricing_status,
                    source_language,response_to_article_id,created_at,updated_at
                 ) VALUES(
                    :author,:title,:slug,:lead,:body,\'draft\',\'free\',0,\'free\',
                    :source_language,:response_to,NOW(),NOW()
                 )',
                [
                    'author' => $authorId,
                    'title' => $data['title'],
                    'slug' => $this->uniqueSlug((string)$data['title']),
                    'lead' => $data['lead'] ?? null,
                    'body' => $data['body'],
                    'source_language' => $sourceLanguage,
                    'response_to' => $sourceArticleId,
                ]
            );
            $this->snapshot($id, (string)$data['title'], $data['lead'] ?? null, (string)$data['body']);
            $this->event($id, $authorId, 'response_created', [
                'status' => 'draft',
                'response_to_article_id' => $sourceArticleId,
                'monetization' => 'tt_only_after_editorial_publication',
            ]);
            return $id;
        });
    }

    public function updateDraft(int $articleId, int $authorId, array $data): int
    {
        $article = $this->findForAuthor($articleId, $authorId);
        if (!$article) {
            throw new \RuntimeException('Nie znaleziono tekstu.');
        }
        if (!in_array($article['status'], ['draft', 'rejected', 'published'], true)) {
            throw new \RuntimeException('Można edytować tylko szkic, tekst odrzucony albo opublikowany.');
        }
        ArticleSubmissionPolicy::validate($data);

        $accessMode = array_key_exists('access_mode', $data) && in_array($data['access_mode'], ['free', 'paid'], true)
            ? $data['access_mode']
            : (string)($article['access_mode'] ?? 'free');
        $priceMinor = array_key_exists('price_minor', $data)
            ? max(0, (int)$data['price_minor'])
            : (int)($article['price_minor'] ?? 0);
        $sourceLanguage = array_key_exists('source_language', $data) && in_array($data['source_language'], ['pl', 'en', 'de', 'fr', 'it', 'es'], true)
            ? $data['source_language']
            : (string)($article['source_language'] ?? 'pl');
        if (!empty($article['response_to_article_id'])) {
            $accessMode = 'free';
            $priceMinor = 0;
        }

        return $this->db->transaction(function (Database $db) use (
            $articleId,
            $authorId,
            $data,
            $accessMode,
            $priceMinor,
            $sourceLanguage
        ): int {
            $locked = $db->one(
                'SELECT * FROM articles WHERE id=:id AND author_id=:author FOR UPDATE',
                ['id' => $articleId, 'author' => $authorId]
            );
            if ($locked === null) {
                throw new \RuntimeException('Nie znaleziono tekstu.');
            }
            if ($locked['status'] === 'published') {
                return $this->updatePublishedRevision(
                    $db,
                    $locked,
                    $authorId,
                    $data,
                    $accessMode,
                    $priceMinor,
                    $sourceLanguage
                );
            }
            if (!in_array($locked['status'], ['draft', 'rejected'], true)) {
                throw new \RuntimeException('Tekst został już przekazany do redakcji.');
            }
            $db->query(
                'UPDATE articles
                 SET title=:title,`lead`=:lead,body=:body,access_mode=:access,
                     price_minor=:price,source_language=:source,updated_at=NOW()
                 WHERE id=:id',
                [
                    'id' => $articleId,
                    'title' => $data['title'],
                    'lead' => $data['lead'] ?? null,
                    'body' => $data['body'],
                    'access' => $accessMode,
                    'price' => $priceMinor,
                    'source' => $sourceLanguage,
                ]
            );
            $this->snapshot($articleId, $data['title'], $data['lead'] ?? null, $data['body']);
            $this->event($articleId, $authorId, 'updated', [
                'status' => $locked['status'],
                'access_mode' => $accessMode,
                'price_minor' => $priceMinor,
            ]);
            return $articleId;
        });
    }

    public function submit(int $articleId, int $authorId): void
    {
        $this->db->transaction(function(Database $db) use ($articleId, $authorId): void {
            $article = $db->one(
                'SELECT * FROM articles
                 WHERE id=:id AND author_id=:author
                 LIMIT 1 FOR UPDATE',
                ['id' => $articleId, 'author' => $authorId]
            );
            if (!$article) {
                throw new \RuntimeException('Nie znaleziono tekstu.');
            }
            if (!in_array((string)$article['status'], ['draft', 'rejected'], true)) {
                throw new \RuntimeException('Tekst nie może zostać wysłany z aktualnego statusu.');
            }
            $this->holdResponseSubmissionDeposit($db, $article, $authorId);
            $db->query('UPDATE articles SET status=\'submitted\', updated_at=NOW() WHERE id=:id', ['id'=>$articleId]);
            $this->event($articleId, $authorId, 'submitted', []);
        });
    }

    public function sendToProofreading(int $id, ?int $adminId = null): void
    {
        $this->db->transaction(function(Database $db) use ($id, $adminId): void {
            $article = $db->one(
                'SELECT * FROM articles WHERE id=:id LIMIT 1 FOR UPDATE',
                ['id' => $id]
            );
            if (!$article) {
                throw new \RuntimeException('Nie znaleziono tekstu.');
            }
            if (!in_array((string)$article['status'], ['draft', 'submitted', 'rejected'], true)) {
                throw new \RuntimeException('Tekstu w tym statusie nie można przekazać do korekty.');
            }
            if ((string)$article['status'] !== 'submitted') {
                $this->holdResponseSubmissionDeposit($db, $article, $adminId);
                $db->query('UPDATE articles SET status=\'submitted\', updated_at=NOW() WHERE id=:id', ['id'=>$id]);
            }
            $this->event($id, $adminId, 'sent_to_proofreading', []);
        });
    }

    public function setStatus(int $id, string $status, ?int $adminId = null): int
    {
        if (!in_array($status, ['draft', 'submitted', 'review', 'approved', 'published', 'rejected', 'archived'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy status.');
        }

        return $this->db->transaction(function (Database $db) use ($id, $status, $adminId): int {
            $article = $db->one('SELECT * FROM articles WHERE id=:id FOR UPDATE', ['id' => $id]);
            if ($article === null) {
                throw new \RuntimeException('Nie znaleziono tekstu.');
            }
            $this->assertStatusTransition((string)$article['status'], $status);
            if ($status === 'published' && !empty($article['revision_of_article_id'])) {
                return $this->publishRevision($db, $article, $adminId);
            }

            if ($status === 'submitted' && (string)$article['status'] !== 'submitted') {
                $this->holdResponseSubmissionDeposit($db, $article, $adminId);
            }
            if (in_array($status, ['rejected', 'archived'], true) && (string)$article['status'] !== $status) {
                $this->forfeitResponseSubmissionDeposit($db, $article, $adminId);
            }
            if ($status === 'published' && (string)$article['status'] !== 'published') {
                $this->refundResponseSubmissionDeposit($db, $article, $adminId);
            }

            $published = $status === 'published' ? ', published_at=COALESCE(published_at,NOW())' : '';
            $db->query(
                "UPDATE articles SET status=:status,updated_at=NOW() {$published} WHERE id=:id",
                ['status' => $status, 'id' => $id]
            );
            if (
                $status === 'published'
                && (string)$article['status'] !== 'published'
                && !empty($article['response_to_article_id'])
                && empty($article['revision_of_article_id'])
            ) {
                $this->snapshotResponsePublicationReward($db, $article, $adminId);
            }
            $this->event($id, $adminId, 'status_' . $status, []);
            return $id;
        });
    }

    public function assertAuthorEditable(int $articleId, int $authorId): array
    {
        $article = $this->findForAuthor($articleId, $authorId);
        if ($article === null) {
            throw new \RuntimeException('Nie znaleziono tekstu.');
        }
        if (!in_array($article['status'], ['draft', 'rejected'], true)) {
            throw new \RuntimeException('Media można zmieniać dopiero w szkicu lub odrzuconej rewizji.');
        }
        return $article;
    }

    public function recordRead(int $articleId, ?int $userId, string $ipHash): void
    {
        $this->db->query('INSERT INTO article_reads(article_id,user_id,ip_hash,created_at) VALUES(:article,:user,:ip,NOW())', ['article'=>$articleId,'user'=>$userId,'ip'=>$ipHash]);
    }

    public function hasAccess(?int $userId, int $articleId): bool
    {
        $grant = $this->getAccessGrant($userId, $articleId);
        return $grant !== null;
    }

    public function getAccessGrant(?int $userId, int $articleId): ?array
    {
        $article = $this->db->one('SELECT author_id, access_mode FROM articles WHERE id=:id', ['id'=>$articleId]);
        if (!$article) return null;
        
        if ($article['access_mode'] === 'free') {
            return ['status' => 'active', 'source' => 'free', 'expires_at' => null];
        }
        if (!$userId) return null;
        if ((int)$userId === (int)$article['author_id']) {
            return ['status' => 'active', 'source' => 'author', 'expires_at' => null];
        }
        
        // Sprawdź rolę admina
        $roles = $this->db->all('SELECT role FROM user_roles WHERE user_id=:id', ['id'=>$userId]);
        foreach ($roles as $r) {
            if ($r['role'] === 'admin') {
                return ['status' => 'active', 'source' => 'admin', 'expires_at' => null];
            }
        }

        // Sprawdź czy ma wykupiony aktywny dostęp i nie wygasł
        $grant = $this->db->one('SELECT * FROM article_access_grants 
            WHERE user_id=:u AND article_id=:a AND status=\'active\'
            AND expires_at IS NOT NULL AND expires_at > NOW() 
            LIMIT 1', [
            'u' => $userId,
            'a' => $articleId
        ]);
        
        return $grant;
    }

    public function grantAccess(int $userId, int $articleId, ?int $paymentId = null, string $source = 'payment', ?int $hours = null, ?string $grantedAt = null): bool
    {
        $grantedAt = $grantedAt ?: date('Y-m-d H:i:s');
        $expiresAt = null;
        if ($hours !== null) {
            $expiresAt = date('Y-m-d H:i:s', strtotime($grantedAt) + ($hours * 3600));
        }

        $sql = $this->db->isPostgres()
            ? 'INSERT INTO article_access_grants(
                   user_id,article_id,payment_id,source,status,granted_at,expires_at
               ) VALUES(:u,:a,:p,:s,\'active\',:g,:e)
               ON CONFLICT (user_id,article_id) DO UPDATE SET
                   status=\'active\',revoked_at=NULL,expires_at=EXCLUDED.expires_at,
                   source=EXCLUDED.source,
                   payment_id=COALESCE(EXCLUDED.payment_id,article_access_grants.payment_id),
                   granted_at=EXCLUDED.granted_at'
            : 'INSERT INTO article_access_grants(
                   user_id,article_id,payment_id,source,status,granted_at,expires_at
               ) VALUES(:u,:a,:p,:s,\'active\',:g,:e)
               ON DUPLICATE KEY UPDATE
                   status=\'active\',revoked_at=NULL,expires_at=VALUES(expires_at),
                   source=VALUES(source),payment_id=COALESCE(VALUES(payment_id),payment_id),
                   granted_at=VALUES(granted_at)';
        $this->db->query($sql, [
            'u' => $userId,
            'a' => $articleId,
            'p' => $paymentId,
            's' => $source,
            'e' => $expiresAt,
            'g' => $grantedAt
        ]);
        return true;
    }

    public function revokeAccess(int $userId, int $articleId): bool
    {
        $this->db->query('UPDATE article_access_grants SET status=\'revoked\', revoked_at=NOW() WHERE user_id=:u AND article_id=:a', [
            'u' => $userId,
            'a' => $articleId
        ]);
        return true;
    }

    public function revokeByPayment(int $paymentId): void
    {
        $grants = $this->db->all('SELECT user_id, article_id FROM article_access_grants WHERE payment_id=:p', ['p' => $paymentId]);
        foreach ($grants as $g) {
            $this->revokeAccess((int)$g['user_id'], (int)$g['article_id']);
        }
    }

    private function snapshot(int $articleId, string $title, ?string $lead, string $body): void
    {
        $this->db->query('INSERT INTO article_versions(article_id,title,`lead`,body,created_at) VALUES(:id,:title,:lead,:body,NOW())', ['id'=>$articleId,'title'=>$title,'lead'=>$lead,'body'=>$body]);
    }

    private function invalidateTranslations(Database $db, int $articleId, ?int $changedBy, ?string $instructions = null): void
    {
        (new ArticleTranslationService($db))->invalidateForArticle($articleId, $changedBy, $instructions);
    }

    private function updatePublishedRevision(
        Database $db,
        array $published,
        int $authorId,
        array $data,
        string $accessMode,
        int $priceMinor,
        string $sourceLanguage,
        ?int $actorId = null
    ): int {
        $revision = $db->one(
            'SELECT * FROM articles
             WHERE revision_of_article_id=:source
               AND status IN (\'draft\',\'submitted\',\'review\',\'approved\',\'rejected\')
             ORDER BY id DESC LIMIT 1 FOR UPDATE',
            ['source' => (int)$published['id']]
        );
        if ($revision !== null && !in_array($revision['status'], ['draft', 'rejected'], true)) {
            throw new \RuntimeException('Rewizja tego tekstu jest już w toku redakcyjnym.');
        }

        if ($revision === null) {
            $revisionId = $db->insert(
                'INSERT INTO articles(
                    author_id,title,slug,`lead`,body,status,access_mode,price_minor,currency,
                    is_premium,is_unique,pricing_status,author_share_percent,platform_share_percent,
                    editor_valuation_note,article_label,source_language,display_order,editorial_weight,
                    is_featured,revision_of_article_id,response_to_article_id,created_at,updated_at
                 ) VALUES(
                    :author,:title,:slug,:lead,:body,\'draft\',:access,:price,:currency,
                    :premium,:unique_flag,:pricing,:author_share,:platform_share,
                    :valuation_note,:label,:source,:display_order,:editorial_weight,
                    :featured,:revision_of,:response_to,NOW(),NOW()
                 )',
                [
                    'author' => $authorId,
                    'title' => $data['title'],
                    'slug' => $this->uniqueSlug((string)$data['title'] . '-rewizja'),
                    'lead' => $data['lead'] ?? null,
                    'body' => $data['body'],
                    'access' => $accessMode,
                    'price' => $priceMinor,
                    'currency' => $published['currency'],
                    'premium' => $published['is_premium'],
                    'unique_flag' => $published['is_unique'],
                    'pricing' => $published['pricing_status'],
                    'author_share' => $published['author_share_percent'],
                    'platform_share' => $published['platform_share_percent'],
                    'valuation_note' => $published['editor_valuation_note'],
                    'label' => $published['article_label'],
                    'source' => $sourceLanguage,
                    'display_order' => $published['display_order'],
                    'editorial_weight' => $published['editorial_weight'],
                    'featured' => $published['is_featured'],
                    'revision_of' => (int)$published['id'],
                    'response_to' => !empty($published['response_to_article_id']) ? (int)$published['response_to_article_id'] : null,
                ]
            );
            $db->query(
                ($db->isPostgres()
                    ? 'INSERT INTO article_categories(article_id,category_id)
                       SELECT :revision,category_id
                       FROM article_categories WHERE article_id=:source
                       ON CONFLICT DO NOTHING'
                    : 'INSERT IGNORE INTO article_categories(article_id,category_id)
                       SELECT :revision,category_id
                       FROM article_categories WHERE article_id=:source'),
                ['revision' => $revisionId, 'source' => (int)$published['id']]
            );
            $this->event((int)$published['id'], $actorId ?? $authorId, 'revision_created', ['revision_id' => $revisionId]);
        } else {
            $revisionId = (int)$revision['id'];
            $db->query(
                'UPDATE articles
                 SET title=:title,`lead`=:lead,body=:body,access_mode=:access,price_minor=:price,
                     source_language=:source,status=\'draft\',updated_at=NOW()
                 WHERE id=:id',
                [
                    'title' => $data['title'],
                    'lead' => $data['lead'] ?? null,
                    'body' => $data['body'],
                    'access' => $accessMode,
                    'price' => $priceMinor,
                    'source' => $sourceLanguage,
                    'id' => $revisionId,
                ]
            );
        }

        $this->snapshot($revisionId, (string)$data['title'], $data['lead'] ?? null, (string)$data['body']);
        $this->event($revisionId, $actorId ?? $authorId, 'updated', [
            'status' => 'draft',
            'revision_of_article_id' => (int)$published['id'],
            'access_mode' => $accessMode,
            'price_minor' => $priceMinor,
        ]);
        return $revisionId;
    }

    private function publishRevision(Database $db, array $revision, ?int $adminId): int
    {
        $sourceId = (int)$revision['revision_of_article_id'];
        $source = $db->one('SELECT * FROM articles WHERE id=:id FOR UPDATE', ['id' => $sourceId]);
        if ($source === null || $source['status'] !== 'published') {
            throw new \RuntimeException('Oryginalny opublikowany tekst nie jest dostępny.');
        }

        $db->query(
            'UPDATE articles
             SET title=:title,`lead`=:lead,body=:body,access_mode=:access,price_minor=:price,
                 currency=:currency,is_premium=:premium,is_unique=:unique_flag,pricing_status=:pricing,
                 author_share_percent=:author_share,platform_share_percent=:platform_share,
                 editor_valuation_note=:valuation_note,article_label=:label,source_language=:source_language,
                 display_order=:display_order,editorial_weight=:editorial_weight,is_featured=:featured,
                 updated_at=NOW()
             WHERE id=:id',
            [
                'title' => $revision['title'],
                'lead' => $revision['lead'],
                'body' => $revision['body'],
                'access' => $revision['access_mode'],
                'price' => $revision['price_minor'],
                'currency' => $revision['currency'],
                'premium' => $revision['is_premium'],
                'unique_flag' => $revision['is_unique'],
                'pricing' => $revision['pricing_status'],
                'author_share' => $revision['author_share_percent'],
                'platform_share' => $revision['platform_share_percent'],
                'valuation_note' => $revision['editor_valuation_note'],
                'label' => $revision['article_label'],
                'source_language' => $revision['source_language'],
                'display_order' => $revision['display_order'],
                'editorial_weight' => $revision['editorial_weight'],
                'featured' => $revision['is_featured'],
                'id' => $sourceId,
            ]
        );
        $db->query('DELETE FROM article_categories WHERE article_id=:id', ['id' => $sourceId]);
        $db->query(
            ($db->isPostgres()
                ? 'INSERT INTO article_categories(article_id,category_id)
                   SELECT :source,category_id
                   FROM article_categories WHERE article_id=:revision
                   ON CONFLICT DO NOTHING'
                : 'INSERT IGNORE INTO article_categories(article_id,category_id)
                   SELECT :source,category_id
                   FROM article_categories WHERE article_id=:revision'),
            ['source' => $sourceId, 'revision' => (int)$revision['id']]
        );
        $db->query(
            'UPDATE media SET article_id=:source WHERE article_id=:revision',
            ['source' => $sourceId, 'revision' => (int)$revision['id']]
        );
        $this->invalidateTranslations($db, $sourceId, $adminId);
        $db->query(
            'UPDATE articles SET status=\'archived\',updated_at=NOW() WHERE id=:id',
            ['id' => (int)$revision['id']]
        );
        $this->snapshot($sourceId, (string)$revision['title'], $revision['lead'], (string)$revision['body']);
        $this->event($sourceId, $adminId, 'revision_published', ['revision_id' => (int)$revision['id']]);
        $this->event((int)$revision['id'], $adminId, 'status_archived', ['published_to_article_id' => $sourceId]);
        return $sourceId;
    }

    /** @param array<string,mixed> $article */
    private function snapshotResponsePublicationReward(Database $db, array $article, ?int $adminId): void
    {
        $rule = $db->one(
            'SELECT activity_type,points_amount,amount_minor,is_active
             FROM activity_reward_rules
             WHERE activity_type=\'response_publication_bonus\'
             FOR SHARE'
        );
        $points = $rule !== null ? max(0, min(1_000_000, (int)($rule['points_amount'] ?? 0))) : 0;
        $qualified = $rule !== null
            && (int)($rule['is_active'] ?? 0) === 1
            && $points > 0
            && (int)($rule['amount_minor'] ?? 0) === 0;
        $snapshotPoints = $qualified ? $points : 0;
        $articleId = (int)$article['id'];
        $authorId = (int)$article['author_id'];

        $job = (new EarningsQueueService(new DurableJobQueue($db)))->queueTalentAward(
            $authorId,
            'response_publication_bonus',
            'response_publication',
            $articleId,
            [
                'response_rule_qualified' => $qualified,
                'response_points_amount' => $snapshotPoints,
                'response_source_article_id' => (int)$article['response_to_article_id'],
                'response_published_by' => $adminId,
                'response_published_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
        $jobPublicId = trim((string)($job['public_id'] ?? ''));
        if ($jobPublicId === '') {
            throw new \RuntimeException('Nie udało się zapisać zadania nagrody za opublikowaną polemikę.');
        }

        $db->query(
            'UPDATE articles
             SET response_reward_qualified=:qualified,response_reward_points=:points,
                 response_reward_job_public_id=:job,response_reward_queued_at=NOW(),updated_at=NOW()
             WHERE id=:id',
            [
                'qualified' => $qualified ? 'true' : 'false',
                'points' => $snapshotPoints,
                'job' => $jobPublicId,
                'id' => $articleId,
            ]
        );
        $this->event($articleId, $adminId, 'response_reward_snapshotted', [
            'qualified' => $qualified,
            'points_amount' => $snapshotPoints,
            'job_public_id' => $jobPublicId,
            'rule_activity_type' => 'response_publication_bonus',
        ]);
    }

    /** @param array<string,mixed> $article */
    private function holdResponseSubmissionDeposit(Database $db, array $article, ?int $actorId): void
    {
        if (empty($article['response_to_article_id']) || $article['response_deposit_status'] !== null) {
            return;
        }

        $articleId = (int)$article['id'];
        if (!empty($article['revision_of_article_id'])) {
            $db->query(
                'UPDATE articles
                 SET response_deposit_points=0,response_deposit_status=\'not_required\',
                     response_deposit_snapshotted_at=NOW(),updated_at=NOW()
                 WHERE id=:id AND response_deposit_status IS NULL',
                ['id' => $articleId]
            );
            $this->event($articleId, $actorId, 'response_deposit_not_required', ['reason' => 'revision']);
            return;
        }

        $rule = $db->one(
            'SELECT submission_deposit_points
             FROM activity_reward_rules
             WHERE activity_type=\'response_publication_bonus\'
             FOR SHARE'
        );
        $points = $rule !== null
            ? max(0, min(1_000_000, (int)($rule['submission_deposit_points'] ?? 0)))
            : 0;

        if ($points === 0) {
            $db->query(
                'UPDATE articles
                 SET response_deposit_points=0,response_deposit_status=\'not_required\',
                     response_deposit_snapshotted_at=NOW(),updated_at=NOW()
                 WHERE id=:id AND response_deposit_status IS NULL',
                ['id' => $articleId]
            );
            $this->event($articleId, $actorId, 'response_deposit_not_required', ['reason' => 'admin_value_zero']);
            return;
        }

        $authorId = (int)$article['author_id'];
        $ledger = new LedgerService($db, new FinancialService($db));
        $wallet = $ledger->walletForUser($authorId, true);
        if ((int)($wallet['is_locked'] ?? 0) === 1) {
            throw new \RuntimeException('Portfel użytkownika jest zablokowany.');
        }
        if ((int)($wallet['points_balance'] ?? 0) < $points) {
            throw new ResponseSubmissionDepositException($points);
        }

        $transactionId = $ledger->post(
            $authorId,
            'response_submission_deposit_hold',
            0,
            -$points,
            'Kaucja za wysłanie opinii lub polemiki do redakcji',
            [
                'account_type' => 'points',
                'source_module' => 'response_publication',
                'ref_type' => 'response_publication',
                'ref_id' => $articleId,
                'idempotency_key' => 'response-submission-deposit:' . $articleId,
                'meta' => ['response_article_id' => $articleId, 'deposit_points' => $points],
            ]
        );
        $db->query(
            'UPDATE articles
             SET response_deposit_points=:points,response_deposit_status=\'held\',
                 response_deposit_snapshotted_at=NOW(),response_deposit_charged_at=NOW(),
                 response_deposit_debit_transaction_id=:transaction,updated_at=NOW()
             WHERE id=:id AND response_deposit_status IS NULL',
            ['points' => $points, 'transaction' => $transactionId, 'id' => $articleId]
        );
        $this->event($articleId, $actorId, 'response_deposit_held', [
            'points_amount' => $points,
            'wallet_transaction_id' => $transactionId,
        ]);
    }

    /** @param array<string,mixed> $article */
    private function forfeitResponseSubmissionDeposit(Database $db, array $article, ?int $actorId): void
    {
        if (empty($article['response_to_article_id']) || (string)($article['response_deposit_status'] ?? '') !== 'held') {
            return;
        }
        $points = max(0, (int)($article['response_deposit_points'] ?? 0));
        if ($points === 0) {
            return;
        }
        $articleId = (int)$article['id'];
        $platformId = $this->platformUserId($db);
        $transactionId = (new LedgerService($db, new FinancialService($db)))->post(
            $platformId,
            'response_submission_deposit_forfeit',
            0,
            $points,
            'Przepadek kaucji za nieopublikowaną opinię lub polemikę',
            [
                'account_type' => 'points',
                'source_module' => 'response_publication',
                'ref_type' => 'response_publication',
                'ref_id' => $articleId,
                'counterparty_user_id' => (int)$article['author_id'],
                'idempotency_key' => 'response-submission-deposit-forfeit:' . $articleId,
                'meta' => ['response_article_id' => $articleId, 'deposit_points' => $points],
            ]
        );
        $db->query(
            'UPDATE articles
             SET response_deposit_status=\'forfeited\',response_deposit_settled_at=NOW(),
                 response_deposit_forfeit_transaction_id=:transaction,updated_at=NOW()
             WHERE id=:id AND response_deposit_status=\'held\'',
            ['transaction' => $transactionId, 'id' => $articleId]
        );
        $this->event($articleId, $actorId, 'response_deposit_forfeited', [
            'points_amount' => $points,
            'wallet_transaction_id' => $transactionId,
        ]);
    }

    /** @param array<string,mixed> $article */
    private function refundResponseSubmissionDeposit(Database $db, array $article, ?int $actorId): void
    {
        if (empty($article['response_to_article_id'])) {
            return;
        }
        $status = (string)($article['response_deposit_status'] ?? '');
        if (!in_array($status, ['held', 'forfeited'], true)) {
            return;
        }
        $points = max(0, (int)($article['response_deposit_points'] ?? 0));
        if ($points === 0) {
            return;
        }
        $articleId = (int)$article['id'];
        $ledger = new LedgerService($db, new FinancialService($db));
        $reversalTransactionId = null;
        if ($status === 'forfeited') {
            $reversalTransactionId = $ledger->post(
                $this->platformUserId($db),
                'response_submission_deposit_forfeit_reversal',
                0,
                -$points,
                'Cofnięcie przepadku kaucji po publikacji poprawionej polemiki',
                [
                    'account_type' => 'points',
                    'source_module' => 'response_publication',
                    'ref_type' => 'response_publication',
                    'ref_id' => $articleId,
                    'counterparty_user_id' => (int)$article['author_id'],
                    'idempotency_key' => 'response-submission-deposit-forfeit-reversal:' . $articleId,
                    'meta' => ['response_article_id' => $articleId, 'deposit_points' => $points],
                ]
            );
        }
        $refundTransactionId = $ledger->post(
            (int)$article['author_id'],
            'response_submission_deposit_refund',
            0,
            $points,
            'Zwrot kaucji po publikacji opinii lub polemiki',
            [
                'account_type' => 'points',
                'source_module' => 'response_publication',
                'ref_type' => 'response_publication',
                'ref_id' => $articleId,
                'idempotency_key' => 'response-submission-deposit-refund:' . $articleId,
                'meta' => ['response_article_id' => $articleId, 'deposit_points' => $points],
            ]
        );
        $db->query(
            'UPDATE articles
             SET response_deposit_status=\'refunded\',response_deposit_settled_at=NOW(),
                 response_deposit_reversal_transaction_id=:reversal,
                 response_deposit_refund_transaction_id=:refund,updated_at=NOW()
             WHERE id=:id AND response_deposit_status IN (\'held\',\'forfeited\')',
            ['reversal' => $reversalTransactionId, 'refund' => $refundTransactionId, 'id' => $articleId]
        );
        $this->event($articleId, $actorId, 'response_deposit_refunded', [
            'points_amount' => $points,
            'wallet_transaction_id' => $refundTransactionId,
            'forfeit_reversal_transaction_id' => $reversalTransactionId,
        ]);
    }

    private function platformUserId(Database $db): int
    {
        $platform = $db->one('SELECT id FROM users WHERE email=\'platform@zrodlo-slowa.local\' LIMIT 1');
        if ($platform !== null) {
            return (int)$platform['id'];
        }
        $db->query(
            'INSERT INTO users(
                email,phone,password_hash,display_name,status,can_write,talent_enabled,
                wallet_enabled,payout_enabled,permissions_updated_at,created_at,updated_at
             ) VALUES(
                \'platform@zrodlo-slowa.local\',NULL,:hash,\'Platforma ŹRÓDŁO SŁOWA\',
                \'active\',0,0,1,0,NOW(),NOW(),NOW()
             ) ON CONFLICT (email) DO NOTHING',
            ['hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]
        );
        $platform = $db->one('SELECT id FROM users WHERE email=\'platform@zrodlo-slowa.local\' LIMIT 1');
        if ($platform === null) {
            throw new \RuntimeException('Nie udało się utworzyć konta rozliczeniowego serwisu.');
        }
        return (int)$platform['id'];
    }

    private function assertStatusTransition(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        $allowed = [
            'draft' => ['submitted', 'rejected', 'archived'],
            'submitted' => ['review', 'approved', 'rejected', 'draft'],
            'review' => ['submitted', 'approved', 'rejected'],
            'approved' => ['published', 'review', 'rejected', 'archived'],
            'published' => ['archived'],
            'rejected' => ['draft', 'submitted', 'archived'],
            'archived' => ['draft'],
        ];
        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new \RuntimeException("Niedozwolona zmiana statusu: {$from} → {$to}.");
        }
    }

    private function event(int $articleId, ?int $userId, string $event, array $payload): void
    {
        $this->db->query('INSERT INTO article_events(article_id,user_id,event,payload_json,created_at) VALUES(:article,:user,:event,:payload,NOW())', [
            'article'=>$articleId, 'user'=>$userId, 'event'=>$event, 'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);
    }

    private function uniqueSlug(string $title): string
    {
        $base = $this->slug($title);
        $slug = $base;
        $n = 2;
        while ($this->db->one('SELECT id FROM articles WHERE slug=:slug LIMIT 1', ['slug'=>$slug])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    private function slug(string $title): string
    {
        $slug = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$title) ?: $title;
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $slug));
        return trim($slug, '-') ?: 'tekst';
    }
}
