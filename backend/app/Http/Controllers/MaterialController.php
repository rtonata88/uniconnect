<?php

namespace App\Http\Controllers;

use App\Events\MaterialPublished;
use App\Http\Resources\MaterialResource;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Support\Files\AttachmentService;
use App\Support\StaleWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @group Course materials
 */
class MaterialController extends Controller
{
    /** Material types lecturers may upload (LMS-3). */
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xlsx', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp'];

    /**
     * List materials
     *
     * Grouped by week on the client. Students only see published materials.
     */
    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        Gate::authorize('view', $course);
        $materials = $course->materials()->with('attachment')
            ->unless($course->isLecturer($request->user()), fn ($q) => $q->published())
            ->orderBy('week')->orderBy('id')->get();

        return MaterialResource::collection($materials);
    }

    /**
     * Upload a material (LMS-3)
     *
     * Upload the file with POST /attachments first, then send its id here.
     */
    public function store(Request $request, Course $course, AttachmentService $files): JsonResponse
    {
        Gate::authorize('teach', $course);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'week' => ['required', 'integer', 'min:0', 'max:60'],
            'attachment_id' => ['required', 'integer'],
            'publish' => ['sometimes', 'boolean'],
        ]);
        $attachment = $files->assertOwned($data['attachment_id'], $request->user());
        if (! in_array(pathinfo($attachment->original_name, PATHINFO_EXTENSION), self::EXTENSIONS, true)) {
            return response()->json(['message' => 'Lecture notes must be PDF, Word, PowerPoint, Excel, text or image files.', 'errors' => ['attachment_id' => ['Unsupported material type.']]], 422);
        }

        $material = $course->materials()->create([
            ...collect($data)->except('publish')->all(),
            'uploaded_by' => $request->user()->id,
            'published_at' => ($data['publish'] ?? true) ? now() : null,
        ]);
        if ($material->isPublished()) {
            MaterialPublished::dispatch($material);
        }

        return (new MaterialResource($material->load('attachment')))->response()->setStatusCode(201);
    }

    /**
     * Update a material
     *
     * Send the `updated_at` you edited to get 409 if someone changed it meanwhile.
     */
    public function update(Request $request, CourseMaterial $material, AttachmentService $files): MaterialResource
    {
        Gate::authorize('update', $material);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'week' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'attachment_id' => ['sometimes', 'integer'],
            'publish' => ['sometimes', 'boolean'],
            'updated_at' => ['nullable', 'date'],
        ]);
        StaleWrite::check($material, $data['updated_at'] ?? null);
        if (isset($data['attachment_id']) && $data['attachment_id'] !== $material->attachment_id) {
            $files->assertOwned($data['attachment_id'], $request->user());
        }

        $wasPublished = $material->isPublished();
        if (array_key_exists('publish', $data)) {
            $material->published_at = $data['publish'] ? ($material->published_at ?? now()) : null;
        }
        $material->fill(collect($data)->except(['publish', 'updated_at'])->all())->save();
        if (! $wasPublished && $material->isPublished()) {
            MaterialPublished::dispatch($material);
        }

        return new MaterialResource($material->load('attachment'));
    }

    /**
     * Delete a material
     */
    public function destroy(CourseMaterial $material): JsonResponse
    {
        Gate::authorize('delete', $material);
        $material->delete();

        return response()->json(['message' => 'Material deleted.']);
    }
}
