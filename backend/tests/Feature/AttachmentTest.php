<?php

use App\Models\Attachment;
use App\Models\CourseMaterial;
use App\Models\User;
use App\Support\Files\FileUrlSigner;
use Aws\S3\S3Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => fakeStorage());

it('SEC-5 stores uploads under random names with SHA-256', function () {
    $user = User::factory()->create();
    $file = pdfUpload('Lecture 1 – Intro.pdf');

    $res = $this->actingAs($user)->post('/api/attachments', ['file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Lecture 1 _ Intro.pdf')
        ->assertJsonPath('data.mime', 'application/pdf');

    $attachment = Attachment::find($res->json('data.id'));
    expect($attachment->path)->toMatch('#^attachments/\d{4}/\d{2}/[A-Za-z0-9]{40}\.pdf$#')
        ->and($attachment->sha256)->toBe(hash_file('sha256', $file->getRealPath()));
    Storage::disk('s3')->assertExists($attachment->path);
});

it('SEC-5 rejects disallowed extensions', function () {
    $this->actingAs(User::factory()->create())
        ->post('/api/attachments', ['file' => UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('file');
});

it('SEC-5 rejects content that does not match the extension', function () {
    $this->actingAs(User::factory()->create())
        ->post('/api/attachments', ['file' => UploadedFile::fake()->createWithContent('fake.pdf', '<?php echo 1;')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'does not match'));
});

it('SEC-5 rejects files over 20 MB', function () {
    $this->actingAs(User::factory()->create())
        ->post('/api/attachments', ['file' => UploadedFile::fake()->create('big.pdf', 21 * 1024, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertStatus(422);
});

it('SEC-4 redirects members to a short-lived signed URL', function () {
    [$course, , $student] = courseWithMembers();
    $material = CourseMaterial::factory()->for($course)->create();

    $location = $this->actingAs($student)->get("/api/attachments/{$material->attachment_id}")
        ->assertRedirect()
        ->headers->get('Location');
    expect($location)->toContain('expir'); // fake disk; the real S3 signature is covered below

    $this->actingAs($student)->getJson("/api/attachments/{$material->attachment_id}?json=1")
        ->assertOk()->assertJsonStructure(['url', 'expires_at', 'attachment' => ['id', 'name', 'mime', 'size']]);
});

it('SEC-4 denies files of courses the user is not in', function () {
    $material = CourseMaterial::factory()->create();
    $this->actingAs(User::factory()->student()->create())->getJson("/api/attachments/{$material->attachment_id}")->assertForbidden();
});

it('SEC-4 the local-disk signed URL serves the file with its name and type', function () {
    Storage::disk('s3')->put('attachments/a.pdf', '%PDF-1.4 test');
    $url = app(FileUrlSigner::class)->temporaryUrl('attachments/a.pdf', 'Week 1 notes.pdf', 'application/pdf');
    expect($url)->toContain('/api/files/attachments/a.pdf?')->toContain('expires=')->toContain('signature=');

    $res = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($res->headers->get('Content-Disposition'))->toStartWith('inline')->toContain('Week 1 notes.pdf')
        ->and($res->streamedContent())->toBe('%PDF-1.4 test');
});

it('SEC-4 the local-disk URL rejects tampering and expiry', function () {
    Storage::disk('s3')->put('attachments/a.pdf', '%PDF-1.4 test');
    Storage::disk('s3')->put('attachments/b.pdf', '%PDF-1.4 other');
    $url = app(FileUrlSigner::class)->temporaryUrl('attachments/a.pdf', 'a.pdf', 'application/pdf');

    $this->get(str_replace('attachments/a.pdf', 'attachments/b.pdf', $url))->assertForbidden();
    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});

it('SEC-4 the S3 signer produces an expiring URL on the request host', function () {
    config(['filesystems.disks.s3' => array_merge(config('filesystems.disks.s3'), ['key' => 'k', 'secret' => 's', 'region' => 'us-east-1', 'bucket' => 'uc-private'])]);
    Storage::forgetDisk('s3');
    $this->app['request']->headers->set('HOST', 'uni.example');
    $url = app(FileUrlSigner::class)->temporaryUrl('attachments/a.pdf', 'a.pdf', 'application/pdf');
    expect($url)->toMatch('~^https?://uni\.example/uc-private/attachments/a\.pdf\?~')
        ->toContain('X-Amz-Expires=300')
        ->toContain('X-Amz-Signature=');
})->skip(fn () => ! class_exists(S3Client::class));
