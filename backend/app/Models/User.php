<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable(['name', 'email', 'password', 'role', 'student_number', 'avatar_path', 'is_active', 'notification_preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /** NOT-2: notifications are broadcast on private-user.{id}. */
    public function receivesBroadcastNotificationsOn(): string
    {
        return "user.{$this->id}";
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class)->withPivot('role')->withTimestamps();
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot(['role', 'joined_at', 'last_delivered_message_id', 'last_read_message_id', 'muted_until'])
            ->withTimestamps();
    }

    /** Users share a course (direct chats are only allowed then, MSG-2). */
    public function sharesCourseWith(User $other): bool
    {
        return DB::table('course_user as a')
            ->join('course_user as b', 'a.course_id', '=', 'b.course_id')
            ->join('courses', 'courses.id', '=', 'a.course_id')
            ->whereNull('courses.deleted_at')
            ->where('a.user_id', $this->id)->where('b.user_id', $other->id)->exists();
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isLecturer(): bool
    {
        return $this->role === Role::Lecturer;
    }

    public function isStudent(): bool
    {
        return $this->role === Role::Student;
    }
}
