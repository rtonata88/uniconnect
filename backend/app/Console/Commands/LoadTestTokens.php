<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Issues API tokens for the load-test users (k6 must not go through the login rate limits)
 * and writes them with the ids each user needs to loadtests/.out/tokens.json.
 */
class LoadTestTokens extends Command
{
    protected $signature = 'loadtest:tokens {--count=500} {--out=../loadtests/.out/tokens.json}';

    protected $description = 'Create tokens for lt_* users and write them for k6';

    public function handle(): int
    {
        $users = User::where('email', 'like', 'lt_student%')->orderBy('email')->limit((int) $this->option('count'))->get();
        if ($users->isEmpty()) {
            $this->error('No load-test users. Run: php artisan db:seed --class=LoadTestSeeder');

            return self::FAILURE;
        }
        $big = Course::where('code', 'LTBIG')->first();
        $lecturer = User::where('email', 'lt_lecturer1@uniconnect.test')->first();
        $out = [];
        foreach ($users as $user) {
            $user->tokens()->where('name', 'loadtest')->delete();
            $course = $user->courses()->where('code', '!=', 'LTBIG')->first();
            $out[] = [
                'id' => $user->id,
                'token' => $user->createToken('loadtest', ['*', 'loadtest'], now()->addDay())->plainTextToken,
                'course_id' => $course?->id,
                'conversation_id' => $course?->conversation?->id,
                'conversation_channel' => $course?->conversation?->channelName(),
            ];
        }
        $lecturer->tokens()->where('name', 'loadtest')->delete();
        $payload = [
            'users' => $out,
            'big' => [
                'course_id' => $big->id,
                'conversation_id' => $big->conversation->id,
                'channel' => $big->conversation->fresh()->channelName(),
                'sender_token' => $lecturer->createToken('loadtest', ['*', 'loadtest'], now()->addDay())->plainTextToken,
                'sender_id' => $lecturer->id,
            ],
        ];
        $path = base_path($this->option('out'));
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($payload));
        $this->info('wrote '.count($out)." tokens to {$path}");

        return self::SUCCESS;
    }
}
