<?php

namespace App\Model;

use App\Helper\Locale;
use App\Service\GoogleTranslateService;
use InvalidArgumentException;
use PDO;

class TransportationModel
{
    private const MANAGED_LANGUAGES = ['fr', 'en', 'es'];
    private const FIXED_CATEGORIES = [
        'en' => [
            ['key' => 'airport_to_hotel', 'title' => 'From Airport to Hotel'],
            ['key' => 'hotel_to_airport', 'title' => 'From Hotel to Airport'],
            ['key' => 'local', 'title' => 'Local'],
        ],
        'fr' => [
            ['key' => 'airport_to_hotel', 'title' => 'De l’aéroport à l’hôtel'],
            ['key' => 'hotel_to_airport', 'title' => 'De l’hôtel à l’aéroport'],
            ['key' => 'local', 'title' => 'Local'],
        ],
        'es' => [
            ['key' => 'airport_to_hotel', 'title' => 'Del aeropuerto al hotel'],
            ['key' => 'hotel_to_airport', 'title' => 'Del hotel al aeropuerto'],
            ['key' => 'local', 'title' => 'Local'],
        ],
    ];

    public function __construct(private PDO $pdo, private ?GoogleTranslateService $googleTranslateService = null)
    {
        $this->ensureTables();
        $this->ensureFixedCategories();
    }

    public function findAllByLanguage(string $language): array
    {
        $language = $this->normalizeLanguage($language);
        $categories = [];

        foreach ($this->fetchCategoryRowsByLanguage($language) as $categoryRow) {
            $categories[] = [
                'title' => (string) $categoryRow['title'],
                'flag' => null,
                'items' => array_map(
                    fn (array $itemRow): array => $this->mapItemRowToViewData($itemRow),
                    $this->fetchItemRowsByCategoryId((int) $categoryRow['id'])
                ),
            ];
        }

        return $categories;
    }

    public function findItemByIndexes(string $language, int $categoryIndex, int $itemIndex): ?array
    {
        $categoryRow = $this->fetchCategoryRowByIndex($this->normalizeLanguage($language), $categoryIndex);

        if ($categoryRow === null) {
            return null;
        }

        $itemRow = $this->fetchItemRowByIndex((int) $categoryRow['id'], $itemIndex);

        if ($itemRow === null) {
            return null;
        }

        return $this->fetchGroupedItem((string) $itemRow['group_key'], $this->normalizeLanguage($language));
    }

    public function findCategoryByIndex(string $language, int $categoryIndex): ?array
    {
        $categoryRow = $this->fetchCategoryRowByIndex($this->normalizeLanguage($language), $categoryIndex);

        if ($categoryRow === null) {
            return null;
        }

        return [
            'title' => (string) ($categoryRow['title'] ?? ''),
            'flag' => '',
        ];
    }

