<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    public function run(): void
    {
        $password = Hash::make(self::PASSWORD);
        $make = fn (string $email, string $name, Role $role, ?string $number = null) => User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'role' => $role, 'student_number' => $number, 'password' => $password, 'is_active' => true, 'email_verified_at' => now()],
        );

        $make('admin@uniconnect.test', 'System Administrator', Role::Admin);
        $make('mziezo@uniconnect.test', 'Dr. Maria Ziezo', Role::Lecturer);
        $make('jshikongo@uniconnect.test', 'Prof. Johannes Shikongo', Role::Lecturer);

        $names = ['Anna Nghipandulwa', 'Ben Kapuka', 'Chloe Amutenya', 'David Iipinge', 'Ester Hamutenya',
            'Frans Nakale', 'Grace Shilongo', 'Hilma Nambahu', 'Isak Kandjii', 'Julia Mwafufya'];
        foreach ($names as $i => $name) {
            $n = $i + 1;
            $make("student{$n}@uniconnect.test", $name, Role::Student, sprintf('2026%04d', $n));
        }
    }
}
