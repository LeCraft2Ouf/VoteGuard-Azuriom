@extends('admin.layouts.admin')

@section('title', trans('voteguard::admin.title'))

@section('content')
    @include('voteguard::admin.partials.styles')

    @if (request()->filled('scan_total'))
        <div class="alert alert-success">
            {{ trans('voteguard::admin.scan.done', [
                'scanned' => request('scan_total'),
                'likely' => request('scan_likely', $countLikely),
                'suspect' => request('scan_suspect', $countSuspect),
                'watch' => request('scan_watch', $countWatch),
            ]) }}
        </div>
    @endif

    <div class="row g-3 mb-4">
        @foreach ([
            ['key' => 'likely', 'count' => $countLikely, 'border' => 'border-danger'],
            ['key' => 'suspect', 'count' => $countSuspect, 'border' => 'border-warning'],
            ['key' => 'watch', 'count' => $countWatch, 'border' => ''],
        ] as $stat)
            <div class="col-md-3">
                <a href="{{ route('voteguard.admin.index', array_filter(['status' => $status === $stat['key'] ? null : $stat['key'], 'search' => $search])) }}"
                   class="vg-stat {{ $status === $stat['key'] ? 'is-active' : '' }}">
                    <div class="card {{ $stat['border'] }}">
                        <div class="card-body">
                            <div class="text-muted small">{{ trans('voteguard::admin.stats.'.$stat['key']) }}</div>
                            <div class="fs-3 fw-bold">{{ $stat['count'] }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">{{ trans('voteguard::admin.stats.today') }}</div>
                    <div class="fs-3 fw-bold">{{ $detectionsToday }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">{{ trans('voteguard::admin.filters') }}</div>
                <div class="card-body">
                    <form method="GET" action="{{ route('voteguard.admin.index') }}" class="row g-2 align-items-end">
                        <div class="col-sm-6">
                            <label class="form-label" for="search">{{ trans('voteguard::admin.search') }}</label>
                            <input type="text" class="form-control" id="search" name="search" value="{{ $search }}"
                                   placeholder="{{ trans('voteguard::admin.search_placeholder') }}" autocomplete="off">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="status">{{ trans('voteguard::admin.fields.status') }}</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">{{ trans('voteguard::admin.all_status') }}</option>
                                @foreach (['watch', 'suspect', 'likely', 'confirmed', 'false_positive'] as $key)
                                    <option value="{{ $key }}" @selected($status === $key)>
                                        {{ trans('voteguard::admin.status.'.$key) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">{{ trans('messages.actions.search') }}</button>
                            @if ($search || $status)
                                <a href="{{ route('voteguard.admin.index') }}" class="btn btn-outline-secondary">
                                    {{ trans('voteguard::admin.reset') }}
                                </a>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">{{ trans('voteguard::admin.scan.title') }}</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('voteguard.admin.scan') }}" id="voteguard-scan-form"
                          class="row g-2 align-items-end">
                        @csrf
                        <div class="col-sm-4">
                            <label class="form-label" for="scan-from">{{ trans('voteguard::admin.scan.from') }}</label>
                            <input type="date" class="form-control" id="scan-from" name="from" required
                                   value="{{ $scanFrom }}">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="scan-to">{{ trans('voteguard::admin.scan.to') }}</label>
                            <input type="date" class="form-control" id="scan-to" name="to" required
                                   value="{{ $scanTo }}">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label d-none d-sm-block">&nbsp;</label>
                            <button type="submit" class="btn btn-warning w-100" id="voteguard-scan-btn">
                                {{ trans('voteguard::admin.scan.button') }}
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer text-muted small">
                    {{ trans('voteguard::admin.scan.help') }}
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>{{ trans('voteguard::admin.fields.user') }}</th>
                    <th style="min-width: 9rem;">{{ trans('voteguard::admin.fields.score') }}</th>
                    <th>{{ trans('voteguard::admin.fields.status') }}</th>
                    <th>{{ trans('voteguard::admin.fields.flags') }}</th>
                    <th>{{ trans('voteguard::admin.fields.date') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($suspects as $suspect)
                    <tr class="vg-row" role="link" tabindex="0"
                        data-href="{{ route('voteguard.admin.show', $suspect) }}">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                @if ($suspect->user)
                                    <img src="{{ $suspect->user->getAvatar() }}" alt="" width="36" height="36" class="rounded">
                                    <div>
                                        <div class="fw-semibold">{{ $suspect->user->name }}</div>
                                        <div class="text-muted small">#{{ $suspect->user_id }}</div>
                                    </div>
                                @else
                                    <span class="text-muted">#{{ $suspect->user_id }}</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            @include('voteguard::admin.partials.score', ['score' => $suspect->score])
                            @if ((int) $suspect->max_score !== (int) $suspect->score)
                                <div class="text-muted small">max {{ $suspect->max_score }}</div>
                            @endif
                        </td>
                        <td>@include('voteguard::admin.partials.status', ['status' => $suspect->status])</td>
                        <td>@include('voteguard::admin.partials.flags', ['flags' => $suspect->last_flags, 'limit' => 2])</td>
                        <td class="text-nowrap text-muted small" title="{{ format_date($suspect->updated_at) }}">
                            {{ format_date_compact($suspect->updated_at) }}
                        </td>
                        <td class="text-end">
                            <i class="bi bi-chevron-right text-muted"></i>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            {{ ($search || $status) ? trans('voteguard::admin.empty_filter') : trans('voteguard::admin.empty') }}
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
            document.querySelectorAll('.vg-row[data-href]').forEach(function (row) {
                row.addEventListener('click', function () {
                    window.location.href = row.dataset.href;
                });
                row.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        window.location.href = row.dataset.href;
                    }
                });
            });

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
                        body: JSON.stringify({
                            offset: offset,
                            from: form.querySelector('[name="from"]').value,
                            to: form.querySelector('[name="to"]').value
                        })
                    });

                    if (!response.ok) {
                        throw new Error('scan');
                    }

                    const data = await response.json();
                    offset = data.offset || 0;
                    setProgress(offset, data.total || 0);

                    if (data.done) {
                        const url = new URL(indexUrl, window.location.origin);
                        url.searchParams.set('scan_total', String(data.total || 0));
                        url.searchParams.set('scan_likely', String(data.likely || 0));
                        url.searchParams.set('scan_suspect', String(data.suspect || 0));
                        url.searchParams.set('scan_watch', String(data.watch || 0));
                        url.searchParams.set('from', form.querySelector('[name="from"]').value);
                        url.searchParams.set('to', form.querySelector('[name="to"]').value);
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
