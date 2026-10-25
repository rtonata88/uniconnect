<?php

namespace App\Http\Controllers;

use App\Events\GradesReleased;
use App\Http\Resources\GradeItemResource;
use App\Http\Resources\UserResource;
use App\Models\Course;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Support\Audit;
use App\Support\Lms\Gradebook;
use App\Support\StaleWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Gradebook
 */
class GradebookController extends Controller
{
    public function __construct(private Gradebook $gradebook) {}

    private static function gradeJson(?Grade $g): ?array
    {
        return $g ? ['id' => $g->id, 'score' => $g->score, 'max_score' => $g->max_score, 'comment' => $g->comment, 'updated_at' => $g->updated_at?->toIso8601ZuluString('microsecond')] : null;
    }

    /**
     * Gradebook grid (LMS-10, lecturer)
     *
     * Students × grade items with the weighted total (percent).
     */
    public function show(Course $course): JsonResponse
    {
        Gate::authorize('teach', $course);
        $grid = $this->gradebook->grid($course);

        return response()->json([
            'items' => GradeItemResource::collection($grid['items']),
            'rows' => $grid['rows']->map(fn ($r) => [
                'student' => new UserResource($r['student']),
                'grades' => $grid['items']->mapWithKeys(fn ($item) => [$item->id => self::gradeJson($r['grades']->get($item->id))]),
                'weighted_total' => $r['weighted_total'],
            ])->values(),
            'weights_total' => (float) $grid['items']->sum('weight_percent'),
        ]);
    }

    /**
     * Export the gradebook as CSV (LMS-10)
     */
    public function export(Course $course): StreamedResponse
    {
        Gate::authorize('teach', $course);
        $grid = $this->gradebook->grid($course);
        $file = "{$course->code}-gradebook-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($grid) {
            $out = fopen('php://output', 'w');
            $header = ['Student', 'Student number', 'Email'];
            foreach ($grid['items'] as $item) {
                $header[] = "{$item->title} (/{$item->max_score}, {$item->weight_percent}%)";
            }
            $header[] = 'Weighted total %';
            fputcsv($out, $header);
            foreach ($grid['rows'] as $row) {
                $line = [$row['student']->name, $row['student']->student_number, $row['student']->email];
                foreach ($grid['items'] as $item) {
                    $line[] = $row['grades']->get($item->id)?->score;
                }
                $line[] = $row['weighted_total'];
                // neutralise spreadsheet formula injection
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $line));
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Add a manual grade item (e.g. participation)
     */
    public function storeItem(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('teach', $course);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'max_score' => ['required', 'numeric', 'min:1', 'max:1000'],
            'weight_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);
        $item = $course->gradeItems()->create([...$data, 'source_type' => 'manual']);

        return (new GradeItemResource($item))->response()->setStatusCode(201);
    }

    /**
     * Update a grade item (weight, title of manual items)
     */
    public function updateItem(Request $request, GradeItem $item): GradeItemResource
    {
        Gate::authorize('manage', $item);
        $data = $request->validate([
            'weight_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'title' => ['sometimes', 'string', 'max:200'],
            'max_score' => ['sometimes', 'numeric', 'min:1', 'max:1000'],
            'updated_at' => ['nullable', 'date'],
        ]);
        StaleWrite::check($item, $data['updated_at'] ?? null);
        if ($item->source_type !== 'manual') {
            unset($data['title'], $data['max_score']); // follow the assignment / quiz
        }
        $item->fill(collect($data)->except('updated_at')->all())->save();

        return new GradeItemResource($item);
    }

    /**
     * Set a grade on a manual item
     */
    public function setGrade(Request $request, GradeItem $item): JsonResponse
    {
        Gate::authorize('manage', $item);
        abort_unless($item->source_type === 'manual', 422, 'Assignment and quiz grades are set by grading the submission or attempt.');
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:'.$item->max_score],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($item->course->students()->whereKey($data['student_id'])->exists(), 422, 'Student is not enrolled.');
        $grade = Grade::withTrashed()->firstOrNew(['grade_item_id' => $item->id, 'student_id' => $data['student_id']]);
        $old = $grade->score;
        $grade->forceFill(['score' => $data['score'], 'max_score' => $item->max_score, 'comment' => $data['comment'] ?? null, 'graded_by' => $request->user()->id, 'deleted_at' => null])->save();
        Audit::log('grade.changed', $grade, ['from' => $old, 'to' => $grade->score, 'grade_item_id' => $item->id]);

        return response()->json(['data' => self::gradeJson($grade)]);
    }

    /**
     * Release grades (LMS-10)
     *
     * Makes the item's grades visible to students and notifies them. `release=false` hides them again.
     */
    public function release(Request $request, GradeItem $item): GradeItemResource
    {
        Gate::authorize('manage', $item);
        $release = $request->boolean('release', true);
        $item->released_at = $release ? ($item->released_at ?? now()) : null;
        $item->save();
        // released grades must reach students through delta sync
        $item->grades()->touch();
        Audit::log($release ? 'grade.released' : 'grade.unreleased', $item, ['course_id' => $item->course_id, 'title' => $item->title]);
        if ($release) {
            event(new GradesReleased($item));
        }

        return new GradeItemResource($item);
    }

    /**
     * My grades (LMS-10, student)
     *
     * Only released items, with the weighted total over them.
     */
    public function mine(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('view', $course);
        $data = $this->gradebook->forStudent($course, $request->user()->id);

        return response()->json([
            'items' => $data['items']->map(fn ($item) => [
                ...(new GradeItemResource($item))->toArray($request),
                'grade' => self::gradeJson($data['grades']->get($item->id)),
            ]),
            'weighted_total' => $data['weighted_total'],
        ]);
    }
}
