<?php

namespace App\Enums;

enum ProfileStatus: string
{
    case Incomplete = 'incomplete';
    case Complete = 'complete';
    case NeedsRevision = 'needs_revision';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
