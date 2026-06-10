<?php

namespace App\Model;

use App\Helper\Locale;
use InvalidArgumentException;
use PDO;

class VideoCapsuleModel
{
    public function __construct(private PDO $pdo)
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
        $this->saveItem($language, $data, null, null);
    }

    public function updateItem(string $language, int $sourceCategoryIndex, int $sourceItemIndex, array $data): void
    {
        $this->saveItem($language, $data, $sourceCategoryIndex, $sourceItemIndex);
    }

    public function deleteItemByIndexes(string $language, int $categoryIndex, int $itemIndex): void
    {
        $categoryRow = $this->fetchCategoryRowByIndex($this->normalizeLanguage($language), $categoryIndex);

        if ($categoryRow === null) {
            throw new InvalidArgumentException($this->localizedMessage('category_not_found', $language));
        }

        $itemRow = $this->fetchItemRowByIndex((int) $categoryRow['id'], $itemIndex);

        if ($itemRow === null) {
            throw new InvalidArgumentException($this->localizedMessage('item_not_found', $language));
        }

        $stmt = $this->pdo->prepare('DELETE FROM video_capsules WHERE id = :id');
        $stmt->execute([
            'id' => (int) $itemRow['id'],
        ]);

        $this->resequenceItems((int) $categoryRow['id']);
    }

    public function deleteCategoryByIndex(string $language, int $categoryIndex): void
    {
        $language = $this->normalizeLanguage($language);
        $categoryRow = $this->fetchCategoryRowByIndex($language, $categoryIndex);

        if ($categoryRow === null) {
            throw new InvalidArgumentException($this->localizedMessage('category_not_found', $language));
        }

        $stmt = $this->pdo->prepare('DELETE FROM video_capsule_categories WHERE id = :id');
        $stmt->execute([
            'id' => (int) $categoryRow['id'],
        ]);

        $this->resequenceCategories($language);
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
            'note' => trim((string) ($data['note'] ?? '')),
            'video_url' => $this->normalizeVideoSource((string) ($data['video_url'] ?? '')),
        ];

        foreach (['name', 'video_url'] as $requiredField) {
            if (trim((string) ($payload[$requiredField] ?? '')) === '') {
                throw new InvalidArgumentException($this->localizedMessage('required_fields', $language));
            }
        }

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
                'INSERT INTO video_capsule_categories (language_code, title, icon_code, sort_order)
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
            'INSERT INTO video_capsules (category_id, name, note, video_url, sort_order)
             VALUES (:category_id, :name, :note, :video_url, :sort_order)'
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
            'UPDATE video_capsules
             SET category_id = :category_id,
                 name = :name,
                 note = :note,
                 video_url = :video_url,
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

    private function fetchCategoryRowsByLanguage(string $language): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, language_code, title, icon_code, sort_order
             FROM video_capsule_categories
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
            'SELECT * FROM video_capsules WHERE category_id = :category_id ORDER BY sort_order ASC, id ASC'
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
            'UPDATE video_capsule_categories SET sort_order = :sort_order WHERE id = :id'
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
            'UPDATE video_capsules SET sort_order = :sort_order WHERE id = :id'
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
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM video_capsules WHERE category_id = :category_id');
        $stmt->execute([
            'category_id' => $categoryId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function mapItemRowToViewData(array $itemRow): array
    {
        return [
            'name' => $itemRow['name'] ?? null,
            'note' => $itemRow['note'] ?? null,
            'video_url' => $this->normalizeVideoSource((string) ($itemRow['video_url'] ?? '')),
        ];
    }

    private function normalizeLanguage(string $language): string
    {
        return Locale::normalize($language);
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

    private function normalizeVideoSource(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        return $this->normalizeExternalUrl($value);
    }

    private function localizedMessage(string $key, string $language): string
    {
        $messages = [
            'required_fields' => [
                'en' => 'Please fill in the title and video link.',
                'fr' => 'Veuillez remplir le titre et le lien vidéo.',
                'es' => 'Completa el título y el enlace del video.',
            ],
            'new_category_title_required' => [
                'en' => 'Please enter the new category title.',
                'fr' => 'Veuillez saisir le titre de la nouvelle catégorie.',
                'es' => 'Ingresa el título de la nueva categoría.',
            ],
            'select_category' => [
                'en' => 'Please select a category.',
                'fr' => 'Veuillez sélectionner une catégorie.',
                'es' => 'Selecciona una categoría.',
            ],
            'item_not_found' => [
                'en' => 'Video not found.',
                'fr' => 'Vidéo introuvable.',
                'es' => 'Video no encontrado.',
            ],
            'category_not_found' => [
                'en' => 'Category not found.',
                'fr' => 'Catégorie introuvable.',
                'es' => 'Categoría no encontrada.',
            ],
        ];

        return $messages[$key][$this->normalizeLanguage($language)] ?? $messages[$key]['en'] ?? $key;
    }
}
