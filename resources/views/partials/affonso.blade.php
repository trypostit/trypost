@if(config('services.affonso.id'))
<script async defer src="{{ config('trypost.affonso.script_url') }}" data-affonso="{{ config('services.affonso.id') }}" data-cookie_duration="{{ config('services.affonso.cookie_duration') }}"></script>
@endif
