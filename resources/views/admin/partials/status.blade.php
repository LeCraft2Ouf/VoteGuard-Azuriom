@php
    $key = $status ?: 'watch';
    $badge = match ($key) {
        'likely', 'confirmed' => 'danger',
        'suspect' => 'warning',
        'clear' => 'success',
        'false_positive' => 'secondary',
        default => 'info',
    };
@endphp
<span class="badge text-bg-{{ $badge }}">{{ trans('voteguard::admin.status.'.$key) }}</span>
