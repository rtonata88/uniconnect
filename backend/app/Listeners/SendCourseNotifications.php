<?php

namespace App\Listeners;

use App\Events\AnnouncementPosted;
use App\Events\AssignmentPublished;
use App\Events\GradesReleased;
use App\Events\QuizPublished;
use App\Notifications\AnnouncementPostedNotification;
use App\Notifications\AssignmentPublishedNotification;
use App\Notifications\GradesReleasedNotification;
use App\Notifications\QuizPublishedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * NOT-1 fan-out: new announcement (LMS-4) → everyone enrolled except the author;
 * new assignment / quiz / released grades → enrolled students.
 */
class SendCourseNotifications implements ShouldQueue
{
    /** fan-out runs on the worker, never inside the request */
    public string $queue = 'push';

    public function handleAnnouncement(AnnouncementPosted $event): void
    {
        $a = $event->announcement;
        $users = $a->course->members()->where('users.id', '!=', $a->author_id)->get();
        Notification::send($users, new AnnouncementPostedNotification($a));
    }

    public function handleAssignment(AssignmentPublished $event): void
    {
        Notification::send($event->assignment->course->students()->get(), new AssignmentPublishedNotification($event->assignment));
    }

    public function handleQuiz(QuizPublished $event): void
    {
        Notification::send($event->quiz->course->students()->get(), new QuizPublishedNotification($event->quiz));
    }

    public function handleGrades(GradesReleased $event): void
    {
        // only students who actually have a grade in this column
        $ids = $event->item->grades()->whereNotNull('score')->pluck('student_id');
        Notification::send($event->item->course->students()->whereIn('users.id', $ids)->get(), new GradesReleasedNotification($event->item));
    }
}
