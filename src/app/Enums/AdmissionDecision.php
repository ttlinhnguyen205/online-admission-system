<?php

namespace App\Enums;

enum AdmissionDecision: string
{
    case Waiting = 'waiting';
    case Admitted = 'admitted';
    case NotAdmitted = 'not_admitted';
}
