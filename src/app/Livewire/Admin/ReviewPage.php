<?php

namespace App\Livewire\Admin;

use App\Actions\AdmissionReviewSnapshot;
use Livewire\Component;

abstract class ReviewPage extends Component
{
    public function boot(): void
    {
        AdmissionReviewSnapshot::reviewer();
    }
}
