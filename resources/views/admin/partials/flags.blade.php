@php
    $items = array_values($flags ?? []);
    $limit = $limit ?? 8;
    $visible = array_slice($items, 0, $limit);
    $hidden = array_slice($items, $limit);
@endphp
@if ($items === [])
    <span class="text-muted">—</span>
@else
    <div class="d-flex flex-wrap gap-1">
        @foreach ($visible as $flag)
            <span class="badge text-bg-light text-dark fw-normal">{{ trans('voteguard::admin.flags.'.$flag) }}</span>
        @endforeach
        @if ($hidden !== [])
            <span class="badge text-bg-secondary"
                  title="{{ collect($hidden)->map(fn ($flag) => trans('voteguard::admin.flags.'.$flag))->implode(' · ') }}">
                +{{ count($hidden) }}
            </span>
        @endif
    </div>
@endif
