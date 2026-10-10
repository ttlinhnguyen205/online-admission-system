<section class="mx-auto flex w-full max-w-7xl flex-col gap-8">
    <div>
        <flux:text>{{ __('ADMISSIONS ADMINISTRATION') }}</flux:text>

        <flux:heading size="xl" level="1" class="mt-2">
            {{ __('Admission configuration') }}
        </flux:heading>

        <flux:text class="mt-3 max-w-2xl">
            {{ __('Prepare each intake in one place. Set up rounds, maintain the major and method catalogs, then combine them into admission programs.') }}
        </flux:text>
    </div>

    @cannot('create', App\Models\AdmissionRound::class)
    <flux:callout>
        {{ __('You have read-only access. Configuration changes are managed by an active administrator.') }}
    </flux:callout>
    @endcannot

    <div class="grid gap-5 sm:grid-cols-2">
        @foreach ([
        [
        'admission-rounds',
        '01',
        'Admission Rounds',
        'Define intake years, application dates and round status.',
        'Open admission rounds',
        App\Models\AdmissionRound::class
        ],
        [
        'majors',
        '02',
        'Majors',
        'Maintain majors, descriptions and default tuition.',
        'Open majors',
        App\Models\Major::class
        ],
        [
        'admission-methods',
        '03',
        'Admission Methods',
        'Configure admission methods and optional subject weights.',
        'Open admission methods',
        App\Models\AdmissionMethod::class
        ],
        [
        'admission-programs',
        '04',
        'Admission Programs',
        'Connect catalogs to rounds with quotas, scores and tuition.',
        'Open admission programs',
        App\Models\AdmissionProgram::class
        ]
        ] as [$path, $step, $title, $description, $openLabel, $model])

        @can('viewAny', $model)
        <a
            wire:key="{{ $path }}"
            href="{{ route('admin.'.$path.'.index') }}"
            wire:navigate
            class="admission-panel group flex flex-col items-start gap-4 p-6 transition hover:border-rose-200 hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-admission-blue">
            <span class="flex size-10 items-center justify-center rounded-lg bg-admission-soft text-sm font-bold text-admission-blue dark:bg-rose-400/10 dark:text-rose-200">
                {{ $step }}
            </span>

            <flux:heading size="lg">
                {{ __($title) }}
            </flux:heading>

            <flux:text>
                {{ __($description) }}
            </flux:text>

            <span class="mt-auto text-sm font-semibold text-admission-blue group-hover:underline dark:text-rose-200">
                {{ __($openLabel) }}
            </span>
        </a>
        @endcan
        @endforeach
    </div>
</section>
