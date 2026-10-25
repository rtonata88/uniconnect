<?php

namespace App\Support\Files;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SEC-5 upload handling: extension + detected MIME allow-list, 20 MB limit,
 * random object names, SHA-256 recorded.
 */
class AttachmentService
{
    /** @param  list<string>|null  $onlyExtensions  further restrict the allow-list (e.g. materials) */
    public function store(UploadedFile $file, User $owner, ?array $onlyExtensions = null): Attachment
    {
        $allowed = config('uniconnect.uploads.allowed');
        $ext = mb_strtolower($file->getClientOriginalExtension());
        // detected from the bytes (libmagic), never from the client's Content-Type or the extension
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! isset($allowed[$ext]) || ($onlyExtensions !== null && ! in_array($ext, $onlyExtensions, true))) {
            throw ValidationException::withMessages(['file' => "Files of type .{$ext} are not allowed."]);
        }
        if (! in_array($mime, $allowed[$ext], true)) {
            throw ValidationException::withMessages(['file' => "The file content ({$mime}) does not match its .{$ext} extension."]);
        }
        if ($file->getSize() > config('uniconnect.uploads.max_kb') * 1024) {
            throw ValidationException::withMessages(['file' => 'Files may be at most 20 MB.']);
        }

        // store the declared office type rather than the generic zip container type
        $storedMime = $mime === 'application/zip' && $ext !== 'zip' ? ($allowed[$ext][0]) : $mime;
        $path = 'attachments/'.now()->format('Y/m').'/'.Str::random(40).'.'.$ext;
        Storage::put($path, fopen($file->getRealPath(), 'r'), ['ContentType' => $storedMime]);

        return Attachment::create([
            'owner_id' => $owner->id,
            'disk' => config('filesystems.default'),
            'path' => $path,
            'original_name' => $this->safeName($file->getClientOriginalName(), $ext),
            'mime' => $storedMime,
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);
    }

    private function safeName(string $name, string $ext): string
    {
        $base = trim(preg_replace('/[^\pL\pN\s._()-]+/u', '_', pathinfo($name, PATHINFO_FILENAME)) ?? '', ' ._');

        return Str::limit($base ?: 'file', 150, '').'.'.$ext;
    }

    /**
     * An attachment may only be linked to new content by the user who uploaded it.
     */
    public function assertOwned(?int $attachmentId, User $user, string $field = 'attachment_id'): ?Attachment
    {
        if ($attachmentId === null) {
            return null;
        }
        $attachment = Attachment::find($attachmentId);
        if (! $attachment || $attachment->owner_id !== $user->id) {
            throw ValidationException::withMessages([$field => 'Unknown attachment.']);
        }

        return $attachment;
    }
}
