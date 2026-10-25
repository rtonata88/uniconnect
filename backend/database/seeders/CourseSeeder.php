<?php

namespace Database\Seeders;

use App\Models\Attachment;
use App\Models\Course;
use App\Models\User;
use App\Support\Enrolment;
use App\Support\SamplePdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PRD §15: three courses, each with weekly materials (generated PDFs), 2 announcements
 * and 2 assignments (one due in 2 days, one past due).
 */
class CourseSeeder extends Seeder
{
    public const COURSES = [
        'DMS901' => ['Distributed and Mobile Systems', 'mziezo@uniconnect.test', [
            1 => ['Introduction to distributed systems', ['Definitions, goals and transparency.', 'Fallacies of distributed computing.', 'Case study: UniConnect architecture.']],
            2 => ['Communication and RPC', ['Message passing, REST and WebSockets.', 'Idempotency and retries.', 'Pub/sub with Redis.']],
            3 => ['Replication and consistency', ['Primary/replica replication with GTID.', 'Read-your-writes and sticky connections.', 'Eventual consistency on mobile clients.']],
            4 => ['Mobile and offline computing', ['Progressive Web Apps and service workers.', 'Local replicas in IndexedDB.', 'Outbox pattern and delta sync.']],
        ]],
        'SE902' => ['Advanced Software Engineering', 'jshikongo@uniconnect.test', [
            1 => ['Requirements engineering', ['Functional and non-functional requirements.', 'Traceability matrices.']],
            2 => ['Software architecture', ['Architectural styles and views.', 'Architecture decision records.']],
            3 => ['Testing strategies', ['Test pyramid: unit, integration, end-to-end.', 'Load and performance testing with k6.']],
        ]],
        'DB903' => ['Advanced Databases', 'mziezo@uniconnect.test', [
            1 => ['Transactions and isolation', ['ACID properties.', 'Isolation levels and anomalies.']],
            2 => ['Indexing and query optimisation', ['B-trees, covering indexes.', 'Reading EXPLAIN plans.']],
            3 => ['Distributed databases', ['Sharding and partitioning.', 'Replication lag and failover.']],
        ]],
    ];

    public function run(): void
    {
        $enrol = app(Enrolment::class);
        $students = User::where('role', 'student')->orderBy('id')->get();

        foreach (self::COURSES as $code => [$title, $lecturerEmail, $weeks]) {
            $lecturer = User::where('email', $lecturerEmail)->firstOrFail();
            $course = Course::create([
                'code' => $code,
                'title' => $title,
                'description' => "{$title} ({$code}) — Master's programme, IUM. Lectures, labs and weekly readings.",
                'semester' => '2026 Semester 2',
                'created_by' => User::where('role', 'admin')->value('id'),
            ]);
            $enrol->enrol($course, [$lecturer->id], 'lecturer');
            // student1..6 take every course, the rest take DMS901 only (some outsiders for demos)
            $ids = $code === 'DMS901' ? $students->pluck('id')->all() : $students->take(6)->pluck('id')->all();
            $enrol->enrol($course, $ids, 'student');

            foreach ($weeks as $week => [$topic, $points]) {
                $pdf = SamplePdf::make("{$code} Week {$week}: {$topic}", array_merge(
                    ["Course: {$title}", "Lecturer: {$lecturer->name}", ''],
                    array_map(fn ($p) => '* '.$p, $points),
                    ['', 'Reading: see the course page for references.'],
                ));
                $attachment = $this->storeFile($lecturer, "{$code}-week-{$week}.pdf", $pdf);
                $course->materials()->create([
                    'uploaded_by' => $lecturer->id,
                    'title' => "Week {$week}: {$topic}",
                    'description' => 'Lecture notes for week '.$week.'.',
                    'week' => $week,
                    'attachment_id' => $attachment->id,
                    'published_at' => now()->subWeeks(5 - min($week, 4)),
                ]);
            }

            $course->announcements()->create([
                'author_id' => $lecturer->id,
                'title' => 'Welcome to '.$code,
                'body' => "Welcome to {$title}! Lecture notes are under Materials, grouped by week. Use the course chat for questions.",
                'published_at' => now()->subDays(10),
            ]);
            $course->announcements()->create([
                'author_id' => $lecturer->id,
                'title' => 'Lab session moved',
                'body' => 'This week\'s lab moves to Thursday 14:00 in Lab 2. Bring your laptop with the project running.',
                'published_at' => now()->subDays(1),
            ]);

            $course->assignments()->create([
                'created_by' => $lecturer->id,
                'title' => 'Assignment 1: Literature review',
                'instructions' => 'Write a 2-page review of two papers from the reading list. Submit a PDF.',
                'due_at' => now()->subDays(3)->setTime(23, 59),
                'max_score' => 100,
                'allow_late' => true,
                'late_penalty_percent' => 10,
                'published_at' => now()->subDays(20),
            ]);
            $course->assignments()->create([
                'created_by' => $lecturer->id,
                'title' => 'Assignment 2: Design report',
                'instructions' => 'Describe the architecture of your project with at least one deployment and one sequence diagram.',
                'due_at' => now()->addDays(2)->setTime(17, 0),
                'max_score' => 50,
                'allow_late' => false,
                'late_penalty_percent' => 0,
                'published_at' => now()->subDays(7),
            ]);
        }
    }

    private function storeFile(User $owner, string $name, string $contents): Attachment
    {
        $path = 'attachments/seed/'.Str::random(40).'.pdf';
        Storage::put($path, $contents, ['ContentType' => 'application/pdf']);

        return Attachment::create([
            'owner_id' => $owner->id,
            'disk' => config('filesystems.default'),
            'path' => $path,
            'original_name' => $name,
            'mime' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ]);
    }
}
