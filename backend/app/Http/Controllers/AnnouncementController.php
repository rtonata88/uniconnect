<?php

namespace App\Http\Controllers;

use App\Events\AnnouncementPosted;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Course;
use App\Support\StaleWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @group Announcements
 */
class AnnouncementController extends Controller
{
    /**
     * List announcements
     */
    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        Gate::authorize('view', $course);
        $items = $course->announcements()->with('author:id,name')
            ->unless($course->isLecturer($request->user()), fn ($q) => $q->published())
            ->latest('published_at')->latest('id')->get();

        return AnnouncementResource::collection($items);
    }

    /**
     * Post an announcement (LMS-4)
     *
     * Enrolled users are notified. `client_uuid` makes the call idempotent so drafts
     * written offline are never posted twice (MOB-5).
     */
    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('teach', $course);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'publish' => ['sometimes', 'boolean'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);
        if (! empty($data['client_uuid']) && $existing = Announcement::where('client_uuid', $data['client_uuid'])->first()) {
            abort_unless($existing->course_id === $course->id, 422, 'client_uuid already used.');

            return (new AnnouncementResource($existing->load('author:id,name')))->response()->setStatusCode(200);
        }

        $announcement = $course->announcements()->create([
            'title' => $data['title'],
            'body' => $data['body'],
            'client_uuid' => $data['client_uuid'] ?? null,
            'author_id' => $request->user()->id,
            'published_at' => ($data['publish'] ?? true) ? now() : null,
        ]);
        if ($announcement->isPublished()) {
            AnnouncementPosted::dispatch($announcement);
        }

        return (new AnnouncementResource($announcement->load('author:id,name')))->response()->setStatusCode(201);
    }

    /**
     * Update an announcement
     */
    public function update(Request $request, Announcement $announcement): AnnouncementResource
    {
        Gate::authorize('update', $announcement);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'string', 'max:20000'],
            'publish' => ['sometimes', 'boolean'],
            'updated_at' => ['nullable', 'date'],
        ]);
        StaleWrite::check($announcement, $data['updated_at'] ?? null);

        $wasPublished = $announcement->isPublished();
        if (array_key_exists('publish', $data)) {
            $announcement->published_at = $data['publish'] ? ($announcement->published_at ?? now()) : null;
        }
        $announcement->fill(collect($data)->only(['title', 'body'])->all())->save();
        if (! $wasPublished && $announcement->isPublished()) {
            AnnouncementPosted::dispatch($announcement);
        }

        return new AnnouncementResource($announcement->load('author:id,name'));
    }

    /**
     * Delete an announcement
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        Gate::authorize('delete', $announcement);
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }
}
