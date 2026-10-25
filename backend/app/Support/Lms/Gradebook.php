<?php

namespace App\Support\Lms;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use Illuminate\Support\Collection;

/**
 * LMS-10 gradebook. Each assignment and quiz owns a grade item; manual items can be added.
 * Grade rows mirror the source: final assignment score (after late penalty) and the best
 * submitted quiz attempt. Students only ever see released items.
 */
class Gradebook
{
    public function syncSubmission(Submission $submission): void
    {
        $item = $submission->assignment->gradeItem ?? $submission->assignment->syncGradeItem();
        Grade::withTrashed()->updateOrCreate(
            ['grade_item_id' => $item->id, 'student_id' => $submission->student_id],
            [
                'score' => $submission->finalScore(),
                'max_score' => $item->max_score,
                'comment' => $submission->feedback,
                'graded_by' => $submission->graded_by,
                'deleted_at' => null,
            ],
        );
    }

    public function syncQuiz(Quiz $quiz, int $studentId): void
    {
        $item = $quiz->gradeItem ?? $quiz->syncGradeItem();
        $best = QuizAttempt::where('quiz_id', $quiz->id)->where('student_id', $studentId)
            ->whereNotNull('submitted_at')->orderByDesc('score')->first();
        if (! $best) {
            return;
        }
        Grade::withTrashed()->updateOrCreate(
            ['grade_item_id' => $item->id, 'student_id' => $studentId],
            [
                'score' => $best->score,
                'max_score' => $best->max_score ?: $item->max_score,
                'comment' => $best->needs_manual_grading ? 'Some answers are awaiting manual grading.' : null,
                'deleted_at' => null,
            ],
        );
    }

    /**
     * Weighted total in percent. Items without a grade are skipped. If no weights are set,
     * every graded item counts equally. Returns null when nothing is graded.
     *
     * @param  Collection<int, GradeItem>  $items
     * @param  Collection<int, Grade>  $grades  keyed by grade_item_id
     */
    public static function weightedTotal(Collection $items, Collection $grades): ?float
    {
        $parts = $items->map(function (GradeItem $item) use ($grades) {
            $grade = $grades->get($item->id);
            if (! $grade || $grade->score === null || ! $grade->max_score) {
                return null;
            }

            return ['pct' => $grade->score / $grade->max_score * 100, 'w' => $item->weight_percent];
        })->filter();

        if ($parts->isEmpty()) {
            return null;
        }
        $weightSum = $parts->sum('w');
        if ($weightSum <= 0) {
            return round($parts->avg('pct'), 2);
        }

        return round($parts->sum(fn ($p) => $p['pct'] * $p['w']) / $weightSum, 2);
    }

    /** Lecturer grid: students × items. */
    public function grid(Course $course): array
    {
        $items = $course->gradeItems()->orderBy('created_at')->orderBy('id')->get();
        $students = $course->students()->orderBy('name')->get();
        $grades = Grade::whereIn('grade_item_id', $items->pluck('id'))->get()->groupBy('student_id');

        return [
            'items' => $items,
            'rows' => $students->map(function ($student) use ($items, $grades) {
                $mine = ($grades->get($student->id) ?? collect())->keyBy('grade_item_id');

                return [
                    'student' => $student,
                    'grades' => $mine,
                    'weighted_total' => self::weightedTotal($items, $mine),
                ];
            }),
        ];
    }

    /** Released items and the student's grades. */
    public function forStudent(Course $course, int $studentId): array
    {
        $items = $course->gradeItems()->whereNotNull('released_at')->orderBy('created_at')->orderBy('id')->get();
        $grades = Grade::whereIn('grade_item_id', $items->pluck('id'))->where('student_id', $studentId)->get()->keyBy('grade_item_id');

        return ['items' => $items, 'grades' => $grades, 'weighted_total' => self::weightedTotal($items, $grades)];
    }

    public static function sourceUrl(GradeItem $item): ?string
    {
        return match ($item->source_type) {
            'assignment' => "/assignments/{$item->source_id}",
            'quiz' => "/quizzes/{$item->source_id}",
            default => null,
        };
    }

    public function itemFor(Assignment|Quiz $source): GradeItem
    {
        return $source->gradeItem ?? $source->syncGradeItem();
    }
}
