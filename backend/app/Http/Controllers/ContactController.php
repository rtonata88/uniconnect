<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Messaging: conversations
 */
class ContactController extends Controller
{
    /**
     * People you can chat with (MSG-2)
     *
     * Users who share a course with you, plus admins. Admins can find everyone.
     *
     * @queryParam search string Name filter. Example: Anna
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $me = $request->user();
        $users = User::query()->where('id', '!=', $me->id)->where('is_active', true)
            ->unless($me->isAdmin(), fn ($q) => $q->where(fn ($q) => $q
                ->where('role', 'admin')
                ->orWhereIn('id', fn ($sub) => $sub->select('b.user_id')->from('course_user as a')
                    ->join('course_user as b', 'a.course_id', '=', 'b.course_id')
                    ->join('courses', 'courses.id', '=', 'a.course_id')->whereNull('courses.deleted_at')
                    ->where('a.user_id', $me->id))))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')).'%'))
            ->orderBy('name')->limit(200)->get();

        return UserResource::collection($users);
    }
}
