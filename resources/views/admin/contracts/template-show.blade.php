@extends('layouts.admin')
@section('title', 'Agreement template v'.$template->version)
@section('menu', 'contracts')
@section('content')
<a href="{{ route('admin.contracts.index') }}#templates" class="small text-decoration-none d-inline-flex align-items-center gap-1 mb-2"><svg class="ic" aria-hidden="true"><use href="#i-arrow"/></svg>Templates</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div><h1 class="h3 mb-1">{{ ucfirst($template->type->value) }} agreement v{{ $template->version }}</h1>
        <div class="text-slate small">{{ $template->isPublished() ? 'Published '.$template->published_at->format('M j, Y').($template->is_active ? ', in use for new agreements' : ', retired') : 'Draft, not yet used' }}. Preview with sample client details.</div></div>
    <div class="d-flex gap-2">
        @if (! $template->isPublished())
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.contracts.templates.edit', $template) }}">Back to editing</a>
            <form method="post" action="{{ route('admin.contracts.templates.publish', $template) }}">@csrf
                <button class="btn btn-primary btn-sm" data-confirm="Publish v{{ $template->version }}? New agreements will use it, and it can’t be edited afterwards.">Publish v{{ $template->version }}</button></form>
        @elseif ($template->is_active)
            <form method="post" action="{{ route('admin.contracts.templates.duplicate', $template) }}">@csrf<button class="btn btn-primary btn-sm">Create new version</button></form>
        @endif
    </div>
</div>
<div class="panel p-3 p-md-5 doc" style="max-width:860px">
    <h2>{{ $template->title }}</h2>
    {!! $preview !!}
</div>
@endsection
