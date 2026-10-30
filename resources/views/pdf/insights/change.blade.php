@if ($change)
    <span class="change change-{{ $change['direction'] }} {{ $compact ? 'change-compact' : '' }}">
        @if ($change['direction'] === 'up')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>
        @elseif ($change['direction'] === 'down')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7l6 6 4-4 8 8"/><path d="M21 10v7h-7"/></svg>
        @else
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>
        @endif
        {{ $change['label'] }}
    </span>
@endif
