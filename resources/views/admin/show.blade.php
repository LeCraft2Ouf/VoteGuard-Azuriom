@extends('admin.layouts.admin')

@section('title', trans('voteguard::admin.show.title', ['name' => $suspect->user->name ?? $suspect->user_id]))

@section('content')
    @include('voteguard::admin.partials.styles')

    @php
        $sites = collect($intervals)->pluck('site')->unique()->values();
        $rowClass = fn (string $kind) => match ($kind) {
            'sniper' => 'table-danger',
            'tight' => 'table-warning',
            default => '',
        };
    @endphp

    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <a href="{{ route('voteguard.admin.index') }}" class="btn btn-secondary">{{ trans('messages.actions.back') }}</a>
        <div class="small text-muted">{{ trans('voteguard::admin.show.hint') }}</div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <span>{{ trans('voteguard::admin.show.intervals') }}</span>
                    @if ($sites->count() > 1)
                        <div class="btn-group btn-group-sm" role="group" id="vg-site-filter">
                            <button type="button" class="btn btn-outline-secondary active" data-vg-site="">
                                {{ trans('voteguard::admin.filter_site') }}
                            </button>
                            @foreach ($sites as $site)
                                <button type="button" class="btn btn-outline-secondary" data-vg-site="{{ $site }}">
                                    {{ $site }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="px-3 pt-3 pb-0 small d-flex flex-wrap gap-2">
                    <span><span class="badge text-bg-danger">{{ trans('voteguard::admin.kind.sniper') }}</span> {{ trans('voteguard::admin.legend.sniper') }}</span>
                    <span><span class="badge text-bg-warning text-dark">{{ trans('voteguard::admin.kind.tight') }}</span> {{ trans('voteguard::admin.legend.tight') }}</span>
                    <span><span class="badge text-bg-success">{{ trans('voteguard::admin.kind.classic') }}</span> {{ trans('voteguard::admin.legend.classic') }}</span>
                    <span><span class="badge text-bg-secondary">{{ trans('voteguard::admin.kind.sleep') }}</span> {{ trans('voteguard::admin.legend.sleep') }}</span>
                </div>
                <div class="table-responsive vg-intervals-wrap">
                    <table class="table vg-intervals mb-0" id="vg-intervals">
                        <thead>
                        <tr>
                            <th>{{ trans('voteguard::admin.fields.date') }}</th>
                            <th>{{ trans('voteguard::admin.fields.site') }}</th>
                            <th>{{ trans('voteguard::admin.fields.gap') }}</th>
                            <th>{{ trans('voteguard::admin.fields.delta') }}</th>
                            <th>{{ trans('voteguard::admin.fields.kind') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($intervals as $row)
                            <tr class="{{ $rowClass($row['kind'] ?? '') }}" data-site="{{ $row['site'] }}">
                                <td class="text-nowrap">{{ $row['at_label'] ?? format_date_compact($row['at']) }}</td>
                                <td>{{ $row['site'] }}</td>
                                <td class="text-nowrap">
                                    {{ $row['gap_label'] }}
                                    <span class="text-muted small">/ {{ $row['expected_label'] }}</span>
                                </td>
                                <td class="text-nowrap fw-semibold">{{ $row['delta_label'] ?? '—' }}</td>
                                <td>@include('voteguard::admin.partials.kind', ['kind' => $row['kind'] ?? 'first'])</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-muted text-center py-3">
                                    {{ trans('voteguard::admin.show.no_intervals') }}
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="vg-side sticky-top">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            @if ($suspect->user)
                                <img src="{{ $suspect->user->getAvatar() }}" alt="" width="56" height="56" class="rounded">
                                <div>
                                    <div class="fw-bold fs-5">{{ $suspect->user->name }}</div>
                                    <div class="text-muted small">#{{ $suspect->user_id }}</div>
                                    @if (Route::has('admin.users.edit'))
                                        <a class="small" href="{{ route('admin.users.edit', $suspect->user) }}">
                                            {{ trans('voteguard::admin.show.azuriom_profile') }}
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </div>

                        @include('voteguard::admin.partials.score', ['score' => $suspect->score, 'size' => 'lg'])
                        @if ((int) $suspect->max_score !== (int) $suspect->score)
                            <p class="text-muted small mb-2">max {{ $suspect->max_score }}</p>
                        @else
                            <div class="mb-2"></div>
                        @endif

                        <div class="mb-3">
                            @include('voteguard::admin.partials.status', ['status' => $suspect->status])
                            @if ($suspect->blocked)
                                <span class="badge text-bg-dark">{{ trans('voteguard::admin.status.blocked') }}</span>
                            @endif
                        </div>

                        @if (($mix['total'] ?? 0) > 0)
                            <div class="mb-3">
                                <div class="small text-muted mb-1">{{ trans('voteguard::admin.show.mix_title') }}</div>
                                @include('voteguard::admin.partials.mix-bar', ['mix' => $mix])
                            </div>
                        @endif

                        @if (($suspect->last_flags ?? []) !== [])
                            <div>
                                <div class="small text-muted mb-1">{{ trans('voteguard::admin.fields.flags') }}</div>
                                @include('voteguard::admin.partials.flags', ['flags' => $suspect->last_flags, 'limit' => 8])
                            </div>
                        @endif
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">{{ trans('voteguard::admin.show.review') }}</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('voteguard.admin.update', $suspect) }}">
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="status">{{ trans('voteguard::admin.fields.status') }}</label>
                                <select class="form-select" id="status" name="status">
                                    @foreach (['clear', 'watch', 'suspect', 'likely', 'confirmed', 'false_positive'] as $key)
                                        <option value="{{ $key }}" @selected($suspect->status === $key)>
                                            {{ trans('voteguard::admin.status.'.$key) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="blocked" name="blocked" value="1"
                                           @checked(old('blocked', $suspect->blocked))>
                                    <label class="form-check-label" for="blocked">{{ trans('voteguard::admin.fields.blocked') }}</label>
                                </div>
                                <div class="form-text">{{ trans('voteguard::admin.show.blocked_help') }}</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="note">{{ trans('voteguard::admin.fields.note') }}</label>
                                <textarea class="form-control" id="note" name="note" rows="3">{{ old('note', $suspect->note) }}</textarea>
                            </div>
                            @if ($suspect->reviewed_at)
                                <p class="small text-muted">
                                    {{ trans('voteguard::admin.fields.reviewed') }} :
                                    {{ $suspect->reviewer->name ?? '—' }} —
                                    {{ format_date($suspect->reviewed_at) }}
                                </p>
                            @endif
                            <button type="submit" class="btn btn-primary">{{ trans('messages.actions.save') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">{{ trans('voteguard::admin.show.detections') }}</div>
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead>
                <tr>
                    <th>{{ trans('voteguard::admin.fields.date') }}</th>
                    <th>{{ trans('voteguard::admin.fields.score') }}</th>
                    <th>{{ trans('voteguard::admin.fields.flags') }}</th>
                    <th>{{ trans('voteguard::admin.fields.ip') }}</th>
                    <th>{{ trans('voteguard::admin.fields.source') }}</th>
                    <th>{{ trans('voteguard::admin.fields.ua') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($detections as $detection)
                    <tr>
                        <td class="text-nowrap">{{ format_date_compact($detection->created_at) }}</td>
                        <td>@include('voteguard::admin.partials.score', ['score' => $detection->score])</td>
                        <td>@include('voteguard::admin.partials.flags', ['flags' => $detection->flags, 'limit' => 4])</td>
                        <td class="small text-nowrap">{{ $detection->ip ?? '—' }}</td>
                        <td>{{ trans('voteguard::admin.sources.'.$detection->source) }}</td>
                        <td class="small text-break" title="{{ $detection->user_agent }}">
                            {{ \Illuminate\Support\Str::limit($detection->user_agent ?? '—', 72) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-muted text-center py-3">
                            {{ trans('voteguard::admin.show.no_detections') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('footer-scripts')
    <script>
        (function () {
            const group = document.getElementById('vg-site-filter');
            if (!group) {
                return;
            }

            group.querySelectorAll('[data-vg-site]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const site = btn.getAttribute('data-vg-site') || '';
                    group.querySelectorAll('[data-vg-site]').forEach(function (other) {
                        other.classList.toggle('active', other === btn);
                    });
                    document.querySelectorAll('#vg-intervals tbody tr[data-site]').forEach(function (row) {
                        row.classList.toggle('d-none', site !== '' && row.getAttribute('data-site') !== site);
                    });
                });
            });
        })();
    </script>
@endpush
