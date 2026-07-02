<?php

namespace App\Model;

use App\Helper\Locale;
use App\Service\GoogleTranslateService;
use InvalidArgumentException;
use PDO;

class RestaurantModel
{
    private const MANAGED_LANGUAGES = ['fr', 'en', 'es'];

    public function __construct(private PDO $pdo, private ?GoogleTranslateService $googleTranslateService = null)
    {
    }

    public function findAllByLanguage(string $language): array
    {
        $language = $this->normalizeLanguage($language);
        $categories = [];

        foreach ($this->fetchCategoryRowsByLanguage($language) as $categoryRow) {
            $categories[] = [
                'title' => (string) $categoryRow['title'],
                'flag' => $categoryRow['icon_code'] !== null && trim((string) $categoryRow['icon_code']) !== ''
                    ? trim((string) $categoryRow['icon_code'])
                    : null,
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

        return $itemRow === null ? null : $this->mapItemRowToViewData($itemRow);
    }

    public function createItem(string $language, array $data): void
    {
        $language = $this->normalizeLanguage($language);
        $this->pdo->beginTransaction();

        try {
            $this->saveItem($language, $data, null, null);
            $this->syncTranslatedItem($language, $data, null, null);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function updateItem(string $language, int $sourceCategoryIndex, int $sourceItemIndex, array $data): void
    {
        $language = $this->normalizeLanguage($language);
        $this->pdo->beginTransaction();

        try {
            $this->saveItem($language, $data, $sourceCategoryIndex, $sourceItemIndex);
            $this->syncTranslatedItem($language, $data, $sourceCategoryIndex, $sourceItemIndex);
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
        $this->pdo->beginTransaction();

        try {
            foreach (self::MANAGED_LANGUAGES as $managedLanguage) {
                $this->deleteItemInLanguage($managedLanguage, $categoryIndex, $itemIndex, $managedLanguage === $language);
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
        $language = $this->normalizeLanguage($language);
        $this->pdo->beginTransaction();

        try {
            foreach (self::MANAGED_LANGUAGES as $managedLanguage) {
                $this->deleteCategoryInLanguage($managedLanguage, $categoryIndex, $managedLanguage === $language);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function saveItem(string $language, array $data, ?int $sourceCategoryIndex, ?int $sourceItemIndex): void
    {
        $language = $this->normalizeLanguage($language);
        $payload = $this->normalizeItemPayload($data, $language);
        $destinationCategoryId = $this->resolveDestinationCategoryId($language, $data);

        if ($sourceCategoryIndex === null || $sourceItemIndex === null) {
            $this->insertItem($destinationCategoryId, $payload, $this->countItemsInCategory($destinationCategoryId));

            return;
        }

        $sourceCategoryRow = $this->fetchCategoryRowByIndex($language, $sourceCategoryIndex);

        if ($sourceCategoryRow === null) {
            throw new InvalidArgumentException($this->localizedMessage('item_not_found', $language));
        }

        $itemRow = $this->fetchItemRowByIndex((int) $sourceCategoryRow['id'], $sourceItemIndex);

        if ($itemRow === null) {
            throw new InvalidArgumentException($this->localizedMessage('item_not_found', $language));
        }

        $newSortOrder = $destinationCategoryId === (int) $sourceCategoryRow['id']
            ? (int) $itemRow['sort_order']
            : $this->countItemsInCategory($destinationCategoryId);

        $this->updateItemRow((int) $itemRow['id'], $destinationCategoryId, $payload, $newSortOrder);

        if ($destinationCategoryId !== (int) $sourceCategoryRow['id']) {
            $this->resequenceItems((int) $sourceCategoryRow['id']);
            $this->resequenceItems($destinationCategoryId);
        }
    }

    private function normalizeItemPayload(array $data, string $language): array
    {
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'address' => trim((string) ($data['area'] ?? '')),
            'price' => trim((string) ($data['price'] ?? '')),
            'reference_label' => trim((string) ($data['reference'] ?? '')),
            'website_url' => $this->normalizeExternalUrl((string) ($data['url'] ?? '')),
            'website_label' => '',
        ];

        foreach (['name', 'address', 'price', 'website_url'] as $requiredField) {
            if (trim((string) ($payload[$requiredField] ?? '')) === '') {
                throw new InvalidArgumentException($this->localizedMessage('required_fields', $language));
            }
        }

        $payload['website_label'] = $this->labelFromUrl($payload['website_url']);

        return $payload;
    }

    private function resolveDestinationCategoryId(string $language, array $data): int
    {
        $categoryChoice = trim((string) ($data['category_choice'] ?? ''));

        if ($categoryChoice === '__new__') {
            $newTitle = trim((string) ($data['new_category_title'] ?? ''));

            if ($newTitle === '') {
                throw new InvalidArgumentException($this->localizedMessage('new_category_title_required', $language));
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO restaurant_categories (language_code, title, icon_code, sort_order)
                 VALUES (:language_code, :title, :icon_code, :sort_order)'
            );
            $stmt->execute([
                'language_code' => $language,
                'title' => $newTitle,
                'icon_code' => ($data['new_category_flag'] ?? null) !== null && trim((string) $data['new_category_flag']) !== ''
                    ? trim((string) $data['new_category_flag'])
                    : null,
                'sort_order' => count($this->fetchCategoryRowsByLanguage($language)),
            ]);

            return (int) $this->pdo->lastInsertId();
        }

        if ($categoryChoice !== '') {
            $categoryRow = $this->fetchCategoryRowByIndex($language, (int) $categoryChoice);

            if ($categoryRow !== null) {
                return (int) $categoryRow['id'];
            }
        }

        throw new InvalidArgumentException($this->localizedMessage('select_category', $language));
    }

    private function insertItem(int $categoryId, array $payload, int $sortOrder): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO restaurants (category_id, name, address, price, reference_label, website_url, website_label, sort_order)
             VALUES (:category_id, :name, :address, :price, :reference_label, :website_url, :website_label, :sort_order)'
        );
        $stmt->execute(array_merge(
            [
                'category_id' => $categoryId,
                'sort_order' => $sortOrder,
            ],
            $payload
        ));
    }

    private function updateItemRow(int $itemId, int $categoryId, array $payload, int $sortOrder): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE restaurants
             SET category_id = :category_id,
                 name = :name,
                 address = :address,
                 price = :price,
                 reference_label = :reference_label,
                 website_url = :website_url,
                 website_label = :website_label,
                 sort_order = :sort_order
             WHERE id = :id'
        );
        $stmt->execute(array_merge(
            $payload,
            [
                'category_id' => $categoryId,
                'sort_order' => $sortOrder,
                'id' => $itemId,
            ]
        ));
    }

    private function syncTranslatedItem(string $sourceLanguage, array $data, ?int $sourceCategoryIndex, ?int $sourceItemIndex): void
    {
        if ($sourceLanguage !== 'fr') {
            return;
        }

        foreach (self::MANAGED_LANGUAGES as $targetLanguage) {
            if ($targetLanguage === $sourceLanguage) {
                continue;
            }

            $translatedData = $this->buildTranslatedData($data, $sourceLanguage, $targetLanguage);
            $this->saveItem($targetLanguage, $translatedData, $sourceCategoryIndex, $sourceItemIndex);
        }
    }

    private function buildTranslatedData(array $data, string $sourceLanguage, string $targetLanguage): array
    {
        $translatedData = $data;
        $sourceCategoryIndex = $this->resolvePostedCategoryIndexAfterSourceSave($sourceLanguage, $data);

        if ($sourceCategoryIndex !== null) {
            $this->ensureMirroredCategoryExists($sourceLanguage, $targetLanguage, $sourceCategoryIndex);
            $translatedData['category_choice'] = (string) $sourceCategoryIndex;
        }

        return $translatedData;
    }

    private function resolvePostedCategoryIndexAfterSourceSave(string $sourceLanguage, array $data): ?int
    {
        $categoryChoice = trim((string) ($data['category_choice'] ?? ''));

        if ($categoryChoice === '__new__') {
            $categories = $this->fetchCategoryRowsByLanguage($sourceLanguage);

            return $categories === [] ? null : count($categories) - 1;
        }

        if ($categoryChoice === '' || !is_numeric($categoryChoice)) {
            return null;
        }

        return (int) $categoryChoice;
    }

    private function ensureMirroredCategoryExists(string $sourceLanguage, string $targetLanguage, int $categoryIndex): void
    {
        if ($this->fetchCategoryRowByIndex($targetLanguage, $categoryIndex) !== null) {
            return;
        }

        $sourceCategoryRow = $this->fetchCategoryRowByIndex($sourceLanguage, $categoryIndex);

        if ($sourceCategoryRow === null) {
            throw new InvalidArgumentException($this->localizedMessage('category_not_found', $sourceLanguage));
        }

        $this->insertCategory(
            $targetLanguage,
            $this->translateText((string) ($sourceCategoryRow['title'] ?? ''), $sourceLanguage, $targetLanguage),
            $sourceCategoryRow['icon_code'] ?? null,
            $categoryIndex
        );
    }

    private function insertCategory(string $language, string $title, ?string $iconCode, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO restaurant_categories (language_code, title, icon_code, sort_order)
             VALUES (:language_code, :title, :icon_code, :sort_order)'
        );
        $stmt->execute([
            'language_code' => $language,
            'title' => trim($title),
            'icon_code' => $iconCode !== null && trim((string) $iconCode) !== '' ? trim((string) $iconCode) : null,
            'sort_order' => $sortOrder,
        ]);

        return (int) $this->pdo->lastInsertId();
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

    private function deleteItemInLanguage(string $language, int $categoryIndex, int $itemIndex, bool $strict): void
    {
        $categoryRow = $this->fetchCategoryRowByIndex($language, $categoryIndex);

        if ($categoryRow === null) {
            if ($strict) {
                throw new InvalidArgumentException($this->localizedMessage('category_not_found', $language));
            }

            return;
        }

        $itemRow = $this->fetchItemRowByIndex((int) $categoryRow['id'], $itemIndex);

        if ($itemRow === null) {
            if ($strict) {
                throw new InvalidArgumentException($this->localizedMessage('item_not_found', $language));
            }

            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM restaurants WHERE id = :id');
        $stmt->execute([
            'id' => (int) $itemRow['id'],
        ]);

        $this->resequenceItems((int) $categoryRow['id']);
    }

    private function deleteCategoryInLanguage(string $language, int $categoryIndex, bool $strict): void
    {
        $categoryRow = $this->fetchCategoryRowByIndex($language, $categoryIndex);

        if ($categoryRow === null) {
            if ($strict) {
                throw new InvalidArgumentException($this->localizedMessage('category_not_found', $language));
            }

            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM restaurant_categories WHERE id = :id');
        $stmt->execute([
            'id' => (int) $categoryRow['id'],
        ]);

        $this->resequenceCategories($language);
    }

    private function fetchCategoryRowsByLanguage(string $language): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, language_code, title, icon_code, sort_order
             FROM restaurant_categories
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
            'SELECT * FROM restaurants WHERE category_id = :category_id ORDER BY sort_order ASC, id ASC'
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

    private function resequenceCategories(string $language): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE restaurant_categories SET sort_order = :sort_order WHERE id = :id'
        );

        foreach ($this->fetchCategoryRowsByLanguage($language) as $index => $categoryRow) {
            $stmt->execute([
                'sort_order' => $index,
                'id' => (int) $categoryRow['id'],
            ]);
        }
    }

    private function resequenceItems(int $categoryId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE restaurants SET sort_order = :sort_order WHERE id = :id'
        );

        foreach ($this->fetchItemRowsByCategoryId($categoryId) as $index => $itemRow) {
            $stmt->execute([
                'sort_order' => $index,
                'id' => (int) $itemRow['id'],
            ]);
        }
    }

    private function countItemsInCategory(int $categoryId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM restaurants WHERE category_id = :category_id');
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
            'area' => $itemRow['address'] ?? null,
            'price' => $itemRow['price'] ?? null,
            'reference' => $itemRow['reference_label'] ?? null,
            'url' => $url !== '' ? $url : null,
            'url_label' => $url !== '' ? $this->labelFromUrl($url) : null,
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

        $host = preg_replace('/^www\./i', '', (string) ($parts['host'] ?? '')) ?? '';
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

    private function localizedMessage(string $key, string $language): string
    {
        $messages = [
            'required_fields' => [
                'en' => 'Please fill in all required fields.',
                'fr' => 'Veuillez remplir tous les champs obligatoires.',
            ],
            'new_category_title_required' => [
                'en' => 'Please enter the new category title.',
                'fr' => 'Veuillez saisir le titre de la nouvelle catégorie.',
            ],
            'select_category' => [
                'en' => 'Please select a category.',
                'fr' => 'Veuillez sélectionner une catégorie.',
            ],
            'item_not_found' => [
                'en' => 'Item not found.',
                'fr' => 'Élément introuvable.',
            ],
            'category_not_found' => [
                'en' => 'Category not found.',
                'fr' => 'Catégorie introuvable.',
            ],
        ];

        return $messages[$key][$this->normalizeLanguage($language)] ?? $messages[$key]['fr'] ?? $key;
    }
}

