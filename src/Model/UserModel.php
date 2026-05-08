<?php

namespace App\Model;

use PDO;

class UserModel
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(string $name, string $email, string $password): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (name, email, password)
            VALUES (:name, :email, :password)
        ");

        $stmt->execute([
            'name' => trim($name),
            'email' => strtolower(trim($email)),
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, password, created_at
            FROM users
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute([
            'email' => strtolower(trim($email)),
        ]);

        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, email, created_at
            FROM users
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $id,
        ]);

        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function updateName(int $id, string $name): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET name = :name
            WHERE id = :id
        ");

        return $stmt->execute([
            'id' => $id,
            'name' => trim($name),
        ]);
    }

    public function verifyCredentials(string $email, string $password): ?array
    {
        $user = $this->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password'])) {
            return null;
        }

        unset($user['password']);

        return $user;
    }
}
