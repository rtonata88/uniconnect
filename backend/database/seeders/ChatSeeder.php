<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Course;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PRD §15: course chats with some history, plus a few direct and group chats.
 * Messages are written directly (no broadcasts) with realistic times and receipts.
 */
class ChatSeeder extends Seeder
{
    public function run(): void
    {
        $u = fn (string $email) => User::where('email', $email)->firstOrFail();
        $lecturer = $u('mziezo@uniconnect.test');
        $s1 = $u('student1@uniconnect.test');
        $s2 = $u('student2@uniconnect.test');
        $s3 = $u('student3@uniconnect.test');

        $dms = Course::where('code', 'DMS901')->first()->conversation;
        $this->history($dms, [
            [$lecturer, 'Welcome to the DMS901 course chat! Ask questions about the labs here.', 50],
            [$s1, 'Thanks! Is the replication lab due this week?', 48],
            [$lecturer, 'Yes, Friday 17:00. Make sure the replica shows Seconds_Behind_Source.', 47],
            [$s2, 'Can we run the replica on a second laptop?', 30],
            [$lecturer, 'Absolutely — that is the recommended setup for the demo.', 29],
            [$s3, 'Is Traefik discovering new API instances automatically for everyone?', 6],
        ]);
        foreach (Course::where('code', '!=', 'DMS901')->get() as $course) {
            $this->history($course->conversation, [
                [$course->lecturers()->first(), "Welcome to the {$course->code} chat.", 40],
            ]);
        }

        $direct = Conversation::create(['type' => 'direct', 'direct_key' => Conversation::directKey($lecturer->id, $s1->id), 'created_by' => $s1->id]);
        $this->join($direct, [$s1, $lecturer]);
        $this->history($direct, [
            [$s1, 'Good morning Dr. Ziezo, could I come to your office hours tomorrow?', 20],
            [$lecturer, 'Sure, 10:00 works. Bring your design report draft.', 19],
            [$s1, 'Thank you!', 19],
        ]);

        $direct2 = Conversation::create(['type' => 'direct', 'direct_key' => Conversation::directKey($s1->id, $s2->id), 'created_by' => $s2->id]);
        $this->join($direct2, [$s1, $s2]);
        $this->history($direct2, [
            [$s2, 'Did you finish the k6 load test script?', 3],
            [$s1, 'Almost, the WebSocket part is tricky 😅', 2],
        ], readAll: false);

        $group = Conversation::create(['type' => 'group', 'title' => 'DMS901 project team', 'created_by' => $s1->id]);
        $this->join($group, [$s1], 'admin');
        $this->join($group, [$s2, $s3]);
        $this->history($group, [
            [null, "{$s1->name} created the group \"DMS901 project team\"", 26],
            [$s1, 'Let\'s split the work: I take the backend, Ben the PWA, Chloe the tests.', 25],
            [$s2, 'Deal. I\'ll start with the service worker.', 24],
            [$s3, 'I\'ll set up Playwright and the k6 scripts.', 23],
        ]);
    }

    private function join(Conversation $c, array $users, string $role = 'member'): void
    {
        foreach ($users as $user) {
            $c->participants()->firstOrCreate(['user_id' => $user->id], ['role' => $role, 'joined_at' => now()->subDays(60)]);
        }
    }

    /** @param  list<array{0: ?User, 1: string, 2: int}>  $rows  [sender, body, hours ago] */
    private function history(Conversation $c, array $rows, bool $readAll = true): void
    {
        $participants = $c->participants()->get();
        $last = null;
        foreach ($rows as [$sender, $body, $hoursAgo]) {
            $at = Carbon::now()->subHours($hoursAgo);
            $m = new Message([
                'conversation_id' => $c->id,
                'sender_id' => $sender?->id,
                'client_uuid' => (string) Str::uuid(),
                'type' => $sender ? 'text' : 'system',
                'body' => $body,
            ]);
            $m->created_at = $at;
            $m->updated_at = $at;
            $m->save();
            $last = $m;
            if ($sender) {
                foreach ($participants->where('user_id', '!=', $sender->id) as $p) {
                    MessageReceipt::create(['message_id' => $m->id, 'user_id' => $p->user_id, 'delivered_at' => $at->copy()->addMinute(), 'read_at' => $readAll ? $at->copy()->addMinutes(5) : null]);
                }
            }
        }
        if ($last) {
            $c->forceFill(['last_message_id' => $last->id, 'last_message_at' => $last->created_at])->save();
            $c->participants()->update(['last_delivered_message_id' => $last->id]);
            if ($readAll) {
                $c->participants()->update(['last_read_message_id' => $last->id]);
            } else {
                $c->participants()->where('user_id', $last->sender_id)->update(['last_read_message_id' => $last->id]);
            }
        }
    }
}
