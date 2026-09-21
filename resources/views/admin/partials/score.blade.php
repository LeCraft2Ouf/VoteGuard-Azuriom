@php
    $value = (int) $score;
    $tone = $value >= 80 ? 'danger' : ($value >= 50 ? 'warning' : ($value >= 25 ? 'info' : 'success'));
    $size = $size ?? 'md';
@endphp
<div class="d-flex align-items-center gap-2 {{ $size === 'lg' ? 'flex-column align-items-stretch gap-1' : '' }}">
    @if ($size === 'lg')
        <div class="d-flex align-items-baseline justify-content-between">
            <span class="display-6 fw-bold lh-1 text-{{ $tone }}">{{ $value }}</span>
            <span class="text-muted">/ 100</span>
        </div>
    @else
        <span class="fw-bold text-{{ $tone }} text-nowrap">{{ $value }}</span>
    @endif
    <div class="progress {{ $size === 'lg' ? '' : 'flex-grow-1' }}" style="height: {{ $size === 'lg' ? '10px' : '8px' }}; min-width: {{ $size === 'lg' ? '0' : '64px' }};">
        <div class="progress-bar bg-{{ $tone }}" role="progressbar" style="width: {{ max(0, min(100, $value)) }}%"></div>
    </div>
</div>
