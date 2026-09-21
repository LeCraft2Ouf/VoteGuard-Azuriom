<script>
    window.VoteGuard = {
        sessionUrl: @json(route('voteguard.session')),
        clickUrl: @json(route('voteguard.click')),
        csrf: @json(csrf_token()),
        blocked: @json($blocked ?? false),
        blockedMessage: @json($blockedMessage ?? trans('voteguard::messages.blocked'))
    };
</script>
<script src="{{ plugin_asset('voteguard', 'js/guard.js') }}?v=1.1.2" defer></script>
