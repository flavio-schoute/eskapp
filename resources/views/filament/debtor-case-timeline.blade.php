{{-- The debtor case timeline, newest first: notes from the team and what the Plug&Pay sync picked up. --}}
@php
    /** @var \App\Models\DebtorCase $case */
    $case = $getRecord();
    $events = $case->events()->latest('created_at')->latest('id')->get();
    $colors = [
        'danger' => 'var(--color-danger-600, #dc2626)',
        'success' => 'var(--color-success-600, #16a34a)',
        'primary' => 'var(--color-primary-600, #d97706)',
        'gray' => 'rgb(127 127 127)',
    ];
@endphp

<ol style="display: flex; flex-direction: column; gap: 0.75rem; margin: 0; padding: 0; list-style: none;">
    @forelse ($events as $event)
        <li style="display: flex; gap: 0.75rem;">
            <div style="flex-shrink: 0; width: 2rem; height: 2rem; border-radius: 9999px; display: flex; align-items: center; justify-content: center; background: rgb(127 127 127 / 0.1); color: {{ $colors[$event->color()] }};">
                <x-filament::icon :icon="$event->icon()" style="width: 1.1rem; height: 1.1rem;" />
            </div>
            <div style="flex: 1; min-width: 0; border-radius: 0.5rem; padding: 0.5rem 0.75rem; box-shadow: inset 0 0 0 1px rgb(127 127 127 / 0.2);">
                <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.5rem;">
                    <span style="font-weight: 600;">{{ $event->type === \App\Models\DebtorCaseEvent::Note ? $event->causer() : $event->title }}</span>
                    <span style="font-size: 0.8rem; opacity: 0.7;" title="{{ $event->created_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i') }}">
                        @if ($event->type !== \App\Models\DebtorCaseEvent::Note)
                            {{ $event->causer() }} ·
                        @endif
                        {{ $event->created_at->timezone('Europe/Amsterdam')->translatedFormat('j M Y, H:i') }}
                    </span>
                </div>
                @if (filled($event->body))
                    <div class="fi-prose" style="margin-top: 0.375rem;">{!! str($event->body)->sanitizeHtml() !!}</div>
                @endif
            </div>
        </li>
    @empty
        <li style="opacity: 0.7;">Nothing happened in this case yet.</li>
    @endforelse

    @if ($case->invoice_date)
        <li style="display: flex; gap: 0.75rem;">
            <div style="flex-shrink: 0; width: 2rem; height: 2rem; border-radius: 9999px; display: flex; align-items: center; justify-content: center; background: rgb(127 127 127 / 0.1); color: rgb(127 127 127);">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentText" style="width: 1.1rem; height: 1.1rem;" />
            </div>
            <div style="flex: 1; border-radius: 0.5rem; padding: 0.5rem 0.75rem; box-shadow: inset 0 0 0 1px rgb(127 127 127 / 0.2);">
                <span style="font-weight: 600;">Invoice {{ $case->invoice_number }} sent</span>
                <span style="font-size: 0.8rem; opacity: 0.7; float: right;">{{ $case->invoice_date->translatedFormat('j M Y') }}</span>
            </div>
        </li>
    @endif
</ol>
