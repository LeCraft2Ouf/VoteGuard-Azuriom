<style>
    .vg-stat { color: inherit; text-decoration: none; display: block; height: 100%; }
    .vg-stat .card { height: 100%; transition: border-color .15s ease, box-shadow .15s ease; }
    .vg-stat:hover .card { border-color: var(--bs-primary); }
    .vg-stat.is-active .card { box-shadow: 0 0 0 .15rem rgba(var(--bs-primary-rgb), .25); }
    .vg-row { cursor: pointer; }
    .vg-row:hover td { background: var(--bs-tertiary-bg); }
    .vg-intervals-wrap { max-height: min(70vh, 740px); }
    .vg-intervals thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        background: var(--bs-tertiary-bg, var(--bs-body-bg));
    }
    .vg-mix { height: .7rem; }
    .vg-mix > div { min-width: 2px; }
    .vg-side { top: 1rem; }
    .vg-kind { min-width: 4.2rem; }
</style>
