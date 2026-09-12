<?php

namespace App\Enums;

enum UserRole: string
{
    case Candidate = 'candidate';
    case Staff = 'staff';
    case Admin = 'admin';
}
