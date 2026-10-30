<span class="account {{ ($large ?? false) ? 'account-large' : '' }}">
    @if ($account['avatar'])
        <img src="{{ $account['avatar'] }}" alt="" class="avatar">
    @else
        <span class="avatar avatar-empty">{{ mb_strtoupper(mb_substr($account['name'], 0, 1)) }}</span>
    @endif
    <span class="account-text">
        <span class="account-name">{{ $account['name'] }}</span>
        @if (! $compact)
            <span class="muted small">{{ $account['handle'] ? $account['handle'].' · ' : '' }}{{ $account['network'] }}</span>
        @else
            <span class="muted small">{{ $account['network'] }}</span>
        @endif
    </span>
</span>
