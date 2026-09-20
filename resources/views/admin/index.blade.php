@extends('admin.layouts.admin')

@section('title', trans('voteguard::admin.title'))

@section('content')
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-danger">
                <div class="card-body">
                    <div class="text-muted small">{{ trans('voteguard::admin.stats.likely') }}</div>
                    <div class="fs-3 fw-bold">{{ $countLikely }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body">
                    <div class="text-muted small">{{ trans('voteguard::admin.stats.suspect') }}</div>
                    <div class="fs-3 fw-bold">{{ $countSuspect }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">{{ trans('voteguard::admin.stats.watch') }}</div>
                    <div class="fs-3 fw-bold">{{ $countWatch }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <div class="text-muted small">{{ trans('voteguard::admin.stats.today') }}</div>
                    <div class="fs-3 fw-bold">{{ $detectionsToday }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap gap-3 align-items-end">
            <form method="GET" action="{{ route('voteguard.admin.index') }}" class="d-flex flex-wrap gap-2 flex-grow-1">
                <div>
                    <label class="form-label" for="search">{{ trans('voteguard::admin.search') }}</label>
                    <input type="text" class="form-control" id="search" name="search" value="{{ $search }}">
                </div>
                <div>
                    <label class="form-label" for="status">{{ trans('voteguard::admin.fields.status') }}</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">—</option>
                        @foreach (['watch', 'suspect', 'likely', 'confirmed', 'false_positive'] as $key)
                            <option value="{{ $key }}" @selected($status === $key)>
                                {{ trans('voteguard::admin.status.'.$key) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label d-block">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">{{ trans('messages.actions.search') }}</button>
                </div>
            </form>

            <form method="POST" action="{{ route('voteguard.admin.scan') }}"
                  onsubmit="return confirm(@json(trans('voteguard::admin.scan.help')))">
                @csrf
                <button type="submit" class="btn btn-warning">{{ trans('voteguard::admin.scan.button') }}</button>
            </form>
        </div>
        <div class="card-footer text-muted small">
            {{ trans('voteguard::admin.scan.help') }}
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead>
                <tr>
                    <th>{{ trans('voteguard::admin.fields.user') }}</th>
                    <th>{{ trans('voteguard::admin.fields.score') }}</th>
                    <th>{{ trans('voteguard::admin.fields.max') }}</th>
                    <th>{{ trans('voteguard::admin.fields.status') }}</th>
                    <th>{{ trans('voteguard::admin.fields.flags') }}</th>
                    <th>{{ trans('voteguard::admin.fields.date') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($suspects as $suspect)
                    <tr>
                        <td>
                            @if ($suspect->user)
                                <a href="{{ route('voteguard.admin.show', $suspect) }}">{{ $suspect->user->name }}</a>
                            @else
                                #{{ $suspect->user_id }}
                            @endif
                        </td>
                        <td class="fw-bold">{{ $suspect->score }}</td>
                        <td>{{ $suspect->max_score }}</td>
                        <td>
                            @php
                                $badge = match ($suspect->status) {
                                    'likely', 'confirmed' => 'danger',
                                    'suspect' => 'warning',
                                    'false_positive' => 'secondary',
                                    default => 'info',
                                };
                            @endphp
                            <span class="badge text-bg-{{ $badge }}">
                                {{ trans('voteguard::admin.status.'.$suspect->status) }}
                            </span>
                        </td>
                        <td class="small">
                            @foreach (($suspect->last_flags ?? []) as $flag)
                                <span class="badge text-bg-light text-dark">{{ trans('voteguard::admin.flags.'.$flag) }}</span>
                            @endforeach
                        </td>
                        <td>{{ format_date_compact($suspect->updated_at) }}</td>
                        <td>
                            <a class="btn btn-sm btn-primary" href="{{ route('voteguard.admin.show', $suspect) }}">
                                {{ trans('messages.actions.show') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            {{ trans('voteguard::admin.empty') }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($suspects->hasPages())
            <div class="card-footer">
                {{ $suspects->withQueryString()->links() }}
            </div>
        @endif
    </div>
@endsection
