<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.insights.styles')
</head>
<body>
    <section class="cover">
        <div class="cover-brand">
            @if ($logo)
                <img src="{{ $logo }}" alt="" class="cover-logo">
            @else
                <span class="cover-mark" aria-hidden="true"></span>
            @endif
        </div>
        <div class="cover-body">
            <p class="eyebrow">{{ __('analytics.insights.export.pdf.cover.eyebrow') }}</p>
            <h1 class="cover-title">{{ $title }}</h1>
            <p class="cover-range">{{ $range }}</p>
            <p class="muted">{{ __('analytics.insights.export.compared_to') }} {{ $previous_range }}</p>
        </div>
        @if (count($cover_channels) && ($performance ?? null) === null)
            <div class="cover-channels">
                <p class="label">{{ trans_choice('analytics.insights.export.pdf.cover.channels', count($cover_channels), ['count' => count($cover_channels)]) }}</p>
                <div class="chip-list">
                    @foreach ($cover_channels as $channel)
                        @include('pdf.insights.account', ['account' => $channel, 'compact' => true])
                    @endforeach
                </div>
            </div>
        @endif
        <p class="cover-generated muted">{{ __('analytics.insights.export.generated_at') }} {{ $generated_at }}</p>
    </section>

    @if ($summary !== null)
        <section class="section">
            @include('pdf.insights.heading', ['heading' => __('analytics.dashboard.summary'), 'subtitle' => $range])
            @include('pdf.insights.summary', ['cards' => $summary])
        </section>
    @endif

    @if ($scope === 'workspace')
        @if ($top_reactions !== null)
            <section class="section">
                @include('pdf.insights.heading', ['heading' => trans_choice('analytics.insights.export.pdf.top_posts.by_reactions', count($top_reactions), ['count' => count($top_reactions)]), 'subtitle' => $range])
                @include('pdf.insights.top-posts', ['posts' => $top_reactions])
            </section>
        @endif

        @if ($top_comments !== null)
            <section class="section">
                @include('pdf.insights.heading', ['heading' => trans_choice('analytics.insights.export.pdf.top_posts.by_comments', count($top_comments), ['count' => count($top_comments)]), 'subtitle' => $range])
                @include('pdf.insights.top-posts', ['posts' => $top_comments])
            </section>
        @endif

        @if ($performance !== null)
            <section class="section">
                @include('pdf.insights.heading', ['heading' => __('analytics.dashboard.performance'), 'subtitle' => __('analytics.insights.export.compared_to').' '.$previous_range])
                @if (count($performance['rows']))
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('analytics.dashboard.channel') }}</th>
                                @foreach ($performance['columns'] as $column)
                                    <th class="num">{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($performance['rows'] as $row)
                                <tr>
                                    <td>@include('pdf.insights.account', ['account' => $row['account'], 'compact' => false])</td>
                                    @foreach ($row['cells'] as $cell)
                                        <td class="num">
                                            <span class="value">{{ $cell['value'] }}</span>
                                            @include('pdf.insights.change', ['change' => $cell['change'], 'compact' => true])
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @include('pdf.insights.columns-note', ['hidden' => $performance['hidden'], 'shown' => count($performance['columns'])])
                @else
                    <p class="empty">{{ __('analytics.dashboard.no_performance') }}</p>
                @endif
            </section>
        @endif

        @if ($channels !== null)
            @foreach ($channels as $channel)
                <section class="section channel-part">
                    <div class="channel-head">
                        @include('pdf.insights.account', ['account' => $channel['account'], 'compact' => false, 'large' => true])
                        <span class="muted">{{ $range }}</span>
                    </div>
                    @include('pdf.insights.summary', ['cards' => $channel['summary']])
                    @include('pdf.insights.charts', ['charts' => $channel['charts']])
                </section>
            @endforeach
            @if ($channels_total > count($channels))
                <p class="note">{{ __('analytics.insights.export.pdf.channels_note', ['shown' => count($channels), 'total' => $channels_total]) }}</p>
            @endif
        @endif
    @else
        @if ($metrics !== null)
            <section class="section">
                @include('pdf.insights.heading', ['heading' => __('analytics.channel.metrics_card.title'), 'subtitle' => $range])
                @include('pdf.insights.charts', ['charts' => $metrics])
            </section>
        @endif

        @if ($posts !== null)
            <section class="section">
                @include('pdf.insights.heading', ['heading' => __('analytics.channel.performance'), 'subtitle' => $range])
                @if (count($posts['rows']))
                    <table class="table posts-table">
                        <thead>
                            <tr>
                                <th>{{ trans_choice('analytics.insights.export.pdf.posts_count', count($posts['rows']), ['count' => count($posts['rows'])]) }}</th>
                                @foreach ($posts['columns'] as $column)
                                    <th class="num">{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($posts['rows'] as $row)
                                <tr>
                                    <td>
                                        <div class="post-cell">
                                            <span class="rank">#{{ $row['rank'] }}</span>
                                            @if ($row['thumbnail'])
                                                <img src="{{ $row['thumbnail'] }}" alt="" class="thumb">
                                            @else
                                                <span class="thumb thumb-empty"></span>
                                            @endif
                                            <span class="post-text">
                                                <span class="excerpt">{{ $row['excerpt'] }}</span>
                                                <span class="muted small">{{ $row['date'] }}@if ($row['type']) · {{ $row['type'] }}@endif</span>
                                            </span>
                                        </div>
                                    </td>
                                    @foreach ($row['cells'] as $cell)
                                        <td class="num value">{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @include('pdf.insights.columns-note', ['hidden' => $posts['hidden'], 'shown' => count($posts['columns'])])
                    @if ($posts['total'] > count($posts['rows']))
                        <p class="note">{{ __('analytics.insights.export.pdf.posts_note', ['shown' => count($posts['rows']), 'total' => $posts['total']]) }}</p>
                    @endif
                @else
                    <p class="empty">{{ __('analytics.channel.no_posts') }}</p>
                @endif
            </section>
        @endif
    @endif
</body>
</html>
