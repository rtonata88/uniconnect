<?php

namespace App\Http\Controllers;

use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Support\Files\AttachmentService;
use App\Support\Files\FileUrlSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * @group Files
 */
class AttachmentController extends Controller
{
    /**
     * Upload a file (SEC-5)
     *
     * Multipart upload, max 20 MB. Allowed: pdf, doc(x), ppt(x), xlsx, txt, csv, zip and images.
     * The returned id is then sent with the material, submission or message.
     *
     * @bodyParam file file required The file.
     */
    public function store(Request $request, AttachmentService $files): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.config('uniconnect.uploads.max_kb')]]);
        $attachment = $files->store($request->file('file'), $request->user());

        return (new AttachmentResource($attachment))->response()->setStatusCode(201);
    }

    /**
     * Download a file (SEC-4)
     *
     * Checks the policy, then redirects (302) to a presigned URL valid for 5 minutes.
     * With `?json=1` it returns the URL instead (for <img> tags and offline downloads).
     *
     * @queryParam json boolean Return JSON instead of redirecting. Example: 1
     */
    public function show(Request $request, Attachment $attachment, FileUrlSigner $signer): RedirectResponse|JsonResponse
    {
        Gate::authorize('view', $attachment);
        $url = $signer->temporaryUrl($attachment->path, $attachment->original_name, $attachment->mime);

        if ($request->boolean('json')) {
            return response()->json([
                'url' => $url,
                'expires_at' => now()->addMinutes((int) config('uniconnect.uploads.signed_url_minutes'))->toIso8601ZuluString(),
                'attachment' => new AttachmentResource($attachment),
            ]);
        }

        return redirect()->away($url);
    }
}
