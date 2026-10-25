<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\GradebookController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\QuizAttemptController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;

/*
| Public endpoints (SEC-1: everything else requires a Sanctum token)
*/
Route::get('/health', HealthController::class)->name('health');
Route::get('/config', ConfigController::class);
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login-ip')->name('auth.login');
Route::middleware('throttle:guest')->group(function () {
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->name('password.email');
    Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

Route::middleware(['auth:sanctum', 'activity', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);

    Route::get('/me', [MeController::class, 'show']);
    Route::patch('/me', [MeController::class, 'update']);
    Route::post('/me/avatar', [MeController::class, 'avatar'])->middleware('throttle:uploads');

    // Files
    Route::post('/attachments', [AttachmentController::class, 'store'])->middleware('throttle:uploads');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show']);

    // Courses (LMS)
    Route::get('/courses', [CourseController::class, 'index']);
    Route::get('/courses/{course}', [CourseController::class, 'show']);
    Route::get('/courses/{course}/people', [CourseController::class, 'people']);

    Route::get('/courses/{course}/materials', [MaterialController::class, 'index']);
    Route::post('/courses/{course}/materials', [MaterialController::class, 'store']);
    Route::patch('/materials/{material}', [MaterialController::class, 'update']);
    Route::delete('/materials/{material}', [MaterialController::class, 'destroy']);

    Route::get('/courses/{course}/announcements', [AnnouncementController::class, 'index']);
    Route::post('/courses/{course}/announcements', [AnnouncementController::class, 'store']);
    Route::patch('/announcements/{announcement}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);

    Route::get('/courses/{course}/assignments', [AssignmentController::class, 'index']);
    Route::post('/courses/{course}/assignments', [AssignmentController::class, 'store']);
    Route::get('/assignments/{assignment}', [AssignmentController::class, 'show']);
    Route::patch('/assignments/{assignment}', [AssignmentController::class, 'update']);
    Route::delete('/assignments/{assignment}', [AssignmentController::class, 'destroy']);

    Route::get('/assignments/{assignment}/submissions', [SubmissionController::class, 'index']);
    Route::post('/assignments/{assignment}/submissions', [SubmissionController::class, 'store']);
    Route::get('/assignments/{assignment}/my-submission', [SubmissionController::class, 'mine']);
    Route::patch('/submissions/{submission}/grade', [SubmissionController::class, 'grade']);

    Route::get('/courses/{course}/quizzes', [QuizController::class, 'index']);
    Route::post('/courses/{course}/quizzes', [QuizController::class, 'store']);
    Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
    Route::patch('/quizzes/{quiz}', [QuizController::class, 'update']);
    Route::delete('/quizzes/{quiz}', [QuizController::class, 'destroy']);
    Route::post('/quizzes/{quiz}/attempts', [QuizAttemptController::class, 'start']);
    Route::get('/quizzes/{quiz}/attempts', [QuizAttemptController::class, 'index']);
    Route::get('/attempts/{attempt}', [QuizAttemptController::class, 'show']);
    Route::put('/attempts/{attempt}/answers', [QuizAttemptController::class, 'saveAnswers']);
    Route::post('/attempts/{attempt}/submit', [QuizAttemptController::class, 'submit']);
    Route::patch('/attempts/{attempt}/grade', [QuizAttemptController::class, 'grade']);

    Route::get('/courses/{course}/gradebook', [GradebookController::class, 'show']);
    Route::get('/courses/{course}/gradebook/export', [GradebookController::class, 'export']);
    Route::post('/courses/{course}/grade-items', [GradebookController::class, 'storeItem']);
    Route::patch('/grade-items/{item}', [GradebookController::class, 'updateItem']);
    Route::put('/grade-items/{item}/grades', [GradebookController::class, 'setGrade']);
    Route::post('/grade-items/{item}/release', [GradebookController::class, 'release']);
    Route::get('/courses/{course}/my-grades', [GradebookController::class, 'mine']);

    // Messaging
    Route::get('/contacts', [ContactController::class, 'index']);
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
    Route::post('/conversations/{conversation}/participants', [ConversationController::class, 'addParticipants']);
    Route::delete('/conversations/{conversation}/participants/{user}', [ConversationController::class, 'removeParticipant']);
    Route::post('/conversations/{conversation}/leave', [ConversationController::class, 'leave']);
    Route::post('/conversations/{conversation}/mute', [ConversationController::class, 'mute']);
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])->middleware('throttle:messages');
    Route::delete('/messages/{message}', [MessageController::class, 'destroy']);
    Route::post('/messages/receipts', [MessageController::class, 'receipts']);
    Route::get('/messages/{message}/info', [MessageController::class, 'info']);

    // Notifications and push
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'read']);
    Route::get('/notification-preferences', [NotificationController::class, 'preferences']);
    Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences']);
    Route::post('/push/webpush/subscribe', [PushSubscriptionController::class, 'store']);
    Route::delete('/push/webpush/subscribe', [PushSubscriptionController::class, 'destroy']);

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::post('/users/import', [AdminUserController::class, 'import'])->middleware('throttle:uploads');
        Route::patch('/users/{user}', [AdminUserController::class, 'update']);
        Route::get('/courses', [AdminCourseController::class, 'index']);
        Route::post('/courses', [AdminCourseController::class, 'store']);
        Route::patch('/courses/{course}', [AdminCourseController::class, 'update']);
        Route::delete('/courses/{course}', [AdminCourseController::class, 'destroy']);
        Route::post('/courses/{course}/enrolments', [AdminCourseController::class, 'enrol']);
        Route::delete('/courses/{course}/enrolments', [AdminCourseController::class, 'unenrol']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });
});
