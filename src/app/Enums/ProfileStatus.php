<?php

namespace App\Enums;

enum ProfileStatus: string
{
    case Incomplete = 'incomplete';
    case Complete = 'complete';
    case Verified = 'verified';
}
