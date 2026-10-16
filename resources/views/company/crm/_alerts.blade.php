@if (session('success'))
    <span data-crm-flash hidden>{{ session('success') }}</span>
@endif

@if ($errors->any())
    <div class="crm-top-note" role="alert">
        <span>
            @foreach ($errors->all() as $error)
                {{ $error }}@if (! $loop->last)<br>@endif
            @endforeach
        </span>
    </div>
@endif
