<?php

namespace App\Http\Controllers;

use App\Http\Resources\CourseResource;
use App\Http\Resources\UserResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @group Courses
 */
class CourseController extends Controller
{
    /**
     * My courses
     *
     * Courses the user is enrolled in (admins: every course).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = $user->isAdmin()
            ? Course::query()
            : $user->courses();

        $courses = $query->with('lecturers:id,name')->withCount('students')->orderBy('code')->get();

        return CourseResource::collection($courses);
    }

    /**
     * Course details
     */
    public function show(Course $course): CourseResource
    {
        Gate::authorize('view', $course);

        return new CourseResource($course->load('lecturers:id,name')->loadCount('students'));
    }

    /**
     * People in a course
     *
     * Lecturers and students enrolled in the course.
     */
    public function people(Course $course): JsonResponse
    {
        Gate::authorize('view', $course);
        $members = $course->members()->orderBy('name')->get();

        return response()->json([
            'lecturers' => UserResource::collection($members->where('pivot.role', 'lecturer')->values()),
            'students' => UserResource::collection($members->where('pivot.role', 'student')->values()),
        ]);
    }
}
