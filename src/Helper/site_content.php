<?php

use App\Helper\Locale;

function siteContentFilePath(): string
{
    return dirname(__DIR__, 2) . '/data/site_content.php';
}

function siteContentEmptyState(): array
{
    $empty = [];

    foreach (['restaurants', 'excursions', 'playa_guide'] as $section) {
        $empty[$section] = [];

        foreach (Locale::all() as $language) {
            $empty[$section][$language] = [];
        }
    }

    return $empty;
}

function siteContentTableMap(): array
{
    return [
        'restaurants' => [
            'categories_table' => 'restaurant_categories',
            'items_table' => 'restaurants',
            'category_title_column' => 'title',
            'category_icon_column' => 'icon_code',
            'item_column_map' => [
                'name' => 'name',
                'area' => 'address',
                'price' => 'price',
                'reference' => 'reference_label',
                'url' => 'website_url',
                'url_label' => 'website_label',
            ],
        ],
        'excursions' => [
            'categories_table' => 'excursion_categories',
            'items_table' => 'excursions',
            'category_title_column' => 'title',
            'category_icon_column' => 'icon_code',
            'item_column_map' => [
                'name' => 'name',
                'area' => 'address',
                'note' => 'note',
                'url' => 'website_url',
                'url_label' => 'website_label',
                'video_url' => 'video_url',
            ],
        ],
        'playa_guide' => [
            'categories_table' => 'todo_categories',
            'items_table' => 'todo_items',
            'category_title_column' => 'title',
            'category_icon_column' => 'icon_code',
            'item_column_map' => [
                'name' => 'name',
                'area' => 'address',
                'note' => 'note',
                'url' => 'website_url',
                'url_label' => 'website_label',
                'video_url' => 'video_url',
            ],
        ],
    ];
}

function setSiteContentPdo(PDO $pdo): void
{
    $GLOBALS['site_content_pdo'] = $pdo;
    ensureSiteContentTables($pdo);
    migrateLegacySiteContentFileToDatabase($pdo);
    seedMissingSiteContentLanguage($pdo, 'es', 'en');
}

function siteContentPdo(): ?PDO
{
    return $GLOBALS['site_content_pdo'] ?? null;
}

function ensureSiteContentTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS restaurant_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            language_code CHAR(2) NOT NULL,
            title VARCHAR(255) NOT NULL,
            icon_code VARCHAR(50) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_restaurant_categories_language_sort (language_code, sort_order)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS restaurants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            address VARCHAR(255) NULL,
            price VARCHAR(20) NULL,
            reference_label VARCHAR(255) NULL,
            website_url TEXT NULL,
            website_label VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_restaurants_category_sort (category_id, sort_order),
            CONSTRAINT fk_restaurants_category
                FOREIGN KEY (category_id) REFERENCES restaurant_categories(id)
                ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS excursion_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            language_code CHAR(2) NOT NULL,
            title VARCHAR(255) NOT NULL,
            icon_code VARCHAR(50) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_excursion_categories_language_sort (language_code, sort_order)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS excursions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            address VARCHAR(255) NULL,
            note TEXT NULL,
            website_url TEXT NULL,
            website_label VARCHAR(255) NULL,
            video_url TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_excursions_category_sort (category_id, sort_order),
            CONSTRAINT fk_excursions_category
                FOREIGN KEY (category_id) REFERENCES excursion_categories(id)
                ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS todo_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            language_code CHAR(2) NOT NULL,
            title VARCHAR(255) NOT NULL,
            icon_code VARCHAR(50) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_todo_categories_language_sort (language_code, sort_order)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS todo_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            address VARCHAR(255) NULL,
            note TEXT NULL,
            website_url TEXT NULL,
            website_label VARCHAR(255) NULL,
            video_url TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_todo_items_category_sort (category_id, sort_order),
            CONSTRAINT fk_todo_items_category
                FOREIGN KEY (category_id) REFERENCES todo_categories(id)
                ON DELETE CASCADE
        )
    ");
}

function isSiteContentDatabaseEmpty(PDO $pdo): bool
{
    foreach (siteContentTableMap() as $config) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $config['categories_table'])->fetchColumn();

        if ($count > 0) {
            return false;
        }
    }

    return true;
}

function loadLegacySiteContentFromFile(): array
{
    $path = siteContentFilePath();

    if (!file_exists($path)) {
        return siteContentEmptyState();
    }

    $content = require $path;

    if (!is_array($content)) {
        return siteContentEmptyState();
    }

    return array_replace_recursive(siteContentEmptyState(), $content);
}

function migrateLegacySiteContentFileToDatabase(PDO $pdo): void
{
    if (!isSiteContentDatabaseEmpty($pdo)) {
        return;
    }

    $legacyContent = loadLegacySiteContentFromFile();
    $hasLegacyData = false;

    foreach ($legacyContent as $sectionContent) {
        foreach ($sectionContent as $languageContent) {
            if (!empty($languageContent)) {
                $hasLegacyData = true;
                break 2;
            }
        }
    }

    if (!$hasLegacyData) {
        return;
    }

    saveSiteContentToDatabase($pdo, $legacyContent);
}

