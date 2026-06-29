<?php

namespace App\Model;

use InvalidArgumentException;
use PDO;

class PropertyModel
{
    private const USD_TO_CAD_RATE = 1.3625;
    private const USD_TO_CAD_RATE_DATE = '2026-05-06';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): int
    {
        $payload = $this->normalizePayload($data);

        $stmt = $this->pdo->prepare("
            INSERT INTO property_cards (
                image_url,
                name,
                city,
                description,
                listing_mode,
                price_amount,
                price_currency,
                external_url
            ) VALUES (
                :image_url,
                :name,
                :city,
                :description,
                :listing_mode,
                :price_amount,
                :price_currency,
                :external_url
            )
        ");

        $stmt->execute($payload);

        return (int) $this->pdo->lastInsertId();
    }

    public function replaceImages(int $propertyId, array $imageUrls): void
    {
        if ($propertyId <= 0) {
            throw new InvalidArgumentException('Invalid property ID.');
        }

        $this->pdo->prepare('DELETE FROM property_card_images WHERE property_card_id = :property_card_id')
            ->execute([
                'property_card_id' => $propertyId,
            ]);

        $this->insertImages($propertyId, $imageUrls);
    }

    public function addImages(int $propertyId, array $imageUrls): void
    {
        if ($propertyId <= 0) {
            throw new InvalidArgumentException('Invalid property ID.');
        }

        $existingCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM property_card_images WHERE property_card_id = :property_card_id');
        $existingCountStmt->execute([
            'property_card_id' => $propertyId,
        ]);

        $offset = (int) $existingCountStmt->fetchColumn();

        $this->insertImages($propertyId, $imageUrls, $offset);
    }

    public function removeImages(int $propertyId, array $imageUrls): void
    {
        if ($propertyId <= 0) {
            throw new InvalidArgumentException('Invalid property ID.');
        }

        $property = $this->findById($propertyId);

        if ($property === null) {
            throw new InvalidArgumentException('Property not found.');
        }

        $selectedImages = array_values(array_intersect(
            $property['images'] ?? [],
            array_map(static fn ($imageUrl): string => trim((string) $imageUrl), $imageUrls)
        ));

        if ($selectedImages === []) {
            throw new InvalidArgumentException('No property images selected.');
        }

        $remainingImages = array_values(array_filter(
            $property['images'] ?? [],
            static fn (string $imageUrl): bool => !in_array($imageUrl, $selectedImages, true)
        ));

        if ($remainingImages === [] || !$this->hasImageMedia($remainingImages)) {
            throw new InvalidArgumentException('A property must keep at least one image.');
        }

        $this->pdo->prepare('DELETE FROM property_card_images WHERE property_card_id = :property_card_id')
            ->execute([
                'property_card_id' => $propertyId,
            ]);

        $this->insertImages($propertyId, $remainingImages);

        $this->pdo->prepare('UPDATE property_cards SET image_url = :image_url WHERE id = :id')
            ->execute([
                'image_url' => $this->firstImageUrl($remainingImages) ?? $remainingImages[0],
                'id' => $propertyId,
            ]);

        foreach ($selectedImages as $imageUrl) {
            $this->deleteManagedUploadFile($imageUrl);
        }
    }

    public function setPrimaryImage(int $propertyId, string $imageUrl): void
    {
        if ($propertyId <= 0) {
            throw new InvalidArgumentException('Invalid property ID.');
        }

        $property = $this->findById($propertyId);

        if ($property === null) {
            throw new InvalidArgumentException('Property not found.');
        }

        $images = $property['images'] ?? [];

        if (!in_array($imageUrl, $images, true)) {
            throw new InvalidArgumentException('Property image not found.');
        }

        if (!$this->buildMediaItem($imageUrl)['is_image']) {
            throw new InvalidArgumentException('Primary media must be an image.');
        }

        $orderedImages = array_values(array_filter(
            $images,
            static fn (string $existingImageUrl): bool => $existingImageUrl !== $imageUrl
        ));
        array_unshift($orderedImages, $imageUrl);

        $this->pdo->prepare('DELETE FROM property_card_images WHERE property_card_id = :property_card_id')
            ->execute([
                'property_card_id' => $propertyId,
            ]);

        $this->insertImages($propertyId, $orderedImages);

        $this->pdo->prepare('UPDATE property_cards SET image_url = :image_url WHERE id = :id')
            ->execute([
                'image_url' => $imageUrl,
                'id' => $propertyId,
            ]);
    }

    public function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid property ID.');
        }

        if (empty($data['image_url'])) {
            $existingProperty = $this->findById($id);

            if ($existingProperty === null) {
                throw new InvalidArgumentException('Property not found.');
            }

            $data['image_url'] = $existingProperty['image_url'];
        }

        $payload = $this->normalizePayload($data);
        $payload['id'] = $id;

        $stmt = $this->pdo->prepare("
            UPDATE property_cards
            SET
                image_url = :image_url,
                name = :name,
                city = :city,
                description = :description,
                listing_mode = :listing_mode,
                price_amount = :price_amount,
                price_currency = :price_currency,
                external_url = :external_url
            WHERE id = :id
        ");

        return $stmt->execute($payload);
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid property ID.');
        }

        $this->pdo->prepare("DELETE FROM property_card_images WHERE property_card_id = :id")
            ->execute([
                'id' => $id,
            ]);

        $stmt = $this->pdo->prepare("DELETE FROM property_cards WHERE id = :id");

        return $stmt->execute([
            'id' => $id,
        ]);
    }

    public function findAll(): array
    {
        return $this->findAllByMode('all');
    }

    public function findAllByMode(string $mode): array
    {
        $mode = strtolower($mode);

        if (!in_array($mode, ['all', 'sale_all', 'achat', 'location', 'revente', 'future_projet'], true)) {
            $mode = 'all';
        }

        $sql = "
            SELECT *
            FROM property_cards
        ";

        $params = [];

        if ($mode === 'sale_all') {
            $sql .= " WHERE listing_mode IN ('achat', 'revente', 'future_projet')";
        } elseif ($mode === 'revente') {
            $sql .= " WHERE listing_mode IN ('achat', 'revente')";
        } elseif ($mode !== 'all') {
            $sql .= " WHERE listing_mode = :listing_mode";
            $params['listing_mode'] = $mode;
        }

        $sql .= " ORDER BY created_at DESC, id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $properties = $stmt->fetchAll();

        return $this->attachImages(array_map(fn (array $property) => $this->decorateProperty($property), $properties));
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM property_cards
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $id,
        ]);

        $property = $stmt->fetch();

        if ($property === false) {
            return null;
        }

        $properties = $this->attachImages([$this->decorateProperty($property)]);

        return $properties[0] ?? null;
    }

    private function normalizePayload(array $data): array
    {
        $imageUrl = trim((string) ($data['image_url'] ?? ''));
        $name = $this->capitalizeFirstCharacter(trim((string) ($data['name'] ?? '')));
        $city = $this->capitalizeFirstCharacter(trim((string) ($data['city'] ?? '')));
        $description = trim((string) ($data['description'] ?? ''));
        $listingMode = strtolower(trim((string) ($data['listing_mode'] ?? 'revente')));
        $priceAmount = trim((string) ($data['price_amount'] ?? ''));
        $priceCurrency = strtoupper(trim((string) ($data['price_currency'] ?? 'USD')));

        if ($imageUrl === '' || $name === '' || $city === '' || $description === '' || $priceAmount === '') {
            throw new InvalidArgumentException('Missing required property fields.');
        }

        if (!is_numeric($priceAmount)) {
            throw new InvalidArgumentException('Invalid property price.');
        }

        if (!in_array($priceCurrency, ['USD', 'CAD', 'MXN'], true)) {
            throw new InvalidArgumentException('Invalid property currency.');
        }

        if ($listingMode === 'achat') {
            $listingMode = 'revente';
        }

        if (!in_array($listingMode, ['revente', 'future_projet', 'location'], true)) {
            throw new InvalidArgumentException('Invalid listing mode.');
        }

        return [
            'image_url' => $imageUrl,
            'name' => $name,
            'city' => $city,
            'description' => $description,
            'listing_mode' => $listingMode,
            'price_amount' => (float) $priceAmount,
            'price_currency' => $priceCurrency,
            'external_url' => '',
        ];
    }

    private function attachImages(array $properties): array
    {
        if ($properties === []) {
            return [];
        }

        $propertyIds = array_values(array_map(
            static fn (array $property): int => (int) $property['id'],
            $properties
        ));

        $imagesByPropertyId = $this->fetchImagesByPropertyIds($propertyIds);

        foreach ($properties as &$property) {
            $propertyId = (int) $property['id'];
            $images = $imagesByPropertyId[$propertyId] ?? [];

            if ($images === [] && !empty($property['image_url'])) {
                $images = [$property['image_url']];
            }

            $property['images'] = $images;
            $property['media_items'] = array_map(fn (string $imageUrl): array => $this->buildMediaItem($imageUrl), $images);
            $property['image_count'] = count($images);
            $property['media_count'] = count($images);
            $property['detail_url'] = '/properties/' . $propertyId;
        }
        unset($property);

        return $properties;
    }

    private function fetchImagesByPropertyIds(array $propertyIds): array
    {
        if ($propertyIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($propertyIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT property_card_id, image_url
            FROM property_card_images
            WHERE property_card_id IN ($placeholders)
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute($propertyIds);

        $imagesByPropertyId = [];

        foreach ($stmt->fetchAll() as $row) {
            $propertyId = (int) $row['property_card_id'];
            $imagesByPropertyId[$propertyId] ??= [];
            $imagesByPropertyId[$propertyId][] = (string) $row['image_url'];
        }

        return $imagesByPropertyId;
    }

    private function insertImages(int $propertyId, array $imageUrls, int $offset = 0): void
    {
        $imageUrls = array_values(array_filter(
            array_map(static fn ($imageUrl): string => trim((string) $imageUrl), $imageUrls),
            static fn (string $imageUrl): bool => $imageUrl !== ''
        ));

        if ($imageUrls === []) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO property_card_images (
                property_card_id,
                image_url,
                sort_order
            ) VALUES (
                :property_card_id,
                :image_url,
                :sort_order
            )
        ");

        foreach ($imageUrls as $index => $imageUrl) {
            $stmt->execute([
                'property_card_id' => $propertyId,
                'image_url' => $imageUrl,
                'sort_order' => $offset + $index,
            ]);
        }
    }

    private function deleteManagedUploadFile(string $imageUrl): void
    {
        $basePath = '/uploads/properties/';

        if (!str_starts_with($imageUrl, $basePath)) {
            return;
        }

        $filename = basename($imageUrl);
        $filePath = dirname(__DIR__, 2) . '/public/uploads/properties/' . $filename;

        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    private function buildMediaItem(string $url): array
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $videoExtensions = ['mp4', 'mov', 'webm'];
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        return [
            'url' => $url,
            'type' => in_array($extension, $videoExtensions, true) ? 'video' : 'image',
            'is_video' => in_array($extension, $videoExtensions, true),
            'is_image' => in_array($extension, $imageExtensions, true),
            'mime_type' => match ($extension) {
                'mp4' => 'video/mp4',
                'mov' => 'video/quicktime',
                'webm' => 'video/webm',
                default => null,
            },
        ];
    }

    private function hasImageMedia(array $mediaUrls): bool
    {
        foreach ($mediaUrls as $mediaUrl) {
            if ($this->buildMediaItem((string) $mediaUrl)['is_image']) {
                return true;
            }
        }

        return false;
    }

    private function firstImageUrl(array $mediaUrls): ?string
    {
        foreach ($mediaUrls as $mediaUrl) {
            if ($this->buildMediaItem((string) $mediaUrl)['is_image']) {
                return (string) $mediaUrl;
            }
        }

        return null;
    }

    private function capitalizeFirstCharacter(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $firstCharacter = mb_substr($value, 0, 1, 'UTF-8');
        $remainingCharacters = mb_substr($value, 1, null, 'UTF-8');

        return mb_strtoupper($firstCharacter, 'UTF-8') . $remainingCharacters;
    }

    private function decorateProperty(array $property): array
    {
        $amount = (float) $property['price_amount'];
        $currency = strtoupper((string) $property['price_currency']);

        if ($currency === 'CAD') {
            $primaryAmount = $amount;
            $primaryCurrency = 'CAD';
            $secondaryAmount = $amount / self::USD_TO_CAD_RATE;
            $secondaryCurrency = 'USD';
        } else {
            $primaryAmount = $amount * self::USD_TO_CAD_RATE;
            $primaryCurrency = 'CAD';
            $secondaryAmount = $amount;
            $secondaryCurrency = 'USD';
        }

        $property['price_display'] = $this->formatPrice($amount, $currency);
        $property['primary_price_display'] = $this->formatPrice($primaryAmount, $primaryCurrency);
        $property['secondary_price_display'] = $this->formatPrice($secondaryAmount, $secondaryCurrency);
        $property['exchange_rate_date'] = self::USD_TO_CAD_RATE_DATE;
        $property['listing_mode_label'] = match ($property['listing_mode']) {
            'location' => 'Location',
            'future_projet' => 'Nouveau projet',
            'revente', 'achat' => 'Revente',
            default => 'Revente',
        };

        return $property;
    }

    private function formatPrice(float $amount, string $currency): string
    {
        return '$' . number_format($amount, 0, '.', ' ') . ' ' . $currency;
    }
}
