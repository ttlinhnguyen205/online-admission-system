<?php

namespace App\Enums;

enum AdmissionRoundStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Processing = 'processing';
    case Published = 'published';
}