function saveSiteContentToDatabase(PDO $pdo, array $content): void
{
    $content = array_replace_recursive(siteContentEmptyState(), $content);
    $pdo->beginTransaction();

    try {
        foreach (siteContentTableMap() as $section => $config) {
            $pdo->exec('DELETE FROM ' . $config['items_table']);
            $pdo->exec('DELETE FROM ' . $config['categories_table']);

            $categoryInsert = $pdo->prepare(
                'INSERT INTO ' . $config['categories_table'] . ' (language_code, ' . $config['category_title_column'] . ', ' . $config['category_icon_column'] . ', sort_order)
                 VALUES (:language_code, :title, :icon_code, :sort_order)'
            );

            $itemColumns = array_values($config['item_column_map']);
            $itemInsert = $pdo->prepare(
                'INSERT INTO ' . $config['items_table'] . ' (category_id, ' . implode(', ', $itemColumns) . ', sort_order)
                 VALUES (:category_id, :' . implode(', :', $itemColumns) . ', :sort_order)'
            );

            foreach (Locale::all() as $language) {
                foreach (($content[$section][$language] ?? []) as $categoryOrder => $category) {
                    $categoryInsert->execute([
                        'language_code' => $language,
                        'title' => trim((string) ($category['title'] ?? '')),
                        'icon_code' => ($category['flag'] ?? null) !== null ? trim((string) $category['flag']) : null,
                        'sort_order' => $categoryOrder,
                    ]);

                    $categoryId = (int) $pdo->lastInsertId();

                    foreach (($category['items'] ?? []) as $itemOrder => $item) {
                        $payload = [
                            'category_id' => $categoryId,
                            'sort_order' => $itemOrder,
                        ];

                        foreach ($config['item_column_map'] as $fieldName => $columnName) {
                            $value = $item[$fieldName] ?? null;
                            $payload[$columnName] = $value === null ? null : trim((string) $value);
                        }

                        $itemInsert->execute($payload);
                    }
                }
            }
        }

        $pdo->commit();
    } catch (\Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}

function seedMissingSiteContentLanguage(PDO $pdo, string $targetLanguage, string $sourceLanguage): void
{
    $targetLanguage = Locale::normalize($targetLanguage);
    $sourceLanguage = Locale::normalize($sourceLanguage);

    if ($targetLanguage === $sourceLanguage) {
        return;
    }

    $pdo->beginTransaction();

    try {
        foreach (siteContentTableMap() as $config) {
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM ' . $config['categories_table'] . ' WHERE language_code = :language_code'
            );
            $countStmt->execute(['language_code' => $targetLanguage]);

            if ((int) $countStmt->fetchColumn() > 0) {
                continue;
            }

            $sourceCategoriesStmt = $pdo->prepare(
                'SELECT id, ' . $config['category_title_column'] . ' AS title, ' . $config['category_icon_column'] . ' AS icon_code, sort_order
                 FROM ' . $config['categories_table'] . '
                 WHERE language_code = :language_code
                 ORDER BY sort_order ASC, id ASC'
            );
            $sourceCategoriesStmt->execute(['language_code' => $sourceLanguage]);
            $sourceCategories = $sourceCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($sourceCategories === []) {
                continue;
            }

            $categoryInsert = $pdo->prepare(
                'INSERT INTO ' . $config['categories_table'] . ' (language_code, ' . $config['category_title_column'] . ', ' . $config['category_icon_column'] . ', sort_order)
                 VALUES (:language_code, :title, :icon_code, :sort_order)'
            );

            $itemColumns = array_values($config['item_column_map']);
            $itemInsert = $pdo->prepare(
                'INSERT INTO ' . $config['items_table'] . ' (category_id, ' . implode(', ', $itemColumns) . ', sort_order)
                 VALUES (:category_id, :' . implode(', :', $itemColumns) . ', :sort_order)'
            );

            foreach ($sourceCategories as $categoryRow) {
                $categoryInsert->execute([
                    'language_code' => $targetLanguage,
                    'title' => $categoryRow['title'],
                    'icon_code' => $categoryRow['icon_code'],
                    'sort_order' => (int) $categoryRow['sort_order'],
                ]);

                $newCategoryId = (int) $pdo->lastInsertId();
                $itemRowsStmt = $pdo->prepare(
                    'SELECT ' . implode(', ', $itemColumns) . ', sort_order
                     FROM ' . $config['items_table'] . '
                     WHERE category_id = :category_id
                     ORDER BY sort_order ASC, id ASC'
                );
                $itemRowsStmt->execute([
                    'category_id' => (int) $categoryRow['id'],
                ]);

                foreach ($itemRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $itemRow) {
                    $payload = [
                        'category_id' => $newCategoryId,
                        'sort_order' => (int) $itemRow['sort_order'],
                    ];

                    foreach ($itemColumns as $columnName) {
                        $payload[$columnName] = $itemRow[$columnName] ?? null;
                    }

                    $itemInsert->execute($payload);
                }
            }
        }

        $pdo->commit();
    } catch (\Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $throwable;
    }
}
