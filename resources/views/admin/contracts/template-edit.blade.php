@extends('layouts.admin')
@section('title', 'Edit draft template')
@section('menu', 'contracts')
@section('content')
<a href="{{ route('admin.contracts.index') }}#templates" class="small text-decoration-none d-inline-flex align-items-center gap-1 mb-2"><svg class="ic" aria-hidden="true"><use href="#i-arrow"/></svg>Templates</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div><h1 class="h3 mb-1">Draft {{ $template->type->value }} agreement v{{ $template->version }}</h1>
        <div class="text-slate small">Not used until you publish it. Publishing retires the current version for new agreements.</div></div>
    @if ($template->contracts()->doesntExist())
        <form method="post" action="{{ route('admin.contracts.templates.destroy', $template) }}">@csrf @method('delete')
            <button class="btn btn-outline-danger btn-sm" data-confirm="Delete this draft? This can’t be undone.">Delete draft</button></form>
    @endif
</div>
<div class="row g-3">
    <div class="col-xl-8">
        <form method="post" action="{{ route('admin.contracts.templates.update', $template) }}" class="panel p-3 p-md-4">@csrf @method('put')
            <div class="row g-3 mb-3">
                <div class="col-sm-3"><label class="form-label" for="version">Version</label>
                    <input class="form-control @error('version') is-invalid @enderror" id="version" name="version" value="{{ old('version', $template->version) }}" required maxlength="20">
                    @error('version')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-sm-9"><label class="form-label" for="title">Title</label>
                    <input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', $template->title) }}" required maxlength="255"></div>
            </div>
            <label class="form-label" for="body_html">Agreement text (HTML)</label>
            <textarea class="form-control font-monospace small" id="body_html" name="body_html" rows="24" spellcheck="true">{{ old('body_html', $template->body_html) }}</textarea>
            <div class="form-text">Allowed: headings (h3 for numbered sections), paragraphs, bold, italic, lists and tables. Scripts, styles and links are removed on save.</div>
            <div class="d-flex gap-2 mt-4 flex-wrap">
                <button class="btn btn-primary" type="submit">Save draft</button>
                <button class="btn btn-outline-primary" type="submit" name="preview" value="1">Save and preview</button>
            </div>
        </form>
    </div>
    <div class="col-xl-4">
        <div class="panel">
            <div class="panel-h"><h2>Placeholders</h2></div>
            <div class="p-3 small">
                <p class="text-slate">Click to insert. Each is filled in from the client’s details, the prices at signing and Settings.</p>
                @foreach ($placeholders as $key => $desc)
                    @php $token = '{'.'{ '.$key.' }'.'}'; @endphp
                    <div class="d-flex justify-content-between gap-2 py-1 border-bottom">
                        <button type="button" class="btn btn-link btn-sm p-0 font-monospace text-start" data-insert="{{ $token }}" data-target="#body_html">{{ $token }}</button>
                        <span class="text-slate text-end">{{ $desc }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
