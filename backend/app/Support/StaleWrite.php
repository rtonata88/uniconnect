<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Optimistic concurrency for lecturer-edited records (PRD 9.4): the client sends the
 * `updated_at` it edited; if the record changed since, the API answers 409.
 */
class StaleWrite
{
    public static function check(Model $model, ?string $clientUpdatedAt): void
    {
        if ($clientUpdatedAt === null || $model->updated_at === null) {
            return;
        }
        $client = \Illuminate\Support\Carbon::parse($clientUpdatedAt);
        if ($model->updated_at->toIso8601ZuluString() !== $client->toIso8601ZuluString()) {
            throw new HttpResponseException(response()->json([
                'message' => 'This item was changed by someone else. Reload and try again.',
                'current_updated_at' => $model->updated_at->toIso8601ZuluString(),
            ], 409));
        }
    }
}
