<?php

namespace App\Actions;

use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\CandidateMajorOffering;
use Illuminate\Database\Eloquent\Collection;

class CandidateMajorOfferings
{
    public static function available(?CandidateMajorOffering $offering, ?AdmissionProgram $program, AdmissionRound $round): bool
    {
        return $offering !== null && $program !== null
            && $offering->getAttribute('is_selectable')
            && (int) $offering->getAttribute('admission_round_id') === (int) $round->getKey()
            && (int) $offering->getAttribute('major_id') === (int) $program->getAttribute('major_id')
            && (int) $offering->getAttribute('admission_program_id') === (int) $program->getKey()
            && CandidateWishes::unavailableReason($program, $round) === null;
    }

    /**
     * Registration only: never filter reviewed engine inputs by current availability.
     *
     * @return Collection<int, CandidateMajorOffering>
     */
    public static function choices(AdmissionRound $round): Collection
    {
        if (! CandidateApplications::roundIsOpen($round)) {
            return new Collection;
        }

        return CandidateMajorOffering::query()->whereBelongsTo($round)->where('is_selectable', true)
            ->with(['major', 'admissionProgram.major', 'admissionProgram.admissionMethod'])
            ->orderBy('id')->get()
            ->filter(fn (CandidateMajorOffering $offering): bool => self::available($offering, $offering->admissionProgram, $round));
    }

    /**
     * Caller holds application/wish locks; offerings are locked before programs.
     *
     * @param  Collection<int, AdmissionWish>  $wishes
     * @return Collection<int, CandidateMajorOffering>
     */
    public static function lockForWishes(Collection $wishes): Collection
    {
        return CandidateMajorOffering::query()->whereKey($wishes->pluck('candidate_major_offering_id')->filter()->unique()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }
}
