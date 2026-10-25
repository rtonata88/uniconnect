<?php

namespace App\Events;

use App\Models\Submission;
use Illuminate\Foundation\Events\Dispatchable;

class SubmissionGraded
{
    use Dispatchable;

    public function __construct(public Submission $submission) {}
}
