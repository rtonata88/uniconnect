<?php

namespace App\Support\Lms;

use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Support\Facades\DB;

/**
 * LMS-9 grading rules:
 *  - mcq / true_false: full points for the correct option, 0 otherwise
 *  - short_answer with correct_text: case-insensitive exact match (trimmed, spaces collapsed)
 *  - short_answer without correct_text: left for manual grading
 */
class QuizGrader
{
    public function __construct(private Gradebook $gradebook) {}

    public static function normalise(?string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $text)));
    }

    public function gradeAnswer(QuizQuestion $question, QuizAnswer $answer): void
    {
        if ($question->type === 'short_answer') {
            if ($question->correct_text === null || $question->correct_text === '') {
                $answer->is_correct = null;
                $answer->points_awarded = null;

                return;
            }
            $ok = self::normalise($answer->text_answer) === self::normalise($question->correct_text);
        } else {
            $ok = $answer->option_id !== null
                && $question->options->firstWhere('id', $answer->option_id)?->is_correct === true;
        }
        $answer->is_correct = $ok;
        $answer->points_awarded = $ok ? $question->points : 0;
    }

    /** Submit (or auto-submit) an attempt and compute its score. */
    public function submit(QuizAttempt $attempt, bool $auto = false): QuizAttempt
    {
        return DB::transaction(function () use ($attempt, $auto) {
            $attempt = QuizAttempt::lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->isSubmitted()) {
                return $attempt;
            }
            $questions = $attempt->quiz->questions()->with('options')->get()->keyBy('id');
            $answers = $attempt->answers()->get()->keyBy('question_id');

            foreach ($questions as $question) {
                $answer = $answers->get($question->id) ?? new QuizAnswer(['attempt_id' => $attempt->id, 'question_id' => $question->id]);
                $this->gradeAnswer($question, $answer);
                $answer->save();
                $answers->put($question->id, $answer);
            }

            $attempt->fill([
                'submitted_at' => $attempt->deadline_at && now()->greaterThan($attempt->deadline_at) ? $attempt->deadline_at : now(),
                'auto_submitted' => $auto,
            ]);
            $this->recompute($attempt, $answers, $questions);

            return $attempt;
        });
    }

    /** Manual grading of short answers: [question_id => points]. */
    public function gradeManually(QuizAttempt $attempt, array $points): QuizAttempt
    {
        $questions = $attempt->quiz->questions()->with('options')->get()->keyBy('id');
        $answers = $attempt->answers()->get()->keyBy('question_id');
        foreach ($points as $questionId => $value) {
            $question = $questions->get($questionId);
            $answer = $answers->get($questionId);
            if (! $question || ! $answer) {
                continue;
            }
            $value = max(0, min((float) $value, $question->points));
            $answer->forceFill(['points_awarded' => $value, 'is_correct' => $value >= $question->points])->save();
        }
        $this->recompute($attempt, $answers, $questions);

        return $attempt;
    }

    private function recompute(QuizAttempt $attempt, $answers, $questions): void
    {
        $attempt->max_score = (float) $questions->sum('points');
        $attempt->score = (float) $answers->sum(fn ($a) => $a->points_awarded ?? 0);
        $attempt->needs_manual_grading = $answers->contains(fn ($a) => $a->points_awarded === null);
        $attempt->save();
        $this->gradebook->syncQuiz($attempt->quiz, $attempt->student_id);
    }
}
