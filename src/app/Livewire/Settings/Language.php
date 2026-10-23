<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\App;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Cài đặt ngôn ngữ')]
class Language extends Component
{
    public string $locale = 'vi';

    public function mount(): void
    {
        $this->locale = 'vi';
    }

    public function updateLanguage(): void
    {
        $this->validate(['locale' => ['required', 'in:vi']]);

        session()->put('locale', $this->locale);
        session()->save();

        App::setLocale($this->locale);

        $this->redirect(
            route('language.edit'),
            navigate: false
        );
    }
}
