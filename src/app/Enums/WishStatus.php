<?php

namespace App\Enums;

enum WishStatus: string
{
    case Pending = 'pending';
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
    case Admitted = 'admitted';
    case Rejected = 'rejected';
}
