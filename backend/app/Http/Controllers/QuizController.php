<?php

namespace App\Http\Controllers;

use App\Events\QuizPublished;
use App\Http\Resources\QuizResource;
use App\Models\Course;
use App\Models\Quiz;
use App\Support\StaleWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Quizzes
 */
class QuizController extends Controller
{
    private function rules(bool $create): array
    {
        $s = $create ? 'required' : 'sometimes';

        return [
            'title' => [$s, 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'publish' => ['sometimes', 'boolean'],
            'questions' => [$s, 'array', 'min:1', 'max:200'],
            'questions.*.type' => ['required', Rule::in(['mcq', 'true_false', 'short_answer'])],
            'questions.*.prompt' => ['required', 'string', 'max:5000'],
            'questions.*.points' => ['required', 'numeric', 'min:0', 'max:1000'],
            'questions.*.correct_text' => ['nullable', 'string', 'max:255'],
            'questions.*.options' => ['array', 'max:10'],
            'questions.*.options.*.text' => ['required', 'string', 'max:500'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
        ];
    }

    /** Every choice question needs at least two options and exactly one correct option. */
    private function validateQuestions(array $questions): void
    {
        $errors = [];
        foreach ($questions as $i => $q) {
            if ($q['type'] === 'short_answer') {
                continue;
            }
            $options = $q['options'] ?? [];
            if ($q['type'] === 'true_false' && count($options) === 0) {
                continue; // generated below
            }
            if (count($options) < 2) {
                $errors["questions.{$i}.options"] = 'Add at least two options.';
            } elseif (collect($options)->where('is_correct', true)->count() !== 1) {
                $errors["questions.{$i}.options"] = 'Mark exactly one option as correct.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function saveQuestions(Quiz $quiz, array $questions): void
    {
        $quiz->questions()->delete();
        foreach (array_values($questions) as $pos => $q) {
            $question = $quiz->questions()->create([
                'type' => $q['type'],
                'prompt' => $q['prompt'],
                'points' => $q['points'],
                'position' => $pos,
                'correct_text' => $q['type'] === 'short_answer' ? ($q['correct_text'] ?? null) : null,
            ]);
            $options = $q['options'] ?? [];
            if ($q['type'] === 'true_false' && ! $options) {
                $truth = filter_var($q['correct_text'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
                $options = [['text' => 'True', 'is_correct' => $truth], ['text' => 'False', 'is_correct' => ! $truth]];
            }
            if ($q['type'] !== 'short_answer') {
                foreach (array_values($options) as $oPos => $o) {
                    $question->options()->create(['text' => $o['text'], 'is_correct' => $o['is_correct'], 'position' => $oPos]);
                }
            }
        }
        $quiz->unsetRelation('questions');
        $quiz->syncGradeItem();
    }

    /**
     * List quizzes
     *
     * Students see published quizzes with their own attempts.
     */
    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        Gate::authorize('view', $course);
        $lecturer = $course->isLecturer($request->user());
        $quizzes = $course->quizzes()->with('gradeItem', 'questions')
            ->when($lecturer, fn ($q) => $q->withCount('attempts'))
            ->unless($lecturer, fn ($q) => $q->published()->with(['myAttempts' => fn ($a) => $a->with('quiz.gradeItem')]))
            ->orderBy('opens_at')->orderBy('id')->get();

        return QuizResource::collection($quizzes);
    }

    /**
     * Create a quiz (LMS-8)
     *
     * Questions are sent in the same payload. True/false questions can omit options
     * (`correct_text` "true" or "false" decides the right answer).
     */
    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('teach', $course);
        $data = $request->validate($this->rules(true));
        $this->validateQuestions($data['questions']);

        $quiz = DB::transaction(function () use ($course, $data, $request) {
            $quiz = $course->quizzes()->create([
                ...collect($data)->except(['questions', 'publish'])->all(),
                'created_by' => $request->user()->id,
                'published_at' => ($data['publish'] ?? true) ? now() : null,
            ]);
            $this->saveQuestions($quiz, $data['questions']);

            return $quiz;
        });
        if ($quiz->isPublished()) {
            QuizPublished::dispatch($quiz);
        }

        return (new QuizResource($quiz->load('questions.options', 'gradeItem')))->withAnswers()->response()->setStatusCode(201);
    }

    /**
     * Quiz details
     *
     * Lecturers get the questions with answers; students get the summary and their attempts.
     */
    public function show(Request $request, Quiz $quiz): QuizResource
    {
        Gate::authorize('view', $quiz);
        $lecturer = $quiz->course->isLecturer($request->user());
        $quiz->load('questions.options', 'gradeItem');
        if ($lecturer) {
            $quiz->loadCount('attempts');
        } else {
            $quiz->load(['myAttempts' => fn ($a) => $a->with('quiz.gradeItem')]);
        }

        return (new QuizResource($quiz))->withAnswers($lecturer);
    }

    /**
     * Update a quiz
     *
     * Questions can only be replaced while nobody has started the quiz.
     */
    public function update(Request $request, Quiz $quiz): QuizResource
    {
        Gate::authorize('update', $quiz);
        $data = $request->validate([...$this->rules(false), 'updated_at' => ['nullable', 'date']]);
        StaleWrite::check($quiz, $data['updated_at'] ?? null);
        if (isset($data['questions'])) {
            if ($quiz->attempts()->exists()) {
                abort(409, 'Students have already started this quiz, so its questions can no longer change.');
            }
            $this->validateQuestions($data['questions']);
        }

        $wasPublished = $quiz->isPublished();
        DB::transaction(function () use ($quiz, $data) {
            if (array_key_exists('publish', $data)) {
                $quiz->published_at = $data['publish'] ? ($quiz->published_at ?? now()) : null;
            }
            $quiz->fill(collect($data)->except(['questions', 'publish', 'updated_at'])->all())->save();
            if (isset($data['questions'])) {
                $this->saveQuestions($quiz, $data['questions']);
            }
        });
        if (! $wasPublished && $quiz->isPublished()) {
            QuizPublished::dispatch($quiz);
        }

        return (new QuizResource($quiz->load('questions.options', 'gradeItem')))->withAnswers();
    }

    /**
     * Delete a quiz
     */
    public function destroy(Quiz $quiz): JsonResponse
    {
        Gate::authorize('delete', $quiz);
        $quiz->delete();

        return response()->json(['message' => 'Quiz deleted.']);
    }
}
