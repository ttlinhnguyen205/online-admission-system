<?php

namespace App\Livewire\Admin;

use App\Models\AdmissionRound;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Admission configuration')]
class Home extends Component
{
    public function boot(): void
    {
        Auth::user()?->refresh();
        Gate::authorize('viewAny', AdmissionRound::class);
    }
}
