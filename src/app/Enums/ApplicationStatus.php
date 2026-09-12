<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case NeedsRevision = 'needs_revision';
    case Verified = 'verified';
    case Processing = 'processing';
    case Completed = 'completed';
}
