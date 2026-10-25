<?php

namespace App\Listeners;

use App\Events\SubmissionGraded;
use App\Support\Lms\Gradebook;

class SyncSubmissionGrade
{
    public function __construct(private Gradebook $gradebook) {}

    public function handle(SubmissionGraded $event): void
    {
        $this->gradebook->syncSubmission($event->submission);
    }
}
