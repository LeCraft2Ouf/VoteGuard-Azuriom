@php
    $parts = [
        'sniper' => (int) ($mix['snipers'] ?? 0),
        'tight' => (int) ($mix['tight'] ?? 0),
        'classic' => (int) ($mix['classic'] ?? 0),
        'sleep' => (int) ($mix['sleeps'] ?? 0),
    ];
    $sum = max(1, array_sum($parts));
    $colors = [
        'sniper' => 'bg-danger',
        'tight' => 'bg-warning',
        'classic' => 'bg-success',
        'sleep' => 'bg-secondary',
    ];
@endphp
<div class="vg-mix d-flex rounded overflow-hidden mb-2">
    @foreach ($parts as $kind => $count)
        @if ($count > 0)
            <div class="{{ $colors[$kind] }}" style="width: {{ round($count / $sum * 100, 1) }}%"
                 title="{{ trans('voteguard::admin.legend.'.$kind) }} : {{ $count }}"></div>
        @endif
    @endforeach
</div>
<div class="d-flex flex-wrap gap-2 small text-muted">
    <span><span class="badge text-bg-danger vg-kind">{{ trans('voteguard::admin.kind.sniper') }}</span> {{ $parts['sniper'] }}</span>
    <span><span class="badge text-bg-warning text-dark vg-kind">{{ trans('voteguard::admin.kind.tight') }}</span> {{ $parts['tight'] }}</span>
    <span><span class="badge text-bg-success vg-kind">{{ trans('voteguard::admin.kind.classic') }}</span> {{ $parts['classic'] }}</span>
    <span><span class="badge text-bg-secondary vg-kind">{{ trans('voteguard::admin.kind.sleep') }}</span> {{ $parts['sleep'] }}</span>
</div>
