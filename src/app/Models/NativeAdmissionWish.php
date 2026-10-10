<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'admission_round_id', 'candidate_major_offering_id', 'major_id', 'priority'])]
class NativeAdmissionWish extends Model
{
    /** @return BelongsTo<CandidateMajorOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(CandidateMajorOffering::class, 'candidate_major_offering_id');
    }
}
