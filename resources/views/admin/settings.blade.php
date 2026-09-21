@extends('admin.layouts.admin')

@section('title', trans('voteguard::admin.settings.title'))

@section('content')
    @include('voteguard::admin.partials.styles')

    <form method="POST" action="{{ route('voteguard.admin.settings.save') }}">
        @csrf

        <div class="card mb-3">
            <div class="card-header">{{ trans('voteguard::admin.settings.section_live') }}</div>
            <div class="card-body">
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="enabled" name="enabled" value="1"
                           @checked(old('enabled', $enabled))>
                    <label class="form-check-label" for="enabled">{{ trans('voteguard::admin.settings.enabled') }}</label>
                </div>
                <div class="form-text">{{ trans('voteguard::admin.settings.enabled_info') }}</div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">{{ trans('voteguard::admin.settings.section_scores') }}</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3 mb-md-0">
                        <label class="form-label" for="watch">{{ trans('voteguard::admin.settings.watch') }}</label>
                        <input type="number" class="form-control" id="watch" name="watch" min="1" max="100"
                               value="{{ old('watch', $watch) }}" required>
                    </div>
                    <div class="col-md-4 mb-3 mb-md-0">
                        <label class="form-label" for="suspect">{{ trans('voteguard::admin.settings.suspect') }}</label>
                        <input type="number" class="form-control" id="suspect" name="suspect" min="1" max="100"
                               value="{{ old('suspect', $suspect) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="likely">{{ trans('voteguard::admin.settings.likely') }}</label>
                        <input type="number" class="form-control" id="likely" name="likely" min="1" max="100"
                               value="{{ old('likely', $likely) }}" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">{{ trans('voteguard::admin.settings.section_timing') }}</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="min_votes">{{ trans('voteguard::admin.settings.min_votes') }}</label>
                        <input type="number" class="form-control" id="min_votes" name="min_votes" min="4" max="50"
                               value="{{ old('min_votes', $minVotes) }}" required>
                        <div class="form-text">{{ trans('voteguard::admin.settings.min_votes_info') }}</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="sniper">{{ trans('voteguard::admin.settings.sniper') }}</label>
                        <input type="number" class="form-control" id="sniper" name="sniper" min="5" max="300"
                               value="{{ old('sniper', $sniper) }}" required>
                        <div class="form-text">{{ trans('voteguard::admin.settings.sniper_info') }}</div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label" for="stddev">{{ trans('voteguard::admin.settings.stddev') }}</label>
                        <input type="number" class="form-control" id="stddev" name="stddev" min="5" max="600"
                               value="{{ old('stddev', $stddev) }}" required>
                        <div class="form-text">{{ trans('voteguard::admin.settings.stddev_info') }}</div>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="ip_farm">{{ trans('voteguard::admin.settings.ip_farm') }}</label>
                    <input type="number" class="form-control" id="ip_farm" name="ip_farm" min="2" max="50"
                           value="{{ old('ip_farm', $ipFarm) }}" required>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">{{ trans('voteguard::admin.settings.section_alerts') }}</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="webhook">{{ trans('voteguard::admin.settings.webhook') }}</label>
                    <input type="url" class="form-control" id="webhook" name="webhook"
                           value="{{ old('webhook', $webhook) }}" placeholder="https://discord.com/api/webhooks/...">
                    @error('webhook')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <div class="form-text">{{ trans('voteguard::admin.settings.webhook_info') }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="whitelist">{{ trans('voteguard::admin.settings.whitelist') }}</label>
                    <textarea class="form-control" id="whitelist" name="whitelist" rows="4">{{ old('whitelist', $whitelist) }}</textarea>
                    <div class="form-text">{{ trans('voteguard::admin.settings.whitelist_info') }}</div>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="blocklist">{{ trans('voteguard::admin.settings.blocklist') }}</label>
                    <textarea class="form-control" id="blocklist" name="blocklist" rows="5">{{ old('blocklist', $blocklist) }}</textarea>
                    <div class="form-text">{{ trans('voteguard::admin.settings.blocklist_info') }}</div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">{{ trans('messages.actions.save') }}</button>
    </form>
@endsection
