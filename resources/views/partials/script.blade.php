<script>
    window.__px = {
        o: @json(route('voteguard.session')),
        e: @json(route('voteguard.click')),
        c: @json(csrf_token())
    };
</script>
@if ($script !== '')
<script>{!! $script !!}</script>
@endif
<template><a href="{{ $trapUrl }}" data-vote-id="{{ $trapId }}" data-vote-url="{{ $trapUrl }}" target="_blank" rel="noopener">Vote</a></template>
