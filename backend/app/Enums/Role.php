<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Lecturer = 'lecturer';
    case Student = 'student';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
