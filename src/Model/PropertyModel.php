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
                listing_mode,
                price_amount,
                price_currency,
                external_url
            ) VALUES (
                :image_url,
                :name,
                :city,
                :listing_mode,
                :price_amount,
                :price_currency,
                :external_url
            )
        ");

        $stmt->execute($payload);

        return (int) $this->pdo->lastInsertId();
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

        if (!in_array($mode, ['all', 'achat', 'location'], true)) {
            $mode = 'all';
        }

        $sql = "
            SELECT *
            FROM property_cards
        ";

        $params = [];

        if ($mode !== 'all') {
            $sql .= " WHERE listing_mode = :listing_mode";
            $params['listing_mode'] = $mode;
        }

        $sql .= " ORDER BY created_at DESC, id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $properties = $stmt->fetchAll();

        return array_map(fn (array $property) => $this->decorateProperty($property), $properties);
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

        return $this->decorateProperty($property);
    }

    private function normalizePayload(array $data): array
    {
        $imageUrl = trim((string) ($data['image_url'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $listingMode = strtolower(trim((string) ($data['listing_mode'] ?? 'achat')));
        $priceAmount = trim((string) ($data['price_amount'] ?? ''));
        $priceCurrency = strtoupper(trim((string) ($data['price_currency'] ?? 'USD')));
        $externalUrl = trim((string) ($data['external_url'] ?? ''));

        if ($imageUrl === '' || $name === '' || $city === '' || $priceAmount === '' || $externalUrl === '') {
            throw new InvalidArgumentException('Missing required property fields.');
        }

        if (!is_numeric($priceAmount)) {
            throw new InvalidArgumentException('Invalid property price.');
        }

        if (!in_array($priceCurrency, ['USD', 'CAD'], true)) {
            throw new InvalidArgumentException('Invalid property currency.');
        }

        if (!in_array($listingMode, ['achat', 'location'], true)) {
            throw new InvalidArgumentException('Invalid listing mode.');
        }

        return [
            'image_url' => $imageUrl,
            'name' => $name,
            'city' => $city,
            'listing_mode' => $listingMode,
            'price_amount' => (float) $priceAmount,
            'price_currency' => $priceCurrency,
            'external_url' => $externalUrl,
        ];
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
        $property['listing_mode_label'] = $property['listing_mode'] === 'location' ? 'Location' : 'Achat';

        return $property;
    }

    private function formatPrice(float $amount, string $currency): string
    {
        return '$' . number_format($amount, 0, '.', ' ') . ' ' . $currency;
    }
}
