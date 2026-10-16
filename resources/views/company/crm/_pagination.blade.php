@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();
        $start = max(1, $current - 2);
        $end = min($last, $current + 2);
    @endphp
    <nav class="crm-pagination" aria-label="@lang('crm.nav.overview')">
        <ul class="pagination">
            <li class="page-item{{ $paginator->onFirstPage() ? ' disabled' : '' }}">
                @if ($paginator->onFirstPage())
                    <span class="page-link">@lang('pagination.previous')</span>
                @else
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">@lang('pagination.previous')</a>
                @endif
            </li>
            @for ($page = $start; $page <= $end; $page++)
                <li class="page-item{{ $page === $current ? ' active' : '' }}">
                    @if ($page === $current)
                        <span class="page-link" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="page-link" href="{{ $paginator->url($page) }}">{{ $page }}</a>
                    @endif
                </li>
            @endfor
            <li class="page-item{{ $paginator->hasMorePages() ? '' : ' disabled' }}">
                @if ($paginator->hasMorePages())
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">@lang('pagination.next')</a>
                @else
                    <span class="page-link">@lang('pagination.next')</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
