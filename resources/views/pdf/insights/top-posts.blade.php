@if (count($posts))
    <div class="top-posts">
        @foreach ($posts as $post)
            <div class="top-post">
                <div class="top-post-head">
                    <span>
                        <span class="big">{{ $post['value'] }}</span>
                        <span class="muted small block">{{ $post['metric'] }}</span>
                    </span>
                    <span class="top-post-meta">
                        <span class="rank-badge">#{{ $post['rank'] }}</span>
                        <span class="muted small">{{ $post['date'] }}</span>
                    </span>
                </div>
                @include('pdf.insights.account', ['account' => $post['account'], 'compact' => true])
                <div class="top-post-body">
                    <p class="excerpt">{{ $post['excerpt'] }}</p>
                    @if ($post['thumbnail'])
                        <img src="{{ $post['thumbnail'] }}" alt="" class="thumb">
                    @endif
                </div>
                <p class="top-post-via muted small">{{ __('analytics.dashboard.published_via') }} {{ $post['via'] }}</p>
            </div>
        @endforeach
    </div>
@else
    <p class="empty">{{ __('analytics.dashboard.no_ranked_posts') }}</p>
@endif
