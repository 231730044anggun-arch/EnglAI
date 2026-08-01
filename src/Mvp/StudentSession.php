<?php
declare(strict_types=1);

namespace EnglAI\Mvp;

final class StudentSession
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_start();
    }

    /** @return array<string,mixed> */
    public static function requireMember(\PDO $pdo): array
    {
        self::start();
        $memberId = (int) ($_SESSION['student_member_id'] ?? 0);
        $token = (string) ($_SESSION['student_token'] ?? '');
        if ($memberId < 1 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $target = (($_SESSION['user_role'] ?? '') === 'student') ? '/student/account.php' : '/index.php';
            header('Location: ' . $target . '?error=' . rawurlencode('Masukkan Classroom ID untuk melanjutkan.'));
            exit;
        }
        $statement = $pdo->prepare(
            "SELECT m.*, c.name AS classroom_name, c.code AS classroom_code, c.status AS classroom_status
             FROM classroom_members m
             JOIN classrooms c ON c.id = m.classroom_id
             WHERE m.id = ? AND m.session_token = ? AND c.status = 'active'"
        );
        $statement->execute([$memberId, $token]);
        $member = $statement->fetch();
        if (!$member) {
            self::destroy();
            $target = (($_SESSION['user_role'] ?? '') === 'student') ? '/student/account.php' : '/index.php';
            header('Location: ' . $target . '?error=' . rawurlencode('Sesi classroom tidak valid.'));
            exit;
        }
        $pdo->prepare('UPDATE classroom_members SET last_seen_at = NOW() WHERE id = ?')->execute([$memberId]);
        return $member;
    }

    public static function establish(int $memberId, int $classroomId, string $token): void
    {
        self::start();
        $_SESSION['student_member_id'] = $memberId;
        $_SESSION['student_classroom_id'] = $classroomId;
        $_SESSION['student_token'] = $token;
    }

    public static function destroy(): void
    {
        self::start();
        unset($_SESSION['student_member_id']);
        unset($_SESSION['student_classroom_id']);
        unset($_SESSION['student_token']);
    }
}
