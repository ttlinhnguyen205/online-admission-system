<?php

namespace App\Enums;

enum CertificateType: string
{
    case Ielts = 'ielts';
    case Toeic = 'toeic';
    case Sat = 'sat';
}
