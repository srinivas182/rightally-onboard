@foreach (['success' => 'success', 'status' => 'info', 'warning' => 'warning'] as $key => $type)
    @if (session($key))
        <div class="alert alert-{{ $type }} alert-dismissible fade show" role="status">
            {{ session($key) }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Close') }}"></button>
        </div>
    @endif
@endforeach
@if ($errors->any() && ! ($hideErrorSummary ?? false))
    <div class="alert alert-danger" role="alert">
        @if ($errors->count() === 1)
            {{ $errors->first() }}
        @else
            <b>{{ __('Please fix these:') }}</b>
            <ul class="mb-0 mt-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        @endif
    </div>
@endif
