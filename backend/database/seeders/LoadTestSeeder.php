<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PRD 12.2 load-test data set: 500 students, 10 courses (LT01–LT10, 50 students each plus
 * LT-BIG with 260 students for the WebSocket test) and 1 000 chat messages. Additive and
 * idempotent: it never touches the demo data. php artisan db:seed --class=LoadTestSeeder
 */
class LoadTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('LoadTestSeeder only runs when APP_ENV is local or testing.');
        }
        config(['broadcasting.default' => 'null']);
        // nobody signs in as a load-test user: k6 uses tokens from loadtest:tokens
        $password = Hash::make(Str::random(40));
        $now = now();

        $lecturers = collect(range(1, 5))->map(fn ($i) => User::firstOrCreate(
            ['email' => "lt_lecturer{$i}@uniconnect.test"],
            ['name' => "LT Lecturer {$i}", 'role' => 'lecturer', 'password' => $password, 'is_active' => true],
        ));

        $existing = User::where('email', 'like', 'lt_student%')->pluck('id', 'email');
        $rows = [];
        foreach (range(1, 500) as $i) {
            $email = sprintf('lt_student%03d@uniconnect.test', $i);
            if (! isset($existing[$email])) {
                $rows[] = ['name' => "LT Student {$i}", 'email' => $email, 'role' => 'student', 'student_number' => sprintf('LT%05d', $i),
                    'password' => $password, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            User::insert($chunk);
        }
        $students = User::where('email', 'like', 'lt_student%')->orderBy('email')->pluck('id')->values();

        $courses = collect(range(1, 10))->map(fn ($i) => Course::firstOrCreate(
            ['code' => sprintf('LT%02d', $i)],
            ['title' => "Load Test Course {$i}", 'description' => 'Generated for k6 load tests.', 'semester' => '2026 S2'],
        ));
        $big = Course::firstOrCreate(['code' => 'LTBIG'], ['title' => 'Load Test — large course chat', 'semester' => '2026 S2']);

        foreach ($courses as $idx => $course) {
            $lecturer = $lecturers[$idx % 5];
            $members = $students->slice($idx * 50, 50)->values();
            $this->enrol($course, $lecturer->id, $members->all());
            if ($course->materials()->count() === 0) {
                foreach (range(1, 3) as $w) {
                    $course->announcements()->create(['author_id' => $lecturer->id, 'title' => "Week {$w} notes", 'body' => str_repeat('Load test announcement. ', 10), 'published_at' => $now]);
                    $course->assignments()->create(['created_by' => $lecturer->id, 'title' => "LT assignment {$w}", 'instructions' => 'Load test.', 'due_at' => $now->copy()->addDays(7 * $w), 'max_score' => 100, 'published_at' => $now]);
                }
            }
        }
        $this->enrol($big, $lecturers[0]->id, $students->take(260)->all());

        // 1 000 messages spread over the 10 course chats (100 each)
        $have = Message::whereIn('conversation_id', $courses->map(fn ($c) => $c->conversation->id))->count();
        if ($have < 1000) {
            $msgs = [];
            foreach ($courses as $idx => $course) {
                $members = $students->slice($idx * 50, 50)->values();
                foreach (range(1, 100) as $n) {
                    $at = $now->copy()->subMinutes(1000 - $n);
                    $msgs[] = ['conversation_id' => $course->conversation->id, 'sender_id' => $members[$n % 50], 'client_uuid' => (string) Str::uuid(),
                        'type' => 'text', 'body' => encrypt("Load test message {$n}", false), 'created_at' => $at, 'updated_at' => $at];
                }
            }
            foreach (array_chunk($msgs, 250) as $chunk) {
                Message::insert($chunk);
            }
            foreach ($courses as $course) {
                $last = $course->conversation->messages()->latest('id')->first();
                $course->conversation->update(['last_message_id' => $last->id, 'last_message_at' => $last->created_at]);
            }
        }
        $this->command?->info('Load-test data: '.User::where('email', 'like', 'lt_%')->count().' users, '.(Course::where('code', 'like', 'LT%')->count()).' courses, '.Message::count().' messages total.');
    }

    /** Bulk enrol (and add to the course chat) without per-user events. */
    private function enrol(Course $course, int $lecturerId, array $studentIds): void
    {
        $now = now();
        $pivot = [['course_id' => $course->id, 'user_id' => $lecturerId, 'role' => 'lecturer', 'created_at' => $now, 'updated_at' => $now]];
        foreach ($studentIds as $id) {
            $pivot[] = ['course_id' => $course->id, 'user_id' => $id, 'role' => 'student', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($pivot, 500) as $chunk) {
            DB::table('course_user')->insertOrIgnore($chunk);
        }
        $parts = array_map(fn ($row) => ['conversation_id' => $course->conversation->id, 'user_id' => $row['user_id'],
            'role' => $row['role'] === 'lecturer' ? 'admin' : 'member', 'joined_at' => $now->copy()->subDays(30), 'created_at' => $now, 'updated_at' => $now], $pivot);
        foreach (array_chunk($parts, 500) as $chunk) {
            DB::table('conversation_participants')->insertOrIgnore($chunk);
        }
    }
}
