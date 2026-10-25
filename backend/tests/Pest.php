<?php

use App\Models\Course;
use App\Models\User;
use App\Support\Enrolment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
| Helpers
*/
function fakeStorage(): void
{
    Storage::fake('s3', ['serve' => true]);
}

/** A course with one lecturer and one student. @return array{0:\App\Models\Course,1:\App\Models\User,2:\App\Models\User} */
function courseWithMembers(): array
{
    $course = Course::factory()->create();
    $lecturer = User::factory()->lecturer()->create();
    $student = User::factory()->student()->create();
    // through the enrolment service so the course chat membership follows (PRD §6)
    app(Enrolment::class)->enrol($course, [$lecturer->id], 'lecturer');
    app(Enrolment::class)->enrol($course, [$student->id], 'student');

    return [$course, $lecturer, $student];
}

function pdfUpload(string $name = 'notes.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}
