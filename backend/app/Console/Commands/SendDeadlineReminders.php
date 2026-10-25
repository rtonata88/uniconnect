<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Notifications\DeadlineReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * NOT-4: remind students who have not submitted, 24 h and 1 h before the deadline.
 * Each window is sent once per assignment (reminded_*_at, claimed atomically so two
 * schedulers on different machines never double-send).
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'assignments:remind';

    protected $description = 'Send 24 h and 1 h deadline reminders to students who have not submitted';

    public function handle(): int
    {
        $sent = 0;
        foreach (['24h' => [24, 'reminded_24h_at'], '1h' => [1, 'reminded_1h_at']] as $window => [$hours, $column]) {
            $due = Assignment::published()
                ->whereNull($column)
                ->where('due_at', '>', now())
                ->where('due_at', '<=', now()->addHours($hours))
                ->get();
            foreach ($due as $assignment) {
                // claim the window atomically
                $claimed = DB::table('assignments')->where('id', $assignment->id)->whereNull($column)->update([$column => now()]);
                if (! $claimed) {
                    continue;
                }
                if ($window === '24h' && $assignment->due_at->lessThanOrEqualTo(now()->addHour())) {
                    continue; // created less than an hour before the deadline: the 1 h reminder covers it
                }
                $students = $assignment->course->students()
                    ->whereNotIn('users.id', $assignment->submissions()->select('student_id'))
                    ->get();
                Notification::send($students, new DeadlineReminderNotification($assignment, $window));
                $sent += $students->count();
            }
        }
        $this->info("sent {$sent} reminders");

        return self::SUCCESS;
    }
}
