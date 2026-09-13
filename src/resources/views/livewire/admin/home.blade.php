<section class="mx-auto flex w-full max-w-7xl flex-col gap-8">
    <div>
        <flux:text>ADMISSIONS ADMINISTRATION</flux:text>
        <flux:heading size="xl" level="1" class="mt-2">Admission configuration</flux:heading>
        <flux:text class="mt-3 max-w-2xl">Prepare each intake in one place. Set up rounds, maintain the major and method catalogs, then combine them into admission programs.</flux:text>
    </div>
    @cannot('create', App\Models\AdmissionRound::class)
        <flux:callout>You have read-only access. Configuration changes are managed by an active administrator.</flux:callout>
    @endcannot
    <div class="grid gap-5 sm:grid-cols-2">
        @foreach ([['admission-rounds', '01', 'Admission Rounds', 'Define intake years, application dates and round status.', App\Models\AdmissionRound::class], ['majors', '02', 'Majors', 'Maintain majors, descriptions and default tuition.', App\Models\Major::class], ['admission-methods', '03', 'Admission Methods', 'Configure admission methods and optional subject weights.', App\Models\AdmissionMethod::class], ['admission-programs', '04', 'Admission Programs', 'Connect catalogs to rounds with quotas, scores and tuition.', App\Models\AdmissionProgram::class]] as [$path, $step, $title, $description, $model])
            @can('viewAny', $model)
                <a wire:key="{{ $path }}" href="{{ route('admin.'.$path.'.index') }}" wire:navigate class="group flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-6 transition hover:border-zinc-400 focus-visible:outline-2 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-500">
                    <span class="text-sm font-semibold text-zinc-400">{{ $step }}</span>
                    <flux:heading size="lg">{{ $title }}</flux:heading>
                    <flux:text>{{ $description }}</flux:text>
                    <span class="text-sm font-medium underline underline-offset-4">Open {{ strtolower($title) }}</span>
                </a>
            @endcan
        @endforeach
    </div>
</section>
