<?php

namespace App\Http\Controllers;

use App\Http\Resources\AttemptResource;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Support\Lms\QuizGrader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * @group Quiz attempts
 */
class QuizAttemptController extends Controller
{
    private function present(QuizAttempt $attempt): AttemptResource
    {
        return new AttemptResource($attempt->load('quiz.questions.options', 'quiz.gradeItem', 'answers'));
    }

    /**
     * Start (or resume) an attempt (LMS-9)
     *
     * Returns the questions (without answers), the server-side deadline and the server time
     * so the client timer is not affected by a wrong device clock. Resumes an attempt that is
     * still in progress.
     */
    public function start(Request $request, Quiz $quiz, QuizGrader $grader): JsonResponse
    {
        Gate::authorize('view', $quiz);
        Gate::authorize('submit', $quiz->course);
        $user = $request->user();

        $attempt = DB::transaction(function () use ($quiz, $user, $grader) {
            $existing = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $user->id)->lockForUpdate()->get();
            $open = $existing->firstWhere('submitted_at', null);
            if ($open && $open->isExpired()) {
                $grader->submit($open, auto: true);
                $open = null;
            }
            if ($open) {
                return $open;
            }
            abort_unless($quiz->isOpen(), 422, 'This quiz is not open.');
            abort_if($existing->count() >= $quiz->max_attempts, 422, 'You have used all your attempts.');

            $ids = $quiz->questions()->pluck('id')->all();
            if ($quiz->shuffle_questions) {
                shuffle($ids);
            }
            $deadline = $quiz->time_limit_minutes ? now()->addMinutes($quiz->time_limit_minutes) : null;
            if ($quiz->closes_at && (! $deadline || $quiz->closes_at->lessThan($deadline))) {
                $deadline = $quiz->closes_at;
            }

            return QuizAttempt::create([
                'quiz_id' => $quiz->id,
                'student_id' => $user->id,
                'started_at' => now(),
                'deadline_at' => $deadline,
                'question_order' => $ids,
                'max_score' => $quiz->maxScore(),
            ]);
        });

        return $this->present($attempt)->response()->setStatusCode($attempt->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Autosave answers
     *
     * @bodyParam answers object[] required Example: [{"question_id": 1, "option_id": 3}, {"question_id": 2, "text_answer": "TCP"}]
     */
    public function saveAnswers(Request $request, QuizAttempt $attempt): JsonResponse
    {
        Gate::authorize('answer', $attempt);
        $data = $request->validate([
            'answers' => ['required', 'array', 'max:200'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_id' => ['nullable', 'integer'],
            'answers.*.text_answer' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! $attempt->acceptsAnswers()) {
            return response()->json(['message' => $attempt->isSubmitted() ? 'This attempt was already submitted.' : 'Time is up.'], 409);
        }
        $this->store($attempt, $data['answers']);

        return response()->json(['message' => 'Saved.', 'saved_at' => now()->toIso8601ZuluString()]);
    }

    /**
     * Submit an attempt (LMS-9)
     *
     * Optional final answers are saved first (if still in time), then MCQ/true-false and
     * exact-match short answers are graded automatically.
     */
    public function submit(Request $request, QuizAttempt $attempt, QuizGrader $grader): AttemptResource
    {
        Gate::authorize('answer', $attempt);
        $data = $request->validate([
            'answers' => ['sometimes', 'array', 'max:200'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_id' => ['nullable', 'integer'],
            'answers.*.text_answer' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! empty($data['answers']) && $attempt->acceptsAnswers()) {
            $this->store($attempt, $data['answers']);
        }
        $grader->submit($attempt, auto: $attempt->isExpired());

        return $this->present($attempt->refresh());
    }

    /**
     * Attempt details
     */
    public function show(QuizAttempt $attempt): AttemptResource
    {
        Gate::authorize('view', $attempt);

        return $this->present($attempt);
    }

    /**
     * All attempts of a quiz (lecturer)
     */
    public function index(Quiz $quiz): AnonymousResourceCollection
    {
        Gate::authorize('teach', $quiz->course);
        $attempts = $quiz->attempts()->with('student', 'quiz.gradeItem')->latest('started_at')->get();

        return AttemptResource::collection($attempts);
    }

    /**
     * Grade short answers manually (lecturer)
     *
     * @bodyParam points object required question_id => points. Example: {"12": 2}
     */
    public function grade(Request $request, QuizAttempt $attempt, QuizGrader $grader): AttemptResource
    {
        Gate::authorize('grade', $attempt);
        $data = $request->validate(['points' => ['required', 'array'], 'points.*' => ['numeric', 'min:0']]);
        abort_unless($attempt->isSubmitted(), 409, 'The attempt has not been submitted yet.');
        $old = $attempt->score;
        $grader->gradeManually($attempt, $data['points']);
        \App\Support\Audit::log('grade.changed', $attempt, ['from' => $old, 'to' => $attempt->score, 'quiz_id' => $attempt->quiz_id]);

        return $this->present($attempt->refresh());
    }

    private function store(QuizAttempt $attempt, array $answers): void
    {
        $questions = $attempt->quiz->questions()->with('options')->get()->keyBy('id');
        foreach ($answers as $a) {
            $question = $questions->get($a['question_id']);
            if (! $question) {
                continue;
            }
            $optionId = $a['option_id'] ?? null;
            if ($optionId !== null && ! $question->options->contains('id', $optionId)) {
                $optionId = null; // option from another question
            }
            QuizAnswer::updateOrCreate(
                ['attempt_id' => $attempt->id, 'question_id' => $question->id],
                $question->type === 'short_answer'
                    ? ['text_answer' => $a['text_answer'] ?? null, 'option_id' => null]
                    : ['option_id' => $optionId, 'text_answer' => null],
            );
        }
    }
}
