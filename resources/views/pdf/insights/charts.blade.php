@if (count($charts))
    <div class="charts">
        @foreach ($charts as $chart)
            <div class="chart">
                <div class="chart-head">
                    <span class="label">{{ $chart['title'] }}</span>
                    <span class="chart-total">{{ $chart['total'] }}</span>
                </div>
                {!! $chart['svg'] !!}
            </div>
        @endforeach
    </div>
@endif
