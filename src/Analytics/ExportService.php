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
        $m = (new AnalyticsService($this->pdo))->classroom($id);
        $stmt = $this->pdo->prepare("SELECT name FROM classrooms WHERE id=?");
        $stmt->execute([$id]);
        $classroomName = $stmt->fetchColumn() ?: 'Classroom #' . $id;

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");

        // Title Header
        fputcsv($stream, ['==================================================']);
        fputcsv($stream, ['ENGLAI CLASSROOM ANALYTICS REPORT']);
        fputcsv($stream, ['==================================================']);
        fputcsv($stream, ['Classroom Name', $classroomName]);
        fputcsv($stream, ['Classroom ID', $id]);
        fputcsv($stream, ['Exported By', $actor]);
        fputcsv($stream, ['Export Date', date('Y-m-d H:i:s')]);
        fputcsv($stream, []); // Empty row

        // Section 1: Overview
        fputcsv($stream, ['CLASSROOM OVERVIEW METRICS']);
        fputcsv($stream, ['Metric', 'Value']);
        $metrics = [
            'Total Students' => $m['students'],
            'Active Students (30d)' => $m['active_students'],
            'Inactive Students' => $m['inactive_students'],
            'Self Learning Attempts' => $m['self_learning_attempts'],
            'Live Quiz Sessions' => $m['live_quiz_sessions'],
            'Self Learning Average' => $m['self_learning_average'] . '%',
            'Live Quiz Average' => $m['live_quiz_average'] . '%',
            'Completion Rate' => $m['completion_rate'] . '%',
            'Classroom Level' => ucfirst($m['classroom_level'])
        ];
        foreach ($metrics as $k => $v) {
            fputcsv($stream, [self::safeCell($k), self::safeCell((string)$v)]);
        }
        fputcsv($stream, []); // Empty row

        // Section 2: Skill Performance
        fputcsv($stream, ['SKILL PERFORMANCE']);
        fputcsv($stream, ['Skill', 'Attempts', 'Average Score', 'Completion Rate']);
        foreach ($m['skills'] as $skill => $v) {
            fputcsv($stream, [
                self::safeCell(ucfirst($skill)),
                self::safeCell((string)($v['attempts'] ?? 0)),
                self::safeCell(($v['average'] ?? 0) . '%'),
                self::safeCell(($v['completion'] ?? 0) . '%')
            ]);
        }
        fputcsv($stream, []); // Empty row

        // Section 3: Competencies
        fputcsv($stream, ['COMPETENCY MASTERY']);
        fputcsv($stream, ['Competency', 'Activities', 'Students Checked', 'Attempts', 'Average Score', 'Status']);
        foreach ($m['competencies'] as $c) {
            fputcsv($stream, [
                self::safeCell($c['competency']),
                self::safeCell((string)$c['items']),
                self::safeCell((string)$c['students']),
                self::safeCell((string)$c['attempts']),
                self::safeCell(round((float)$c['average'], 1) . '%'),
                self::safeCell($c['status'])
            ]);
        }
        fputcsv($stream, []); // Empty row

        // Section 4: Student Roster & Gradebook
        fputcsv($stream, ['STUDENT GRADEBOOK']);
        fputcsv($stream, ['Student Name', 'Email / Username', 'Completed Exercises', 'Average Score']);
        $q = $this->pdo->prepare("
            SELECT m.id, m.display_name, m.user_id,
                (SELECT COUNT(*) FROM learning_attempts a WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed') as completed_attempts,
                (SELECT ROUND(AVG(a.score),1) FROM learning_attempts a WHERE a.member_id=m.id AND a.classroom_id=? AND a.status='completed') as avg_score
            FROM classroom_members m
            WHERE m.classroom_id=?
            ORDER BY avg_score DESC, m.display_name ASC
        ");
        $q->execute([$id, $id, $id]);
        $students = $q->fetchAll();
        foreach ($students as $s) {
            fputcsv($stream, [
                self::safeCell($s['display_name'] ?: 'Joined Student'),
                self::safeCell('Member #' . $s['user_id']),
                self::safeCell((string)$s['completed_attempts']),
                self::safeCell($s['avg_score'] !== null ? $s['avg_score'] . '%' : '—')
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        $this->record($id, 'classroom_csv', $actor, 'englai-classroom-' . $id . '-' . date('Ymd') . '.csv');
        return $csv;
    }

    public function studentCsv(int $classroom, int $member, string $actor): string {
        $m = (new AnalyticsService($this->pdo))->student($classroom, $member);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");

        // Title Header
        fputcsv($stream, ['==================================================']);
        fputcsv($stream, ['ENGLAI STUDENT PERFORMANCE REPORT']);
        fputcsv($stream, ['==================================================']);
        fputcsv($stream, ['Student Name', $m['member']['display_name'] ?: 'Student #' . $member]);
        fputcsv($stream, ['Member ID', 'Member #' . ($m['member']['user_id'] ?? $member)]);
        fputcsv($stream, ['Classroom ID', $classroom]);
        fputcsv($stream, ['Exported By', $actor]);
        fputcsv($stream, ['Export Date', date('Y-m-d H:i:s')]);
        fputcsv($stream, []); // Empty row

        // Section 1: Profile Overview
        fputcsv($stream, ['STUDENT OVERVIEW']);
        fputcsv($stream, ['Metric', 'Value']);
        fputcsv($stream, ['Total Completed Exercises', self::safeCell((string)$m['completed_activities'])]);
        fputcsv($stream, ['Self Learning Average Score', self::safeCell($m['self_learning_average'] . '%')]);
        fputcsv($stream, ['Strongest Skill', self::safeCell(ucfirst($m['strongest_skill'] ?: 'insufficient data'))]);
        fputcsv($stream, ['Need to Improve', self::safeCell(ucfirst($m['weakest_skill'] ?: 'insufficient data'))]);
        fputcsv($stream, []); // Empty row

        // Section 2: Skill performance
        fputcsv($stream, ['SKILL COMPETENCY BREAKDOWN']);
        fputcsv($stream, ['Skill', 'Attempts', 'Average Score']);
        if (!$m['skills']) {
            fputcsv($stream, ['No data available', '—', '—']);
        } else {
            foreach ($m['skills'] as $skill => $v) {
                fputcsv($stream, [
                    self::safeCell(ucfirst($skill)),
                    self::safeCell((string)$v['attempts']),
                    self::safeCell($v['average_score'] . '%')
                ]);
            }
        }
        fputcsv($stream, []); // Empty row

        // Section 3: Recent Activity Log
        fputcsv($stream, ['RECENT ACTIVITIES (LAST 20)']);
        fputcsv($stream, ['Activity Title', 'Skill', 'Level', 'Score', 'Date Completed']);
        if (!$m['recent']) {
            fputcsv($stream, ['No completed activities yet', '—', '—', '—', '—']);
        } else {
            foreach ($m['recent'] as $r) {
                fputcsv($stream, [
                    self::safeCell($r['title']),
                    self::safeCell(ucfirst($r['skill'])),
                    self::safeCell(ucfirst($r['level'])),
                    self::safeCell($r['score'] . '/100'),
                    self::safeCell($r['completed_at'])
                ]);
            }
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
