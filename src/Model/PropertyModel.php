<?php

namespace App\Model;

use PDO;
use InvalidArgumentException;

class PropertyModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): int
    {
        $this->validateRequiredFields($data);

        $sql = "
            INSERT INTO properties (
                property_type_id,
                name,
                price,
                city,
                bedrooms,
                bathrooms,
                pool_type,
                distance_from_beach,
                parking_type,
                parking_count,
                has_elevator,
                animals_allowed
            ) VALUES (
                :property_type_id,
                :name,
                :price,
                :city,
                :bedrooms,
                :bathrooms,
                :pool_type,
                :distance_from_beach,
                :parking_type,
                :parking_count,
                :has_elevator,
                :animals_allowed
            )
        ";

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->buildPropertyParams($data));

            $propertyId = (int) $this->pdo->lastInsertId();
            $this->syncListingTypes($propertyId, $data['listing_type_ids'] ?? []);

            $this->pdo->commit();

            return $propertyId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function update(int $id, array $data): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid property ID.');
        }

        $this->validateRequiredFields($data);

        $sql = "
            UPDATE properties
            SET
                property_type_id = :property_type_id,
                name = :name,
                price = :price,
                city = :city,
                bedrooms = :bedrooms,
                bathrooms = :bathrooms,
                pool_type = :pool_type,
                distance_from_beach = :distance_from_beach,
                parking_type = :parking_type,
                parking_count = :parking_count,
                has_elevator = :has_elevator,
                animals_allowed = :animals_allowed
            WHERE id = :id
        ";

        try {
            $this->pdo->beginTransaction();

            $params = $this->buildPropertyParams($data);
            $params['id'] = $id;

            $stmt = $this->pdo->prepare($sql);
            $updated = $stmt->execute($params);

            $this->syncListingTypes($id, $data['listing_type_ids'] ?? []);

            $this->pdo->commit();

            return $updated;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid property ID.');
        }

        $stmt = $this->pdo->prepare("DELETE FROM properties WHERE id = :id");

        return $stmt->execute([
            'id' => $id,
        ]);
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                p.*,
                pt.name_fr AS property_type_name_fr,
                pt.name_en AS property_type_name_en
            FROM properties p
            INNER JOIN property_types pt ON pt.id = p.property_type_id
            ORDER BY p.created_at DESC
        ");

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM properties
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $id]);
        $property = $stmt->fetch();

        return $property ?: null;
    }

    private function validateRequiredFields(array $data): void
    {
        $requiredFields = [
            'property_type_id',
            'name',
            'price',
            'city',
            'bedrooms',
            'bathrooms',
        ];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }

    private function buildPropertyParams(array $data): array
    {
        return [
            'property_type_id' => (int) $data['property_type_id'],
            'name' => trim((string) $data['name']),
            'price' => (float) $data['price'],
            'city' => trim((string) $data['city']),
            'bedrooms' => (int) $data['bedrooms'],
            'bathrooms' => (int) $data['bathrooms'],
            'pool_type' => $this->nullableString($data['pool_type'] ?? null),
            'distance_from_beach' => $this->nullableString($data['distance_from_beach'] ?? null),
            'parking_type' => $this->cleanParkingType($data['parking_type'] ?? 'aucun'),
            'parking_count' => (int) ($data['parking_count'] ?? 0),
            'has_elevator' => $this->checkboxToInt($data['has_elevator'] ?? null),
            'animals_allowed' => $this->checkboxToInt($data['animals_allowed'] ?? null),
        ];
    }

    private function syncListingTypes(int $propertyId, array|string|int $listingTypeIds): void
    {
        if (!is_array($listingTypeIds)) {
            $listingTypeIds = [$listingTypeIds];
        }

        $deleteStmt = $this->pdo->prepare("DELETE FROM property_listing_types WHERE property_id = :property_id");
        $deleteStmt->execute(['property_id' => $propertyId]);

        if (empty($listingTypeIds)) {
            return;
        }

        $insertStmt = $this->pdo->prepare("
            INSERT INTO property_listing_types (property_id, listing_type_id)
            VALUES (:property_id, :listing_type_id)
        ");

        foreach ($listingTypeIds as $listingTypeId) {
            if ((int) $listingTypeId <= 0) {
                continue;
            }

            $insertStmt->execute([
                'property_id' => $propertyId,
                'listing_type_id' => (int) $listingTypeId,
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function checkboxToInt(mixed $value): int
    {
        return !empty($value) ? 1 : 0;
    }

    private function cleanParkingType(string $parkingType): string
    {
        $allowed = ['interieur', 'exterieur', 'les_deux', 'aucun'];

        return in_array($parkingType, $allowed, true) ? $parkingType : 'aucun';
    }
}
