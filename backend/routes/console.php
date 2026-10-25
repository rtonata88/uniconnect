<?php

use Illuminate\Support\Facades\Schedule;

// LMS-9: grade attempts whose time ran out while the student was away
Schedule::command('quizzes:close-expired')->everyMinute()->withoutOverlapping()->onOneServer();
