<?php

namespace App\Console\Commands;

use App\Models\QuizAttempt;
use App\Support\Lms\QuizGrader;
use Illuminate\Console\Command;

/**
 * LMS-9: the client auto-submits when the timer ends; this catches attempts whose
 * client went away (closed tab, lost connection) so they are graded anyway.
 */
class CloseExpiredAttempts extends Command
{
    protected $signature = 'quizzes:close-expired';

    protected $description = 'Auto-submit quiz attempts past their deadline';

    public function handle(QuizGrader $grader): int
    {
        $count = 0;
        QuizAttempt::whereNull('submitted_at')->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now()->subSeconds(QuizAttempt::GRACE_SECONDS))
            ->each(function (QuizAttempt $attempt) use ($grader, &$count) {
                $grader->submit($attempt, auto: true);
                $count++;
            });
        if ($count) {
            $this->info("auto-submitted {$count} attempts");
        }

        return self::SUCCESS;
    }
}
