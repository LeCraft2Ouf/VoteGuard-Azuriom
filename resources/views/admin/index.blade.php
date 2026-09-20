@extends('admin.layouts.admin')

@section('title', trans('voteguard::admin.title'))

@section('content')
    @if (request()->filled('scan_total'))
        <div class="alert alert-success">
            {{ trans('voteguard::admin.scan.done', [
                'scanned' => request('scan_total'),
                'flagged' => request('scan_flagged', 0),
            ]) }}
        </div>
    @endif

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

            <form method="POST" action="{{ route('voteguard.admin.scan') }}" id="voteguard-scan-form">
                @csrf
                <button type="submit" class="btn btn-warning" id="voteguard-scan-btn">
                    {{ trans('voteguard::admin.scan.button') }}
                </button>
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
                        <td>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach (($suspect->last_flags ?? []) as $flag)
                                    <span class="badge text-bg-light text-dark fs-6 fw-normal px-3 py-2">{{ trans('voteguard::admin.flags.'.$flag) }}</span>
                                @endforeach
                            </div>
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

    <div id="voteguard-progress" class="d-none position-fixed top-0 start-0 w-100 h-100"
         style="z-index: 1080; background: rgba(15, 18, 24, .55);">
        <div class="d-flex h-100 align-items-center justify-content-center p-3">
            <div class="card shadow" style="min-width: min(420px, 100%);">
                <div class="card-body">
                    <p class="mb-3" id="voteguard-progress-label">{{ trans('voteguard::admin.scan.progress', ['current' => 0, 'total' => 0]) }}</p>
                    <div class="progress" style="height: 1.35rem;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="voteguard-progress-bar"
                             role="progressbar" style="width: 0%">0%</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('footer-scripts')
    <script>
        (function () {
            const form = document.getElementById('voteguard-scan-form');
            const button = document.getElementById('voteguard-scan-btn');
            const overlay = document.getElementById('voteguard-progress');
            const label = document.getElementById('voteguard-progress-label');
            const bar = document.getElementById('voteguard-progress-bar');

            if (!form || !button || !overlay) {
                return;
            }

            const progressTpl = @json(trans('voteguard::admin.scan.progress', ['current' => '__C__', 'total' => '__T__']));
            const errorMsg = @json(trans('voteguard::admin.scan.error'));
            const indexUrl = @json(route('voteguard.admin.index'));
            const token = form.querySelector('input[name="_token"]')?.value || '';

            function setProgress(current, total) {
                const pct = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;
                label.textContent = progressTpl.replace('__C__', String(current)).replace('__T__', String(total));
                bar.style.width = pct + '%';
                bar.textContent = pct + '%';
            }

            async function runScan() {
                let offset = 0;
                let flagged = 0;

                while (true) {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({offset: offset})
                    });

                    if (!response.ok) {
                        throw new Error('scan');
                    }

                    const data = await response.json();
                    offset = data.offset || 0;
                    flagged += data.flagged || 0;
                    setProgress(offset, data.total || 0);

                    if (data.done) {
                        const url = new URL(indexUrl, window.location.origin);
                        url.searchParams.set('scan_total', String(data.total || 0));
                        url.searchParams.set('scan_flagged', String(flagged));
                        window.location.href = url.toString();
                        return;
                    }
                }
            }

            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                button.disabled = true;
                overlay.classList.remove('d-none');
                setProgress(0, 0);

                runScan().catch(function () {
                    overlay.classList.add('d-none');
                    button.disabled = false;
                    alert(errorMsg);
                });
            });
        })();
    </script>
@endpush
