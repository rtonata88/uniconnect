<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Attachment> */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'disk' => 's3',
            'path' => 'attachments/test/'.Str::random(40).'.pdf',
            'original_name' => 'notes.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1234,
            'sha256' => hash('sha256', Str::random()),
        ];
    }
}
