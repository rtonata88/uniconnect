<?php

use Illuminate\Support\Facades\Schedule;

// LMS-9: grade attempts whose time ran out while the student was away
Schedule::command('quizzes:close-expired')->everyMinute()->withoutOverlapping()->onOneServer();

// NOT-4: deadline reminders 24 h and 1 h before the due time
Schedule::command('assignments:remind')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
