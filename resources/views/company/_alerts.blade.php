{{-- Flash message + validation error summary for the Organization workspace. --}}
@if (session('status'))
    <span data-company-flash hidden>{{ session('status') }}</span>
@endif

@if (session('error'))
    <div class="section-note" role="alert">{{ session('error') }}</div>
@endif

@if ($errors->any())
    <div class="section-note" role="alert">
        <strong>{{ __('company.please_fix_errors') }}</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
