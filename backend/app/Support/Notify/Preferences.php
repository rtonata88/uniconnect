<?php

namespace App\Support\Notify;

use App\Models\User;

/**
 * NOT-5: per-category push on/off. In-app notifications are always on.
 */
class Preferences
{
    public const CATEGORIES = [
        'messages' => 'New chat messages',
        'announcements' => 'Announcements',
        'assignments' => 'New assignments',
        'quizzes' => 'New quizzes',
        'grades' => 'Released grades',
        'reminders' => 'Deadline reminders',
        'groups' => 'Added to a group',
    ];

    /** @return array<string, bool> */
    public static function forUser(User $user): array
    {
        $stored = $user->notification_preferences['push'] ?? [];

        return collect(self::CATEGORIES)->mapWithKeys(fn ($_, $key) => [$key => (bool) ($stored[$key] ?? true)])->all();
    }

    public static function pushEnabled(User $user, string $category): bool
    {
        return self::forUser($user)[$category] ?? true;
    }
}
