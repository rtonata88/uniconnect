<?php

use App\Events\AssignmentPublished;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\Event;

it('lecturers edit, publish and delete announcements; 409 on stale edits', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $a = Announcement::factory()->for($course)->draft()->create(['author_id' => $lecturer->id]);
    $this->actingAs($student)->getJson("/api/courses/{$course->id}/announcements")->assertJsonCount(0, 'data');

    $this->actingAs($lecturer)->patchJson("/api/announcements/{$a->id}", ['title' => 'Updated', 'publish' => true])
        ->assertOk()->assertJsonPath('data.title', 'Updated')->assertJsonPath('data.published_at', fn ($v) => $v !== null);
    $this->actingAs($student)->getJson("/api/courses/{$course->id}/announcements")->assertJsonCount(1, 'data');

    $this->actingAs($lecturer)->patchJson("/api/announcements/{$a->id}", ['title' => 'x', 'updated_at' => now()->subYear()->toIso8601String()])->assertStatus(409);
    $this->actingAs($student)->patchJson("/api/announcements/{$a->id}", ['title' => 'hack'])->assertForbidden();
    $this->actingAs($lecturer)->deleteJson("/api/announcements/{$a->id}")->assertOk();
    $this->actingAs($student)->getJson("/api/courses/{$course->id}/announcements")->assertJsonCount(0, 'data');
});

it('lecturers update assignments: publishing notifies, a new deadline re-arms reminders', function () {
    Event::fake([AssignmentPublished::class]);
    [$course, $lecturer, $student] = courseWithMembers();
    $a = Assignment::factory()->for($course)->draft()->create(['reminded_24h_at' => now()]);
    $this->actingAs($student)->getJson("/api/assignments/{$a->id}")->assertForbidden(); // draft

    $this->actingAs($lecturer)->patchJson("/api/assignments/{$a->id}", ['publish' => true, 'due_at' => now()->addDays(5)->toIso8601String(), 'max_score' => 40])
        ->assertOk()->assertJsonPath('data.max_score', 40);
    Event::assertDispatched(AssignmentPublished::class);
    expect($a->fresh()->reminded_24h_at)->toBeNull()->and($a->gradeItem->fresh()->max_score)->toBe(40.0);

    $this->actingAs($student)->getJson("/api/assignments/{$a->id}")->assertOk()->assertJsonPath('data.my_submission', null);
    $this->actingAs($lecturer)->getJson("/api/assignments/{$a->id}")->assertOk()->assertJsonPath('data.submissions_count', 0);
    $this->actingAs($lecturer)->deleteJson("/api/assignments/{$a->id}")->assertOk();
    expect($a->gradeItem()->first())->toBeNull(); // the gradebook column goes with it
});

it('lecturers update quiz settings and questions until someone starts, then delete', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $quiz = Quiz::factory()->for($course)->withQuestions()->create(['published_at' => null]);
    $this->actingAs($lecturer)->patchJson("/api/quizzes/{$quiz->id}", [
        'title' => 'Renamed', 'publish' => true, 'time_limit_minutes' => 30,
        'questions' => [['type' => 'true_false', 'prompt' => 'Yes?', 'points' => 2, 'correct_text' => 'false']],
    ])->assertOk()->assertJsonPath('data.title', 'Renamed')->assertJsonPath('data.questions.0.options.1.is_correct', true);
    expect($quiz->gradeItem->fresh()->max_score)->toBe(2.0);

    $this->actingAs($student)->getJson("/api/courses/{$course->id}/quizzes")->assertJsonCount(1, 'data');
    $this->actingAs($student)->deleteJson("/api/quizzes/{$quiz->id}")->assertForbidden();
    $this->actingAs($lecturer)->deleteJson("/api/quizzes/{$quiz->id}")->assertOk();
    $this->actingAs($student)->getJson("/api/courses/{$course->id}/quizzes")->assertJsonCount(0, 'data');
});

it('admins update courses and the course chat title follows', function () {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create(['code' => 'OLD1']);
    $this->actingAs($admin)->patchJson("/api/admin/courses/{$course->id}", ['code' => 'new2', 'title' => 'New title'])
        ->assertOk()->assertJsonPath('data.code', 'NEW2');
    expect($course->conversation->fresh()->title)->toBe('NEW2 New title');
    $this->actingAs($admin)->getJson('/api/admin/courses?search=NEW')->assertOk()->assertJsonCount(1, 'data');
});

it('serves the runtime client config without auth', function () {
    $this->getJson('/api/config')->assertOk()->assertJsonStructure(['reverb' => ['key'], 'vapid_public_key', 'upload_max_bytes']);
});

it('the demo seeder creates the PRD §15 data set', function () {
    fakeStorage();
    config(['broadcasting.default' => 'null']);
    $this->seed(\Database\Seeders\DatabaseSeeder::class);
    expect(User::where('email', 'admin@uniconnect.test')->value('role')->value)->toBe('admin')
        ->and(User::where('role', 'student')->count())->toBe(10)
        ->and(Course::pluck('code')->sort()->values()->all())->toBe(['DB903', 'DMS901', 'SE902'])
        ->and(Course::first()->announcements()->count())->toBe(2)
        ->and(Course::first()->assignments()->count())->toBe(2)
        ->and(Course::first()->quizzes()->count())->toBe(1)
        ->and(Course::first()->conversation->messages()->count())->toBeGreaterThan(0)
        ->and(\App\Models\Conversation::where('type', 'group')->count())->toBe(1);
    $pdf = \Illuminate\Support\Facades\Storage::disk('s3')->get(\App\Models\CourseMaterial::first()->attachment->path);
    expect($pdf)->toStartWith('%PDF-1.4');
});
