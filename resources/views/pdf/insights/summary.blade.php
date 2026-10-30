<div class="summary">
    @foreach ($cards as $card)
        <div class="summary-cell">
            <p class="label">{{ $card['label'] }}</p>
            <p class="summary-value">
                <span class="big">{{ $card['value'] }}</span>
                @include('pdf.insights.change', ['change' => $card['change'], 'compact' => false])
            </p>
        </div>
    @endforeach
</div>
