<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use App\Support\AdmissionCounselingHistory;
use App\Support\AdmissionCounselingProvider;
use App\Support\DisabledAdmissionCounselingProvider;
use App\Support\GeminiAdmissionCounselingProvider;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AdmissionCounselingProvider::class, function (): AdmissionCounselingProvider {
            return config('admission_chatbot.enabled') && config('admission_chatbot.provider') === 'gemini'
                ? new GeminiAdmissionCounselingProvider
                : new DisabledAdmissionCounselingProvider;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Gate::define('use-admission-counseling', fn (User $user): bool => $user->isActive() && $user->isCandidate() && $user->hasVerifiedEmail());
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User) {
                try {
                    app(AdmissionCounselingHistory::class)->clear($event->user);
                } catch (Throwable) {
                    Log::warning('Admission counseling history cleanup unavailable');
                }
            }
        });
        Livewire::addPersistentMiddleware([EnsureAccountIsActive::class]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->uncompromised()
        );
    }
}
