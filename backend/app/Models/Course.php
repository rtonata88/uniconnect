<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable(['code', 'title', 'description', 'semester', 'created_by'])]
class Course extends Model
{
    use HasFactory, SoftDeletes;

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function lecturers(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'lecturer');
    }

    public function students(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'student');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CourseMaterial::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** @var array<int, string|null> per-instance memo (models do not outlive a request under Octane) */
    private array $roleMemo = [];

    /** Role of the user in this course: 'lecturer' | 'student' | null */
    public function roleOf(User $user): ?string
    {
        if (array_key_exists($user->id, $this->roleMemo)) {
            return $this->roleMemo[$user->id];
        }
        if ($this->relationLoaded('members')) {
            $role = $this->members->firstWhere('id', $user->id)?->pivot->role;
        } else {
            $role = DB::table('course_user')->where('course_id', $this->id)->where('user_id', $user->id)->value('role');
        }

        return $this->roleMemo[$user->id] = $role;
    }

    public function isLecturer(User $user): bool
    {
        return $this->roleOf($user) === 'lecturer';
    }

    public function isMember(User $user): bool
    {
        return $this->roleOf($user) !== null;
    }
}
