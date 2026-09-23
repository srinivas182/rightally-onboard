@extends('layouts.admin')
@section('title', $page->title)
@section('menu', 'settings')
@section('content')
<a href="{{ route('admin.settings.index') }}#t-legal" class="small text-decoration-none d-inline-flex align-items-center gap-1 mb-2"><svg class="ic" aria-hidden="true"><use href="#i-arrow"/></svg>Settings</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div><h1 class="h3 mb-1">{{ $page->title }}</h1><div class="small text-slate">Published at <a href="{{ route('legal', $page->slug) }}" target="_blank" rel="noopener">{{ route('legal', $page->slug) }}</a>. Last saved {{ $page->updated_at->setTimezone(\App\Support\BusinessClock::timezone())->format('M j, Y g:i A') }}.</div></div>
    @foreach ($others as $o)<a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.legal.edit', $o) }}">Edit {{ $o->title }}</a>@endforeach
</div>
<div class="alert alert-warning small">These pages start as drafts for your attorney to review. Saving publishes immediately. Placeholders such as <span class="font-monospace">&#123;&#123; company_legal_name &#125;&#125;</span>, <span class="font-monospace">&#123;&#123; company_address &#125;&#125;</span>, <span class="font-monospace">&#123;&#123; support_email &#125;&#125;</span> and <span class="font-monospace">&#123;&#123; updated_date &#125;&#125;</span> are filled from Settings.</div>
<form method="post" action="{{ route('admin.legal.update', $page) }}" class="panel p-3 p-md-4">@csrf @method('put')
    <div class="mb-3"><label class="form-label" for="title">Title</label><input class="form-control" id="title" name="title" value="{{ old('title', $page->title) }}" required maxlength="120"></div>
    <label class="form-label" for="body_html">Text (HTML: headings, paragraphs, bold, lists)</label>
    <textarea class="form-control font-monospace small" id="body_html" name="body_html" rows="26">{{ old('body_html', $page->body_html) }}</textarea>
    <button class="btn btn-primary mt-4">Save and publish</button>
</form>
@endsection
