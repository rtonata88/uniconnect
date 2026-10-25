<?php

namespace App\Events;

use App\Models\GradeItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GradesReleased
{
    use Dispatchable, SerializesModels;

    public function __construct(public GradeItem $item) {}
}
