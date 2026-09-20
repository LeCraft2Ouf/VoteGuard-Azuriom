<script>
    window.VoteGuard = {
        sessionUrl: @json(route('voteguard.session')),
        clickUrl: @json(route('voteguard.click')),
        csrf: @json(csrf_token())
    };
</script>
<script src="{{ plugin_asset('voteguard', 'js/guard.js') }}" defer></script>
