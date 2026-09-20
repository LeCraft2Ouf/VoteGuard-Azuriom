@extends('admin.layouts.admin')

@section('title', trans('voteguard::admin.show.title', ['name' => $suspect->user->name ?? $suspect->user_id]))

@section('content')
    <div class="mb-3">
        <a href="{{ route('voteguard.admin.index') }}" class="btn btn-secondary">{{ trans('messages.actions.back') }}</a>
    </div>

    <div class="alert alert-info">{{ trans('voteguard::admin.show.hint') }}</div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header">{{ trans('voteguard::admin.show.intervals') }}</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                        <tr>
                            <th>{{ trans('voteguard::admin.fields.date') }}</th>
                            <th>{{ trans('voteguard::admin.fields.site') }}</th>
                            <th>{{ trans('voteguard::admin.fields.gap') }}</th>
                            <th>{{ trans('voteguard::admin.fields.cooldown') }}</th>
                            <th>{{ trans('voteguard::admin.fields.delta') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($intervals as $row)
                            <tr class="{{ $row['sniper'] ? 'table-danger' : '' }}">
                                <td>{{ format_date_compact($row['at']) }}</td>
                                <td>{{ $row['site'] }}</td>
                                <td>{{ $row['gap_label'] }}</td>
                                <td>{{ $row['expected_label'] }}</td>
                                <td>
                                    @if ($row['delta'] === null)
                                        —
                                    @else
                                        {{ $row['delta'] >= 0 ? '+' : '' }}{{ $row['delta'] }} s
                                    @endif
                                </td>
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
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        @if ($suspect->user)
                            <img src="{{ $suspect->user->getAvatar() }}" alt="" width="48" height="48" class="rounded">
                            <div>
                                <div class="fw-bold">{{ $suspect->user->name }}</div>
                                <div class="text-muted small">#{{ $suspect->user_id }}</div>
                            </div>
                        @endif
                    </div>
                    <p class="mb-1">{{ trans('voteguard::admin.fields.score') }} : <strong>{{ $suspect->score }}</strong>
                        (max {{ $suspect->max_score }})</p>
                    <p class="mb-0">
                        @php
                            $badge = match ($suspect->status) {
                                'likely', 'confirmed' => 'danger',
                                'suspect' => 'warning',
                                'false_positive' => 'secondary',
                                default => 'info',
                            };
                        @endphp
                        <span class="badge text-bg-{{ $badge }}">{{ trans('voteguard::admin.status.'.$suspect->status) }}</span>
                    </p>
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
                                @foreach (['watch', 'suspect', 'likely', 'confirmed', 'false_positive'] as $key)
                                    <option value="{{ $key }}" @selected($suspect->status === $key)>
                                        {{ trans('voteguard::admin.status.'.$key) }}
                                    </option>
                                @endforeach
                            </select>
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
                        <td>{{ format_date_compact($detection->created_at) }}</td>
                        <td>{{ $detection->score }}</td>
                        <td class="small">
                            @foreach (($detection->flags ?? []) as $flag)
                                <span class="badge text-bg-light text-dark">{{ trans('voteguard::admin.flags.'.$flag) }}</span>
                            @endforeach
                        </td>
                        <td class="small">{{ $detection->ip ?? '—' }}</td>
                        <td>{{ trans('voteguard::admin.sources.'.$detection->source) }}</td>
                        <td class="small text-break">{{ $detection->user_agent ?? '—' }}</td>
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
