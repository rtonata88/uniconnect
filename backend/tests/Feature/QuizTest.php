<?php

use App\Models\Grade;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;

function quizFor($course, array $attrs = []): Quiz
{
    return Quiz::factory()->for($course)->withQuestions()->create($attrs)->load('questions.options');
}

/** Correct answers for the factory quiz, except the free-text one. */
function goodAnswers(Quiz $quiz, string $short = 'tcp'): array
{
    [$mcq, $tf, $sa, $free] = $quiz->questions->all();

    return [
        ['question_id' => $mcq->id, 'option_id' => $mcq->options->firstWhere('is_correct', true)->id],
        ['question_id' => $tf->id, 'option_id' => $tf->options->firstWhere('is_correct', true)->id],
        ['question_id' => $sa->id, 'text_answer' => "  {$short} "],
        ['question_id' => $free->id, 'text_answer' => 'Replicas converge eventually.'],
    ];
}

it('LMS-8 lecturer builds a quiz with mcq, true/false and short answer questions', function () {
    [$course, $lecturer] = courseWithMembers();
    $res = $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/quizzes", [
        'title' => 'Week 3 quiz', 'time_limit_minutes' => 15, 'max_attempts' => 2, 'shuffle_questions' => true,
        'opens_at' => now()->subMinute()->toIso8601String(), 'closes_at' => now()->addDay()->toIso8601String(),
        'questions' => [
            ['type' => 'mcq', 'prompt' => 'Pick B', 'points' => 2, 'options' => [['text' => 'A', 'is_correct' => false], ['text' => 'B', 'is_correct' => true]]],
            ['type' => 'true_false', 'prompt' => 'Sky is blue', 'points' => 1, 'correct_text' => 'true'],
            ['type' => 'short_answer', 'prompt' => 'Capital of Namibia', 'points' => 1, 'correct_text' => 'Windhoek'],
        ],
    ])->assertCreated();

    expect($res->json('data.questions'))->toHaveCount(3)
        ->and($res->json('data.questions.1.options'))->toHaveCount(2)
        ->and($res->json('data.max_score'))->toEqual(4);
    // the quiz gets a gradebook column
    expect($course->gradeItems()->where('source_type', 'quiz')->value('max_score'))->toEqual(4.0);
});

it('LMS-8 rejects choice questions without exactly one correct option', function () {
    [$course, $lecturer] = courseWithMembers();
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/quizzes", [
        'title' => 'Bad', 'questions' => [['type' => 'mcq', 'prompt' => 'x', 'points' => 1, 'options' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => true]]]],
    ])->assertStatus(422)->assertJsonValidationErrors('questions.0.options');
});

it('LMS-9 starting an attempt hides the answers and returns a server deadline', function () {
    [$course, , $student] = courseWithMembers();
    $quiz = quizFor($course);
    $res = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->assertCreated();

    expect($res->json('data.questions'))->toHaveCount(4)
        ->and(json_encode($res->json('data.questions')))->not->toContain('is_correct')->not->toContain('correct_text')
        ->and($res->json('data.deadline_at'))->not->toBeNull()
        ->and($res->json('data.server_now'))->not->toBeNull();

    // starting again resumes the same attempt
    $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->assertOk()->assertJsonPath('data.id', $res->json('data.id'));
});

it('LMS-9 auto-grades mcq, true/false and exact-match short answers; free text needs manual grading', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $quiz = quizFor($course);
    $attempt = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('data.id');

    $this->actingAs($student)->putJson("/api/attempts/{$attempt}/answers", ['answers' => goodAnswers($quiz)])->assertOk();
    $this->actingAs($student)->postJson("/api/attempts/{$attempt}/submit")->assertOk()
        ->assertJsonPath('data.needs_manual_grading', true)
        ->assertJsonPath('data.score', null); // hidden from the student until release

    $a = QuizAttempt::find($attempt);
    expect($a->score)->toBe(5.0)->and($a->max_score)->toBe(8.0);

    // lecturer awards the free-text answer
    $free = $quiz->questions[3];
    $this->actingAs($lecturer)->patchJson("/api/attempts/{$attempt}/grade", ['points' => [$free->id => 2.5]])
        ->assertOk()->assertJsonPath('data.score', 7.5)->assertJsonPath('data.needs_manual_grading', false);

    expect(Grade::where('student_id', $student->id)->value('score'))->toBe(7.5);
});

it('LMS-9 short answers are case-insensitive but otherwise exact', function () {
    [$course, , $student] = courseWithMembers();
    $quiz = quizFor($course);
    $attempt = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('data.id');
    $this->actingAs($student)->postJson("/api/attempts/{$attempt}/submit", ['answers' => goodAnswers($quiz, 'tcp/ip')]);
    expect(QuizAttempt::find($attempt)->score)->toBe(3.0);
});

it('LMS-9 enforces the time limit on the server and auto-submits expired attempts', function () {
    [$course, , $student] = courseWithMembers();
    $quiz = quizFor($course, ['time_limit_minutes' => 5]);
    $attempt = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('data.id');
    $this->actingAs($student)->putJson("/api/attempts/{$attempt}/answers", ['answers' => array_slice(goodAnswers($quiz), 0, 1)]);

    $this->travel(6)->minutes();
    $this->actingAs($student)->putJson("/api/attempts/{$attempt}/answers", ['answers' => goodAnswers($quiz)])->assertStatus(409);

    $this->artisan('quizzes:close-expired')->assertSuccessful();
    $a = QuizAttempt::find($attempt);
    expect($a->submitted_at)->not->toBeNull()
        ->and($a->auto_submitted)->toBeTrue()
        ->and($a->score)->toBe(2.0); // only the answer saved in time counts
});

it('LMS-8 limits attempts', function () {
    [$course, , $student] = courseWithMembers();
    $quiz = quizFor($course, ['max_attempts' => 1]);
    $attempt = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('data.id');
    $this->actingAs($student)->postJson("/api/attempts/{$attempt}/submit");
    $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->assertStatus(422);
});

it('LMS-8 quizzes cannot be taken outside their open window', function () {
    [$course, , $student] = courseWithMembers();
    $quiz = quizFor($course, ['opens_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]);
    $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->assertStatus(422);
});

it('SEC-2 lecturers see answers, students do not; students cannot read others attempts', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $quiz = quizFor($course);
    $this->actingAs($lecturer)->getJson("/api/quizzes/{$quiz->id}")->assertJsonPath('data.questions.0.options.1.is_correct', true);
    $this->actingAs($student)->getJson("/api/quizzes/{$quiz->id}")->assertJsonMissingPath('data.questions');

    $attempt = $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts")->json('data.id');
    $other = User::factory()->student()->create();
    $course->members()->attach($other->id, ['role' => 'student']);
    $this->actingAs($other)->getJson("/api/attempts/{$attempt}")->assertForbidden();
    $this->actingAs($other)->putJson("/api/attempts/{$attempt}/answers", ['answers' => []])->assertForbidden();
    $this->actingAs($student)->patchJson("/api/attempts/{$attempt}/grade", ['points' => []])->assertForbidden();
});

it('cannot change questions after students started', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    $quiz = quizFor($course);
    $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/attempts");
    $this->actingAs($lecturer)->patchJson("/api/quizzes/{$quiz->id}", ['questions' => [['type' => 'short_answer', 'prompt' => 'x', 'points' => 1]]])
        ->assertStatus(409);
});

it('the quiz grade item max score follows the questions', function () {
    [$course] = courseWithMembers();
    $quiz = quizFor($course);
    expect($quiz->gradeItem->max_score)->toBe(8.0);
});
