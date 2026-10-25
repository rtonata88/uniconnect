<?php

namespace App\Http\Controllers;

use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Message;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;
use App\Support\System\SystemStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Dashboard
 */
class DashboardController extends Controller
{
    /**
     * Role-aware dashboard (PRD 10.1, LMS-11)
     *
     * Student: upcoming deadlines, recent announcements, unread chats.
     * Lecturer: submissions to grade and recent activity. Admin: counts and a health summary.
     */
    public function __invoke(Request $request, SystemStatus $system): JsonResponse
    {
        $user = $request->user();
        $courseIds = $user->courses()->pluck('courses.id');
        $lecturing = $user->courses()->wherePivot('role', 'lecturer')->pluck('courses.id');
        $studying = $courseIds->diff($lecturing)->values();

        $data = ['role' => $user->role->value];

        $data['recent_announcements'] = AnnouncementResource::collection(
            Announcement::whereIn('course_id', $courseIds)->published()->with('author:id,name', 'course:id,code')
                ->latest('published_at')->limit(5)->get()
        )->resolve();
        foreach ($data['recent_announcements'] as $i => $a) {
            $data['recent_announcements'][$i]['course_code'] = Announcement::find($a['id'])?->course?->code;
        }
        $data['unread_chats'] = (int) ConversationController::query($user)->get()->filter(fn ($c) => $c->unread_count > 0)->count();

        if ($studying->isNotEmpty()) {
            $data['upcoming_deadlines'] = Assignment::whereIn('course_id', $studying)->published()
                ->where('due_at', '>', now())->where('due_at', '<', now()->addDays(14))
                ->with(['course:id,code', 'mySubmission'])
                ->orderBy('due_at')->limit(8)->get()
                ->map(fn ($a) => [
                    'id' => $a->id, 'title' => $a->title, 'course_id' => $a->course_id, 'course_code' => $a->course->code,
                    'due_at' => $a->due_at->toIso8601ZuluString(), 'submitted' => $a->mySubmission !== null,
                ]);
        }

        if ($lecturing->isNotEmpty()) {
            $data['to_grade'] = Assignment::whereIn('course_id', $lecturing)->with('course:id,code')
                ->whereHas('submissions', fn ($q) => $q->whereNull('graded_at'))
                ->withCount(['submissions as ungraded_count' => fn ($q) => $q->whereNull('graded_at')])
                ->orderByDesc('ungraded_count')->limit(8)->get()
                ->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'course_code' => $a->course->code, 'ungraded' => $a->ungraded_count]);
            $data['quiz_attempts_to_grade'] = QuizAttempt::whereHas('quiz', fn ($q) => $q->whereIn('course_id', $lecturing))
                ->where('needs_manual_grading', true)->count();
            $data['recent_submissions'] = Submission::whereHas('assignment', fn ($q) => $q->whereIn('course_id', $lecturing))
                ->with('student:id,name', 'assignment:id,title,course_id')->latest('submitted_at')->limit(8)->get()
                ->map(fn ($s) => [
                    'id' => $s->id, 'assignment_id' => $s->assignment_id, 'assignment' => $s->assignment->title,
                    'student' => $s->student->name, 'submitted_at' => $s->submitted_at->toIso8601ZuluString(), 'is_late' => $s->is_late,
                ]);
        }

        if ($user->isAdmin()) {
            $instances = $system->instances();
            $data['admin'] = [
                'users' => User::count(),
                'students' => User::where('role', 'student')->count(),
                'lecturers' => User::where('role', 'lecturer')->count(),
                'courses' => Course::count(),
                'messages_today' => Message::where('created_at', '>=', now()->startOfDay())->count(),
                'instances' => collect($instances)->map(fn ($list) => ['total' => count($list), 'healthy' => collect($list)->where('status', 'healthy')->count()]),
            ];
        }

        return response()->json($data);
    }
}
