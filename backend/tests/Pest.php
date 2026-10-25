<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
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
    \Illuminate\Support\Facades\Storage::fake('s3', ['serve' => true]);
}

/** A course with one lecturer and one student. @return array{0:\App\Models\Course,1:\App\Models\User,2:\App\Models\User} */
function courseWithMembers(): array
{
    $course = \App\Models\Course::factory()->create();
    $lecturer = \App\Models\User::factory()->lecturer()->create();
    $student = \App\Models\User::factory()->student()->create();
    // through the enrolment service so the course chat membership follows (PRD §6)
    app(\App\Support\Enrolment::class)->enrol($course, [$lecturer->id], 'lecturer');
    app(\App\Support\Enrolment::class)->enrol($course, [$student->id], 'student');

    return [$course, $lecturer, $student];
}

function pdfUpload(string $name = 'notes.pdf'): \Illuminate\Http\UploadedFile
{
    return \Illuminate\Http\UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}
