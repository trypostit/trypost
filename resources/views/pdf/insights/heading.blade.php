<div class="heading">
    <h2>{{ $heading }}</h2>
    @if (filled($subtitle ?? null))
        <p class="muted small">{{ $subtitle }}</p>
    @endif
</div>
