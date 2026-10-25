<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** `make seed` — demo data from PRD §15. */
    public function run(): void
    {
        // seed quietly: no broadcasts or push for historical data
        config(['broadcasting.default' => 'null']);

        $this->call([
            UserSeeder::class,
            CourseSeeder::class,
            ChatSeeder::class,
        ]);
    }
}
