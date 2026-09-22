<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\App;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Language settings')]
class Language extends Component
{
    public string $locale = 'vi';

    public function mount(): void
    {
        $this->locale = session()->get(
            'locale',
            config('app.locale', 'vi')
        );
    }

    public function updateLanguage(): void
    {
        if (! in_array($this->locale, ['vi', 'en'], true)) {
            return;
        }

        // Lưu lựa chọn vào session
        session()->put('locale', $this->locale);
        session()->save();

        // Áp dụng ngay cho request hiện tại
        App::setLocale($this->locale);

        // Reload toàn bộ trang để middleware đọc locale mới
        $this->redirect(
            route('language.edit'),
            navigate: false
        );
    }
}
