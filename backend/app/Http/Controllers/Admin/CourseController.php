<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\User;
use App\Support\Audit;
use App\Support\Enrolment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * @group Admin: courses
 */
class CourseController extends Controller
{
    /**
     * List courses
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('create', Course::class);
        $courses = Course::with('lecturers:id,name')->withCount('students')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'like', '%'.$request->string('search').'%')
                ->orWhere('title', 'like', '%'.$request->string('search').'%')))
            ->orderBy('code')->paginate(50);

        return CourseResource::collection($courses);
    }

    /**
     * Create a course (LMS-1)
     *
     * Also creates the course group chat. Optional lecturer_ids enrols lecturers.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Course::class);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', 'unique:courses,code'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'semester' => ['nullable', 'string', 'max:40'],
            'lecturer_ids' => ['array'],
            'lecturer_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', 'lecturer')],
        ]);

        $course = DB::transaction(function () use ($data, $request) {
            $course = Course::create([...$data, 'code' => mb_strtoupper($data['code']), 'created_by' => $request->user()->id]);
            app(Enrolment::class)->enrol($course, $data['lecturer_ids'] ?? [], 'lecturer');

            return $course;
        });
        Audit::log('course.created', $course, ['code' => $course->code]);

        return (new CourseResource($course->load('lecturers:id,name')->loadCount('students')))->response()->setStatusCode(201);
    }

    /**
     * Update a course
     */
    public function update(Request $request, Course $course): CourseResource
    {
        Gate::authorize('update', $course);
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:20', 'alpha_dash', Rule::unique('courses', 'code')->ignore($course->id)],
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'semester' => ['nullable', 'string', 'max:40'],
        ]);
        if (isset($data['code'])) {
            $data['code'] = mb_strtoupper($data['code']);
        }
        $course->update($data);

        return new CourseResource($course->load('lecturers:id,name')->loadCount('students'));
    }

    /**
     * Delete a course
     *
     * Soft delete: the course disappears for every member on their next sync.
     */
    public function destroy(Course $course): JsonResponse
    {
        Gate::authorize('delete', $course);
        $course->delete();
        Audit::log('course.deleted', $course, ['code' => $course->code]);

        return response()->json(['message' => 'Course deleted.']);
    }

    /**
     * Enrol users (LMS-1)
     *
     * @bodyParam user_ids integer[] required Users to enrol. Example: [4,5]
     * @bodyParam role string required lecturer or student. Example: student
     */
    public function enrol(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('enrol', $course);
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role' => ['required', Rule::in(['lecturer', 'student'])],
        ]);
        if ($data['role'] === 'lecturer' && User::whereIn('id', $data['user_ids'])->where('role', '!=', 'lecturer')->exists()) {
            return response()->json(['message' => 'Only users with the lecturer role can be enrolled as lecturers.', 'errors' => ['user_ids' => ['Only lecturers can teach a course.']]], 422);
        }

        $added = app(Enrolment::class)->enrol($course, $data['user_ids'], $data['role']);

        return response()->json(['message' => count($added).' enrolled.', 'added' => $added]);
    }

    /**
     * Remove enrolments (LMS-1)
     *
     * @bodyParam user_ids integer[] required Example: [4]
     */
    public function unenrol(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('enrol', $course);
        $data = $request->validate(['user_ids' => ['required', 'array', 'min:1'], 'user_ids.*' => ['integer']]);
        $removed = app(Enrolment::class)->unenrol($course, $data['user_ids']);

        return response()->json(['message' => count($removed).' removed.', 'removed' => $removed]);
    }
}
