<?php
declare(strict_types=1);
namespace EnglAI\Analytics;
final class ExportService {
    public function __construct(private readonly \PDO $pdo) {}

    public static function safeCell(mixed $value): string {
        $s = (string)$value;
        return preg_match('/^[=+\-@]/', $s) ? "'" . $s : $s;
    }

    public function classroomCsv(int $id, string $actor): string {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

        // Set clean CSV headers with unit indicators
        fputcsv($stream, [
            'Student Name',
            'Member ID',
            'Joined Date',
            'Last Active',
            'Completed Exercises',
            'Average Score (%)',
            'Grade',
            'Reading Avg (%)',
            'Listening Avg (%)',
            'Speaking Avg (%)',
            'Writing Avg (%)'
        ]);

        $q = $this->pdo->prepare("
            SELECT m.id, m.display_name, m.user_id, m.created_at, m.last_seen_at, u.name as user_name, u.email as user_email,
                (SELECT COUNT(*) FROM learning_attempts a WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed') as completed_attempts,
                (SELECT ROUND(AVG(a.score),1) FROM learning_attempts a WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed') as avg_score,
                (SELECT ROUND(AVG(a.score),1) FROM learning_attempts a JOIN learning_activities la ON la.id=a.activity_id WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed' AND la.skill='reading') as reading_avg,
                (SELECT ROUND(AVG(a.score),1) FROM learning_attempts a JOIN learning_activities la ON la.id=a.activity_id WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed' AND la.skill='listening') as listening_avg,
                (SELECT ROUND(AVG(a.score),1) FROM learning_attempts a JOIN learning_activities la ON la.id=a.activity_id WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed' AND la.skill='speaking') as speaking_avg,
                (SELECT ROUND(AVG(a.score),1) FROM learning_attempts a JOIN learning_activities la ON la.id=a.activity_id WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed' AND la.skill='writing') as writing_avg
            FROM classroom_members m
            LEFT JOIN users u ON u.id = m.user_id
            WHERE m.classroom_id=?
            ORDER BY avg_score DESC, m.display_name ASC
        ");
        $q->execute([$id, $id, $id, $id, $id, $id, $id]);
        $students = $q->fetchAll();

        foreach ($students as $s) {
            $avg = $s['avg_score'] !== null ? (float)$s['avg_score'] : null;
            $grade = $avg !== null ? ($avg >= 90 ? 'A' : ($avg >= 80 ? 'B' : ($avg >= 70 ? 'C' : ($avg >= 60 ? 'D' : 'E')))) : '';
            
            $displayName = $s['display_name'] ?: $s['user_name'] ?: $s['user_email'] ?: ('Student #' . ($s['user_id'] ?: $s['id']));

            fputcsv($stream, [
                self::safeCell($displayName),
                self::safeCell('Member #' . ($s['user_id'] ?: $s['id'])),
                self::safeCell(substr((string)$s['created_at'], 0, 10)),
                self::safeCell($s['last_seen_at'] ? substr((string)$s['last_seen_at'], 0, 10) : ''),
                self::safeCell((string)$s['completed_attempts']),
                self::safeCell($avg !== null ? (string)$avg : ''),
                self::safeCell($grade),
                self::safeCell($s['reading_avg'] !== null ? (string)$s['reading_avg'] : ''),
                self::safeCell($s['listening_avg'] !== null ? (string)$s['listening_avg'] : ''),
                self::safeCell($s['speaking_avg'] !== null ? (string)$s['speaking_avg'] : ''),
                self::safeCell($s['writing_avg'] !== null ? (string)$s['writing_avg'] : '')
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $this->record($id, 'classroom_csv', $actor, 'englai-classroom-' . $id . '-' . date('Ymd') . '.csv');
        return $csv;
    }

    public function studentCsv(int $classroom, int $member, string $actor): string {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

        // Set clean CSV headers
        fputcsv($stream, [
            'Activity Title',
            'Skill',
            'Level',
            'Score',
            'Date Completed'
        ]);

        $q = $this->pdo->prepare("
            SELECT l.title, l.skill, l.level, a.score, a.completed_at
            FROM learning_attempts a
            JOIN learning_activities l ON l.id = a.activity_id
            WHERE a.classroom_id = ? AND a.member_id = ? AND a.status = 'completed'
            ORDER BY a.completed_at DESC
        ");
        $q->execute([$classroom, $member]);
        $attempts = $q->fetchAll();

        foreach ($attempts as $r) {
            fputcsv($stream, [
                self::safeCell($r['title']),
                self::safeCell(ucfirst($r['skill'])),
                self::safeCell(ucfirst($r['level'])),
                self::safeCell($r['score'] . '/100'),
                self::safeCell($r['completed_at'])
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $this->record($classroom, 'student_csv', $actor, 'englai-student-' . $member . '-' . date('Ymd') . '.csv', $member);
        return $csv;
    }

    private function record(int $classroom, string $type, string $actor, string $filename, ?int $member = null): void {
        $q = $this->pdo->prepare("INSERT INTO export_jobs(classroom_id,export_type,member_id,requested_by,status,filename) VALUES(?,?,?,?,'completed',?)");
        $q->execute([$classroom, $type, $member, $actor, $filename]);
        (new AuditService($this->pdo))->record($classroom, $actor, 'report.exported', 'export_job', (int)$this->pdo->lastInsertId(), [], ['type' => $type, 'filename' => $filename]);
    }
}
