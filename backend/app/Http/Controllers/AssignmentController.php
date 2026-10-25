<?php

namespace App\Http\Controllers;

use App\Events\AssignmentPublished;
use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\Course;
use App\Support\Files\AttachmentService;
use App\Support\StaleWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @group Assignments
 */
class AssignmentController extends Controller
{
    private function rules(bool $create): array
    {
        $s = $create ? 'required' : 'sometimes';

        return [
            'title' => [$s, 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'due_at' => [$s, 'date'],
            'max_score' => [$s, 'numeric', 'min:1', 'max:1000'],
            'allow_late' => ['sometimes', 'boolean'],
            'late_penalty_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'attachment_id' => ['nullable', 'integer'],
            'publish' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * List assignments
     *
     * Students get their own submission in `my_submission`.
     */
    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        Gate::authorize('view', $course);
        $lecturer = $course->isLecturer($request->user());
        $items = $course->assignments()->with('attachment')
            ->unless($lecturer, fn ($q) => $q->published()->with('mySubmission.attachment', 'mySubmission.assignment'))
            ->when($lecturer, fn ($q) => $q->withCount('submissions'))
            ->orderBy('due_at')->get();

        return AssignmentResource::collection($items);
    }

    /**
     * Create an assignment (LMS-5)
     */
    public function store(Request $request, Course $course, AttachmentService $files): JsonResponse
    {
        Gate::authorize('teach', $course);
        $data = $request->validate($this->rules(true));
        $files->assertOwned($data['attachment_id'] ?? null, $request->user());

        $assignment = $course->assignments()->create([
            ...collect($data)->except('publish')->all(),
            'created_by' => $request->user()->id,
            'published_at' => ($data['publish'] ?? true) ? now() : null,
        ]);
        if ($assignment->isPublished()) {
            AssignmentPublished::dispatch($assignment);
        }

        return (new AssignmentResource($assignment->load('attachment')))->response()->setStatusCode(201);
    }

    /**
     * Assignment details
     */
    public function show(Request $request, Assignment $assignment): AssignmentResource
    {
        Gate::authorize('view', $assignment);
        $assignment->load('attachment', 'course');
        if ($assignment->course->isLecturer($request->user())) {
            $assignment->loadCount('submissions');
        } else {
            $assignment->load('mySubmission.attachment', 'mySubmission.assignment');
        }

        return new AssignmentResource($assignment);
    }

    /**
     * Update an assignment
     */
    public function update(Request $request, Assignment $assignment, AttachmentService $files): AssignmentResource
    {
        Gate::authorize('update', $assignment);
        $data = $request->validate([...$this->rules(false), 'updated_at' => ['nullable', 'date']]);
        StaleWrite::check($assignment, $data['updated_at'] ?? null);
        if (! empty($data['attachment_id']) && $data['attachment_id'] !== $assignment->attachment_id) {
            $files->assertOwned($data['attachment_id'], $request->user());
        }

        $wasPublished = $assignment->isPublished();
        if (array_key_exists('publish', $data)) {
            $assignment->published_at = $data['publish'] ? ($assignment->published_at ?? now()) : null;
        }
        if (isset($data['due_at'])) {
            // a new deadline gets new reminders
            $assignment->reminded_24h_at = null;
            $assignment->reminded_1h_at = null;
        }
        $assignment->fill(collect($data)->except(['publish', 'updated_at'])->all())->save();
        if (! $wasPublished && $assignment->isPublished()) {
            AssignmentPublished::dispatch($assignment);
        }

        return new AssignmentResource($assignment->load('attachment'));
    }

    /**
     * Delete an assignment
     */
    public function destroy(Assignment $assignment): JsonResponse
    {
        Gate::authorize('delete', $assignment);
        $assignment->delete();

        return response()->json(['message' => 'Assignment deleted.']);
    }
}
