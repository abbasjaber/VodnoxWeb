@if ($paginator->hasPages())
    <nav aria-label="pagination">
        <ul class="pagination">
            <li class="page-item{{ $paginator->onFirstPage() ? ' disabled' : '' }}">
                @if ($paginator->onFirstPage())
                    <span class="page-link">@lang('pagination.previous')</span>
                @else
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">@lang('pagination.previous')</a>
                @endif
            </li>
            <li class="page-item disabled"><span class="page-link">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span></li>
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
