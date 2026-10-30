@if ($hidden > 0)
    <p class="note">{{ __('analytics.insights.export.pdf.columns_note', ['shown' => $shown, 'total' => $shown + $hidden]) }}</p>
@endif
