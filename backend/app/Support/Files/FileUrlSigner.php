<?php

namespace App\Support\Files;

use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Creates short-lived presigned URLs for objects in the private bucket (SEC-4).
 *
 * The URL is signed for the host the client used to reach the gateway (e.g.
 * https://localhost or https://192.168.1.20), and Traefik proxies /{bucket}/* to
 * MinIO keeping that Host header, so the signature verifies and the link is
 * same-origin HTTPS (works on phones, no mixed content).
 */
class FileUrlSigner
{
    public function temporaryUrl(string $path, ?string $downloadName = null, ?string $mime = null, ?int $minutes = null): string
    {
        $minutes ??= (int) config('uniconnect.uploads.signed_url_minutes');
        $disk = Storage::disk();

        $params = [];
        if ($downloadName !== null) {
            $inline = $mime && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
            $params['ResponseContentDisposition'] = ($inline ? 'inline' : 'attachment')
                .'; filename="'.addcslashes($downloadName, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($downloadName);
        }
        if ($mime) {
            $params['ResponseContentType'] = $mime;
        }

        if (! $disk instanceof AwsS3V3Adapter) {
            // local / faked disk (tests)
            return $disk->temporaryUrl($path, now()->addMinutes($minutes), $params);
        }

        $config = config('filesystems.disks.s3');
        $client = new S3Client([
            'version' => 'latest',
            'region' => $config['region'],
            'endpoint' => $this->publicBase(),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
        ]);
        $command = $client->getCommand('GetObject', ['Bucket' => $config['bucket'], 'Key' => $path] + $params);

        return (string) $client->createPresignedRequest($command, "+{$minutes} minutes")->getUri();
    }

    private function publicBase(): string
    {
        // behind Traefik (trusted proxy) this is the scheme + host the browser used;
        // in the console Laravel builds the request from APP_URL
        $request = request();

        return $request?->getHost() ? $request->getSchemeAndHttpHost() : rtrim((string) config('app.url'), '/');
    }
}
