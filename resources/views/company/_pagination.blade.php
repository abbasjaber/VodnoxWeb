@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $start = max(1, $current - 2);
        $end = min($last, $current + 2);
    @endphp
    <nav class="pagination" aria-label="{{ __('company.next') }}">
        @if ($paginator->onFirstPage())
            <span class="page-link disabled">@lang('pagination.previous')</span>
        @else
            <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">@lang('pagination.previous')</a>
        @endif

        @for ($page = $start; $page <= $end; $page++)
            @if ($page === $current)
                <span class="page-link active" aria-current="page">{{ $page }}</span>
            @else
                <a class="page-link" href="{{ $paginator->url($page) }}">{{ $page }}</a>
            @endif
        @endfor

        @if ($paginator->hasMorePages())
            <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">@lang('pagination.next')</a>
        @else
            <span class="page-link disabled">@lang('pagination.next')</span>
        @endif
    </nav>
@endif
