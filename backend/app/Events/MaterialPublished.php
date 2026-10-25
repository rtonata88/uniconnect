<?php

namespace App\Events;

use App\Models\CourseMaterial;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when the item becomes visible to students. */
class MaterialPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(public CourseMaterial $material) {}
}
