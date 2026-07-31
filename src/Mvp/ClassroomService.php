<?php
declare(strict_types=1);

namespace EnglAI\Mvp;

final class ClassroomService
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(string $teacherKey, string $name): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw new \InvalidArgumentException('Nama classroom wajib diisi (maksimal 150 karakter).');
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'ENG-' . strtoupper(substr(strtr(base64_encode(random_bytes(6)), '+/', 'AZ'), 0, 6));
            try {
                $statement = $this->pdo->prepare('INSERT INTO classrooms (teacher_key, teacher_user_id, code, name) VALUES (?, ?, ?, ?)');
                $statement->execute([$teacherKey, isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null, $code, $name]);
                return (int) $this->pdo->lastInsertId();
            } catch (\PDOException $exception) {
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }
        throw new \RuntimeException('Classroom ID unik gagal dibuat.');
    }

    /** @return array<string,mixed> */
    public function requireOwned(int $id, string $teacherKey): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM classrooms WHERE id = ? AND (teacher_key = ? OR (teacher_user_id IS NOT NULL AND teacher_user_id = ?))');
        $statement->execute([$id, $teacherKey, isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0]);
        $classroom = $statement->fetch();
        if (!$classroom) {
            throw new \RuntimeException('Classroom tidak ditemukan.');
        }
        return $classroom;
    }

    /** @return array<string,mixed>|false */
    public function findActiveByCode(string $code): array|false
    {
        $statement = $this->pdo->prepare("SELECT * FROM classrooms WHERE code = ? AND status = 'active' AND join_open = 1");
        $statement->execute([strtoupper(trim($code))]);
        return $statement->fetch();
    }
}