    public function createItem(string $language, array $data): void
    {
        $language = $this->normalizeLanguage($language);
        $groupKey = bin2hex(random_bytes(16));
        $this->pdo->beginTransaction();

        try {
            $this->saveGroupedItem($language, $data, $groupKey);

            foreach (self::MANAGED_LANGUAGES as $targetLanguage) {
                if ($targetLanguage === $language) {
                    continue;
                }

                $this->saveGroupedItem(
                    $targetLanguage,
                    $this->buildTranslatedData($data, $language, $targetLanguage),
                    $groupKey
                );
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function updateItem(string $language, int $categoryIndex, int $itemIndex, array $data): void
    {
        $language = $this->normalizeLanguage($language);
        $groupKey = $this->resolveGroupKeyFromIndexes($language, $categoryIndex, $itemIndex);

        $this->pdo->beginTransaction();

        try {
            foreach (self::MANAGED_LANGUAGES as $managedLanguage) {
                $this->deleteGroupedItemInLanguage($groupKey, $managedLanguage);
            }

            $this->saveGroupedItem($language, $data, $groupKey);

            foreach (self::MANAGED_LANGUAGES as $targetLanguage) {
                if ($targetLanguage === $language) {
                    continue;
                }

                $this->saveGroupedItem(
                    $targetLanguage,
                    $this->buildTranslatedData($data, $language, $targetLanguage),
                    $groupKey
                );
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function deleteItemByIndexes(string $language, int $categoryIndex, int $itemIndex): void
    {
        $language = $this->normalizeLanguage($language);
        $groupKey = $this->resolveGroupKeyFromIndexes($language, $categoryIndex, $itemIndex);

        $this->pdo->beginTransaction();

        try {
            foreach (self::MANAGED_LANGUAGES as $managedLanguage) {
                $this->deleteGroupedItemInLanguage($groupKey, $managedLanguage);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function deleteCategoryByIndex(string $language, int $categoryIndex): void
    {
        throw new InvalidArgumentException($this->localizedMessage('fixed_categories', $this->normalizeLanguage($language)));
    }

    public function updateCategoryByIndex(string $language, int $categoryIndex, array $data): void
    {
        throw new InvalidArgumentException($this->localizedMessage('fixed_categories', $this->normalizeLanguage($language)));
    }

    private function saveGroupedItem(string $language, array $data, string $groupKey): void
    {
        $payload = $this->normalizeItemPayload($data, $language);
        $categoryIndexes = $this->normalizeCategoryIndexes($this->resolveNewCategoryChoice($data, $language), $language);

        foreach ($categoryIndexes as $sortOrder => $categoryIndex) {
            $categoryRow = $this->fetchCategoryRowByIndex($language, $categoryIndex);

            if ($categoryRow === null) {
                throw new InvalidArgumentException($this->localizedMessage('select_category', $language));
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO transportation_items (category_id, group_key, name, address, note, website_url, website_label, sort_order)
                 VALUES (:category_id, :group_key, :name, :address, :note, :website_url, :website_label, :sort_order)'
            );
            $stmt->execute([
                'category_id' => (int) $categoryRow['id'],
                'group_key' => $groupKey,
                'name' => $payload['name'],
                'address' => '',
                'note' => $payload['note'],
                'website_url' => $payload['website_url'],
                'website_label' => $payload['website_label'],
                'sort_order' => $this->countItemsInCategory((int) $categoryRow['id']) + $sortOrder,
            ]);
        }
    }

    private function normalizeItemPayload(array $data, string $language): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $websiteUrl = $this->normalizeExternalUrl((string) ($data['url'] ?? ''));

        if ($name === '' || $websiteUrl === '') {
            throw new InvalidArgumentException($this->localizedMessage('required_fields', $language));
        }

        return [
            'name' => $name,
            'note' => trim((string) ($data['description'] ?? '')),
            'website_url' => $websiteUrl,
            'website_label' => $this->labelFromUrl($websiteUrl),
        ];
    }

    private function normalizeCategoryIndexes(array $data, string $language): array
    {
        $choices = $data['category_choices'] ?? [];

        if (!is_array($choices)) {
            $choices = [];
        }

        $indexes = [];

        foreach ($choices as $choice) {
            if (!is_scalar($choice) || !is_numeric((string) $choice)) {
                continue;
            }

            $index = (int) $choice;

            if ($index < 0 || $this->fetchCategoryRowByIndex($language, $index) === null) {
                continue;
            }

            if (!in_array($index, $indexes, true)) {
                $indexes[] = $index;
            }
        }

        if ($indexes === []) {
            throw new InvalidArgumentException($this->localizedMessage('select_category', $language));
        }

        sort($indexes);

        return $indexes;
    }

    private function resolveNewCategoryChoice(array $data, string $language): array
    {
        $newCategoryTitle = trim((string) ($data['new_category_title'] ?? ''));

        if ($newCategoryTitle === '') {
            return $data;
        }

        $categoryIndexes = is_array($data['category_choices'] ?? null) ? $data['category_choices'] : [];
        $resolvedIndex = $this->findCategoryIndexByTitle($language, $newCategoryTitle);

        if ($resolvedIndex === null) {
            $resolvedIndex = $this->createCategorySet($language, $newCategoryTitle);
        }

        $categoryIndexes[] = (string) $resolvedIndex;
        $data['category_choices'] = $categoryIndexes;

        return $data;
    }

    private function buildTranslatedData(array $data, string $sourceLanguage, string $targetLanguage): array
    {
        return [
            'name' => $this->translateText((string) ($data['name'] ?? ''), $sourceLanguage, $targetLanguage),
            'url' => (string) ($data['url'] ?? ''),
            'description' => $this->translateText((string) ($data['description'] ?? ''), $sourceLanguage, $targetLanguage),
            'category_choices' => $data['category_choices'] ?? [],
        ];
    }

    private function createCategorySet(string $sourceLanguage, string $sourceTitle): int
    {
        $sourceLanguage = $this->normalizeLanguage($sourceLanguage);
        $sourceTitle = trim($sourceTitle);

        if ($sourceTitle === '') {
            throw new InvalidArgumentException($this->localizedMessage('required_fields', $sourceLanguage));
        }

        $sortOrder = count($this->fetchCategoryRowsByLanguage($sourceLanguage));

        foreach (self::MANAGED_LANGUAGES as $targetLanguage) {
            $translatedTitle = $targetLanguage === $sourceLanguage
                ? $sourceTitle
                : $this->translateText($sourceTitle, $sourceLanguage, $targetLanguage);

            $stmt = $this->pdo->prepare(
                'INSERT INTO transportation_categories (language_code, title, icon_code, sort_order)
                 VALUES (:language_code, :title, NULL, :sort_order)'
            );
            $stmt->execute([
                'language_code' => $targetLanguage,
                'title' => $translatedTitle !== '' ? $translatedTitle : $sourceTitle,
                'sort_order' => $sortOrder,
            ]);
        }

        return $sortOrder;
    }

    private function findCategoryIndexByTitle(string $language, string $title): ?int
    {
        $normalizedTitle = $this->normalizeComparableTitle($title);

        foreach ($this->fetchCategoryRowsByLanguage($language) as $index => $categoryRow) {
            if ($this->normalizeComparableTitle((string) ($categoryRow['title'] ?? '')) === $normalizedTitle) {
                return $index;
            }
        }

        return null;
    }

    private function resolveGroupKeyFromIndexes(string $language, int $categoryIndex, int $itemIndex): string
    {
        $categoryRow = $this->fetchCategoryRowByIndex($language, $categoryIndex);

        if ($categoryRow === null) {
            throw new InvalidArgumentException($this->localizedMessage('category_not_found', $language));
        }

        $itemRow = $this->fetchItemRowByIndex((int) $categoryRow['id'], $itemIndex);

        if ($itemRow === null) {
            throw new InvalidArgumentException($this->localizedMessage('item_not_found', $language));
        }

        return (string) $itemRow['group_key'];
    }

    private function fetchGroupedItem(string $groupKey, string $language): ?array
    {
        $rows = $this->fetchItemRowsByGroupKey($groupKey, $language);

        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $categoryIndexes = [];
        $categories = $this->fetchCategoryRowsByLanguage($language);

        foreach ($rows as $row) {
            foreach ($categories as $index => $categoryRow) {
                if ((int) $categoryRow['id'] === (int) $row['category_id']) {
                    $categoryIndexes[] = $index;
                    break;
                }
            }
        }

        return [
            'name' => (string) ($first['name'] ?? ''),
            'url' => (string) ($first['website_url'] ?? ''),
            'url_label' => (string) ($first['website_label'] ?? ''),
            'description' => (string) ($first['note'] ?? ''),
            'category_indexes' => array_values(array_unique($categoryIndexes)),
        ];
    }

    private function fetchItemRowsByGroupKey(string $groupKey, string $language): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT items.*
             FROM transportation_items items
             INNER JOIN transportation_categories categories ON categories.id = items.category_id
             WHERE items.group_key = :group_key
               AND categories.language_code = :language_code
             ORDER BY categories.sort_order ASC, items.id ASC'
        );
        $stmt->execute([
            'group_key' => $groupKey,
            'language_code' => $language,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deleteGroupedItemInLanguage(string $groupKey, string $language): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE items
             FROM transportation_items items
             INNER JOIN transportation_categories categories ON categories.id = items.category_id
             WHERE items.group_key = :group_key
               AND categories.language_code = :language_code'
        );
        $stmt->execute([
            'group_key' => $groupKey,
            'language_code' => $language,
        ]);
    }

    private function fetchCategoryRowsByLanguage(string $language): array
    {
        $language = $this->normalizeLanguage($language);

        $stmt = $this->pdo->prepare(
            'SELECT id, language_code, title, icon_code, sort_order
             FROM transportation_categories
             WHERE language_code = :language_code
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([
            'language_code' => $language,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchCategoryRowByIndex(string $language, int $categoryIndex): ?array
    {
        $categories = $this->fetchCategoryRowsByLanguage($language);
        return $categories[$categoryIndex] ?? null;
    }

    private function fetchItemRowsByCategoryId(int $categoryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM transportation_items WHERE category_id = :category_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([
            'category_id' => $categoryId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchItemRowByIndex(int $categoryId, int $itemIndex): ?array
    {
        $items = $this->fetchItemRowsByCategoryId($categoryId);
        return $items[$itemIndex] ?? null;
    }

    private function countItemsInCategory(int $categoryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transportation_items WHERE category_id = :category_id');
        $stmt->execute([
            'category_id' => $categoryId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function mapItemRowToViewData(array $itemRow): array
    {
        $url = $this->normalizeExternalUrl((string) ($itemRow['website_url'] ?? ''));

        return [
            'name' => $itemRow['name'] ?? null,
            'area' => null,
            'note' => ($itemRow['note'] ?? '') !== '' ? (string) $itemRow['note'] : null,
            'url' => $url !== '' ? $url : null,
            'url_label' => $itemRow['website_label'] ?? null,
        ];
    }

    private function normalizeLanguage(string $language): string
    {
        return Locale::normalize($language);
    }

    private function labelFromUrl(string $url): string
    {
        $url = $this->normalizeExternalUrl($url);

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        $host = $parts['host'] ?? '';
        $path = isset($parts['path']) ? trim(urldecode((string) $parts['path']), '/') : '';

        if ($host === '' && $path === '') {
            return $url;
        }

        if ($path === '') {
            return $host;
        }

        return $host !== '' ? $host . '/' . $path : $path;
    }

    private function normalizeExternalUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return 'https://' . ltrim($url, '/');
    }

    private function normalizeComparableTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            return '';
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($title, 'UTF-8')
            : strtolower($title);
    }

    private function translateText(string $text, string $sourceLanguage, string $targetLanguage): string
    {
        $text = trim($text);

        if ($text === '' || $sourceLanguage === $targetLanguage) {
            return $text;
        }

        if ($this->googleTranslateService === null || !$this->googleTranslateService->isConfigured()) {
            return $text;
        }

        try {
            return $this->googleTranslateService->translate($text, $sourceLanguage, $targetLanguage);
        } catch (\RuntimeException) {
            return $text;
        }
    }

    private function localizedMessage(string $key, string $language): string
    {
        $messages = [
            'required_fields' => [
                'en' => 'Please fill in the required fields.',
                'fr' => 'Veuillez remplir les champs obligatoires.',
                'es' => 'Completa los campos obligatorios.',
            ],
            'select_category' => [
                'en' => 'Please choose at least one category.',
                'fr' => 'Veuillez choisir au moins une catégorie.',
                'es' => 'Selecciona al menos una categoría.',
            ],
            'item_not_found' => [
                'en' => 'Item not found.',
                'fr' => 'Élément introuvable.',
                'es' => 'Elemento no encontrado.',
            ],
            'category_not_found' => [
                'en' => 'Category not found.',
                'fr' => 'Catégorie introuvable.',
                'es' => 'Categoría no encontrada.',
            ],
            'fixed_categories' => [
                'en' => 'Transportation categories are fixed and cannot be edited here.',
                'fr' => 'Les catégories de transport sont fixes et ne peuvent pas être modifiées ici.',
                'es' => 'Las categorías de transporte son fijas y no se pueden editar aquí.',
            ],
        ];

        $language = $this->normalizeLanguage($language);

        return $messages[$key][$language] ?? $messages[$key]['en'] ?? $key;
    }

    private function ensureTables(): void
    {
        if (function_exists('ensureSiteContentTables')) {
            \ensureSiteContentTables($this->pdo);
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS transportation_categories (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    language_code CHAR(2) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    icon_code VARCHAR(50) NULL,
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_transportation_categories_language_sort (language_code, sort_order)
                )
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS transportation_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    category_id INT NOT NULL,
                    group_key VARCHAR(64) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    address VARCHAR(255) NULL,
                    note TEXT NULL,
                    website_url TEXT NULL,
                    website_label VARCHAR(255) NULL,
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_transportation_items_category_sort (category_id, sort_order),
                    INDEX idx_transportation_items_group_key (group_key),
                    CONSTRAINT fk_transportation_items_category
                        FOREIGN KEY (category_id) REFERENCES transportation_categories(id)
                        ON DELETE CASCADE
                )
            ");
        }

        $this->ensureGroupKeyColumn();
    }

    private function ensureGroupKeyColumn(): void
    {
        if ($this->columnExists('transportation_items', 'group_key')) {
            return;
        }

        $this->pdo->exec("
            ALTER TABLE transportation_items
            ADD COLUMN group_key VARCHAR(64) NOT NULL DEFAULT ''
            AFTER category_id
        ");

        $stmt = $this->pdo->query('SELECT id FROM transportation_items');

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $update = $this->pdo->prepare('UPDATE transportation_items SET group_key = :group_key WHERE id = :id');
            $update->execute([
                'group_key' => 'legacy-' . (int) $row['id'],
                'id' => (int) $row['id'],
            ]);
        }
    }

    private function ensureFixedCategories(): void
    {
        foreach (self::MANAGED_LANGUAGES as $language) {
            $existing = $this->fetchCategoryRowsByLanguage($language);
            $defaults = self::FIXED_CATEGORIES[$language] ?? [];

            foreach ($defaults as $sortOrder => $category) {
                $existingRow = $existing[$sortOrder] ?? null;

                if ($existingRow === null) {
                    $stmt = $this->pdo->prepare(
                        'INSERT INTO transportation_categories (language_code, title, icon_code, sort_order)
                         VALUES (:language_code, :title, NULL, :sort_order)'
                    );
                    $stmt->execute([
                        'language_code' => $language,
                        'title' => $category['title'],
                        'sort_order' => $sortOrder,
                    ]);
                    continue;
                }

                $stmt = $this->pdo->prepare(
                    'UPDATE transportation_categories
                     SET title = :title, icon_code = NULL, sort_order = :sort_order
                     WHERE id = :id'
                );
                $stmt->execute([
                    'title' => $category['title'],
                    'sort_order' => $sortOrder,
                    'id' => (int) $existingRow['id'],
                ]);
            }

        }
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
