@php
    $key = $kind ?: 'first';
    $class = match ($key) {
        'sniper' => 'text-bg-danger',
        'tight' => 'text-bg-warning text-dark',
        'near' => 'text-bg-info',
        'classic' => 'text-bg-success',
        'sleep' => 'text-bg-secondary',
        'early' => 'text-bg-dark',
        default => 'text-bg-light text-dark',
    };
@endphp
<span class="badge {{ $class }} vg-kind">{{ trans('voteguard::admin.kind.'.$key) }}</span>
