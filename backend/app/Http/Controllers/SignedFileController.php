<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Files
 */
class SignedFileController extends Controller
{
    /**
     * Download a file from local storage (SEC-4)
     *
     * Only used when files are stored on the server's disk (FILESYSTEM_DISK=local, e.g. on Forge).
     * The URL comes from `GET /api/attachments/{id}` and is signed and short-lived, like an S3
     * presigned URL; no token is needed.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, string $path): StreamedResponse
    {
        $disk = Storage::disk();
        abort_unless($disk->exists($path), 404);

        // The query is covered by the signature and comes from the upload allowlist (SEC-5); as a
        // second line of defence only images and PDFs may render in the browser.
        $type = (string) $request->query('type');
        $inline = $request->boolean('inline') && (str_starts_with($type, 'image/') || $type === 'application/pdf') && $type !== 'image/svg+xml';

        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300'];
        if ($type !== '') {
            $headers['Content-Type'] = $type;
        }

        return $disk->response($path, $request->query('name') ?? basename($path), $headers, $inline ? 'inline' : 'attachment');
    }
}
