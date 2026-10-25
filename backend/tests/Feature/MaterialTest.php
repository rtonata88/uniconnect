<?php

use App\Events\MaterialPublished;
use App\Models\Attachment;
use App\Models\CourseMaterial;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => fakeStorage());

it('LMS-3 lecturer uploads a material grouped by week and students download it', function () {
    Event::fake([MaterialPublished::class]);
    [$course, $lecturer, $student] = courseWithMembers();

    $att = $this->actingAs($lecturer)->post('/api/attachments', ['file' => pdfUpload()], ['Accept' => 'application/json'])->json('data.id');
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/materials", [
        'title' => 'Week 3: Replication', 'week' => 3, 'attachment_id' => $att,
    ])->assertCreated()->assertJsonPath('data.week', 3)->assertJsonPath('data.attachment.id', $att);

    Event::assertDispatched(MaterialPublished::class);
    $this->actingAs($student)->getJson("/api/courses/{$course->id}/materials")->assertJsonCount(1, 'data');
    $this->actingAs($student)->get("/api/attachments/{$att}")->assertRedirect();
});

it('SEC-2 students cannot upload materials', function () {
    [$course, , $student] = courseWithMembers();
    $att = Attachment::factory()->create(['owner_id' => $student->id]);
    $this->actingAs($student)->postJson("/api/courses/{$course->id}/materials", ['title' => 'x', 'week' => 1, 'attachment_id' => $att->id])
        ->assertForbidden();
});

it('cannot attach a file uploaded by someone else', function () {
    [$course, $lecturer] = courseWithMembers();
    $att = Attachment::factory()->create();
    $this->actingAs($lecturer)->postJson("/api/courses/{$course->id}/materials", ['title' => 'x', 'week' => 1, 'attachment_id' => $att->id])
        ->assertStatus(422)->assertJsonValidationErrors('attachment_id');
});

it('hides draft materials from students', function () {
    [$course, $lecturer, $student] = courseWithMembers();
    CourseMaterial::factory()->for($course)->draft()->create();
    $this->actingAs($student)->getJson("/api/courses/{$course->id}/materials")->assertJsonCount(0, 'data');
    $this->actingAs($lecturer)->getJson("/api/courses/{$course->id}/materials")->assertJsonCount(1, 'data');
});

it('PRD 9.4 returns 409 when editing a stale version', function () {
    [$course, $lecturer] = courseWithMembers();
    $material = CourseMaterial::factory()->for($course)->create();
    $stale = $material->updated_at->toIso8601ZuluString('microsecond');
    $this->travel(2)->seconds();
    $material->touch();

    $this->actingAs($lecturer)->patchJson("/api/materials/{$material->id}", ['title' => 'New', 'updated_at' => $stale])
        ->assertStatus(409)->assertJsonStructure(['message', 'current_updated_at']);
    $this->actingAs($lecturer)->patchJson("/api/materials/{$material->id}", ['title' => 'New', 'updated_at' => $material->fresh()->updated_at->toIso8601ZuluString('microsecond')])
        ->assertOk()->assertJsonPath('data.title', 'New');
});

it('soft-deletes materials', function () {
    [$course, $lecturer] = courseWithMembers();
    $material = CourseMaterial::factory()->for($course)->create();
    $this->actingAs($lecturer)->deleteJson("/api/materials/{$material->id}")->assertOk();
    expect(CourseMaterial::withTrashed()->find($material->id)->trashed())->toBeTrue();
});
