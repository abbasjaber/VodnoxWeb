@if (session('status'))
    <div class="section-note" role="status" style="border-color:#cfe6da;background:#f1f9f5;color:#21846b">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="section-note" role="alert" style="border-color:#f2dadd;background:#fceff0;color:#c55b61">
        <ul style="margin:0;padding-inline-start:18px">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
