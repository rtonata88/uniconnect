<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** `make seed` — demo data from PRD §15. */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            CourseSeeder::class,
        ]);
    }
}
