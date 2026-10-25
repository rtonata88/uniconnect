<?php

namespace App\Http\Controllers;

use App\Http\Resources\SubmissionResource;
use App\Http\Resources\UserResource;
use App\Models\Assignment;
use App\Models\Submission;
use App\Support\Audit;
use App\Support\Files\AttachmentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * @group Submissions
 */
class SubmissionController extends Controller
{
    /**
     * All submissions (LMS-7, lecturer)
     *
     * One row per enrolled student with status on_time, late or missing.
     */
    public function index(Assignment $assignment): JsonResponse
    {
        Gate::authorize('teach', $assignment->course);
        $subs = $assignment->submissions()->with('attachment', 'assignment')->get()->keyBy('student_id');
        $rows = $assignment->course->students()->orderBy('name')->get()->map(function ($student) use ($subs, $assignment) {
            $sub = $subs->get($student->id);

            return [
                'student' => new UserResource($student),
                'status' => $sub ? ($sub->is_late ? 'late' : 'on_time') : ($assignment->isPastDue() ? 'missing' : 'not_submitted'),
                'submission' => $sub ? new SubmissionResource($sub) : null,
            ];
        });

        return response()->json([
            'data' => $rows,
            'summary' => [
                'on_time' => $rows->where('status', 'on_time')->count(),
                'late' => $rows->where('status', 'late')->count(),
                'missing' => $rows->where('status', 'missing')->count(),
                'not_submitted' => $rows->where('status', 'not_submitted')->count(),
                'graded' => $subs->whereNotNull('graded_at')->count(),
            ],
        ]);
    }

    /**
     * Submit or resubmit (LMS-6, student)
     *
     * Idempotent on `client_uuid`: a retried request returns the stored submission with 200.
     * Resubmission replaces the previous one until the due date. After the due date it is
     * accepted only if the assignment allows late work, and is marked late. Lateness uses the
     * server receive time; `client_submitted_at` (offline) is kept in the audit log.
     */
    public function store(Request $request, Assignment $assignment, AttachmentService $files): JsonResponse
    {
        Gate::authorize('view', $assignment);
        Gate::authorize('submit', $assignment->course);
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'text' => ['nullable', 'string', 'max:50000', 'required_without:attachment_id'],
            'attachment_id' => ['nullable', 'integer', 'required_without:text'],
            'client_submitted_at' => ['nullable', 'date'],
        ]);
        $user = $request->user();

        if ($existing = Submission::withTrashed()->where('client_uuid', $data['client_uuid'])->first()) {
            abort_unless($existing->student_id === $user->id, 422, 'client_uuid already used.');

            return (new SubmissionResource($existing->load('attachment', 'assignment')))->response()->setStatusCode(200);
        }

        $late = $assignment->isPastDue();
        if ($late && ! $assignment->allow_late) {
            return response()->json(['message' => 'The deadline has passed and late submissions are not accepted.'], 422);
        }
        $files->assertOwned($data['attachment_id'] ?? null, $user);

        try {
            $submission = DB::transaction(function () use ($assignment, $user, $data, $late) {
                $current = Submission::where('assignment_id', $assignment->id)->where('student_id', $user->id)->lockForUpdate()->first();
                $values = [
                    'client_uuid' => $data['client_uuid'],
                    'text' => $data['text'] ?? null,
                    'attachment_id' => $data['attachment_id'] ?? null,
                    'submitted_at' => now(),
                    'is_late' => $late,
                ];
                if ($current) {
                    // resubmission: last write wins; any earlier grade no longer applies
                    $current->fill([...$values, 'attempt' => $current->attempt + 1, 'score' => null, 'feedback' => null, 'graded_by' => null, 'graded_at' => null])->save();

                    return $current;
                }

                return Submission::create([...$values, 'attempt' => 1, 'assignment_id' => $assignment->id, 'student_id' => $user->id]);
            });
        } catch (UniqueConstraintViolationException) {
            // a concurrent retry with the same client_uuid won the race
            $submission = Submission::where('client_uuid', $data['client_uuid'])->firstOrFail();
        }

        Audit::log('submission.submitted', $submission, array_filter([
            'assignment_id' => $assignment->id,
            'is_late' => $late,
            'attempt' => $submission->attempt,
            'client_submitted_at' => $data['client_submitted_at'] ?? null,
        ], fn ($v) => $v !== null));

        return (new SubmissionResource($submission->load('attachment', 'assignment')))->response()->setStatusCode(201);
    }

    /**
     * My submission (student)
     */
    public function mine(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('view', $assignment);
        $submission = $assignment->submissions()->where('student_id', $request->user()->id)->with('attachment', 'assignment')->first();

        return response()->json(['data' => $submission ? new SubmissionResource($submission) : null]);
    }

    /**
     * Grade a submission (LMS-7, lecturer)
     */
    public function grade(Request $request, Submission $submission): SubmissionResource
    {
        Gate::authorize('grade', $submission);
        $data = $request->validate([
            'score' => ['required', 'numeric', 'min:0', 'max:'.$submission->assignment->max_score],
            'feedback' => ['nullable', 'string', 'max:20000'],
        ]);

        $old = $submission->score;
        $submission->fill([...$data, 'graded_by' => $request->user()->id, 'graded_at' => now()])->save();
        Audit::log('grade.changed', $submission, ['from' => $old, 'to' => (float) $data['score'], 'assignment_id' => $submission->assignment_id]);
        event(new \App\Events\SubmissionGraded($submission));

        return new SubmissionResource($submission->load('attachment', 'assignment', 'student'));
    }
}
