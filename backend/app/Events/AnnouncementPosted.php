<?php

namespace App\Events;

use App\Models\Announcement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when the item becomes visible to students. */
class AnnouncementPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Announcement $announcement) {}
}
