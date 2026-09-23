@extends('layouts.admin')
@section('title', $template->exists ? 'Edit '.$template->name : 'New custom email')
@section('menu', 'email_templates')
@section('content')
@php $isNew = ! $template->exists; @endphp
<a href="{{ route('admin.email-templates.index') }}" class="small text-decoration-none d-inline-flex align-items-center gap-1 mb-2"><svg class="ic" aria-hidden="true"><use href="#i-arrow"/></svg>Email templates</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div><h1 class="h3 mb-1">{{ $isNew ? 'New custom email' : $template->name }}</h1>
        <div class="text-slate small">{{ $isNew ? 'A reusable message you can send from a customer’s page.' : 'Sent when: '.lcfirst((string) $template->trigger_description).'.' }}</div></div>
    @unless ($isNew)
        <div class="d-flex gap-2 flex-wrap">
            @if ($template->is_system)
                <form method="post" action="{{ route('admin.email-templates.reset', $template) }}">@csrf<button class="btn btn-outline-secondary btn-sm" data-confirm="Replace this email’s wording with the default?">Reset to default</button></form>
            @else
                <form method="post" action="{{ route('admin.email-templates.destroy', $template) }}">@csrf @method('delete')<button class="btn btn-outline-danger btn-sm" data-confirm="Delete this custom email?">Delete</button></form>
            @endif
            <form method="post" action="{{ route('admin.email-templates.test', $template) }}">@csrf<button class="btn btn-outline-primary btn-sm"><svg class="ic me-1" aria-hidden="true"><use href="#i-send"/></svg>Send test to me</button></form>
        </div>
    @endunless
</div>
<div class="row g-3">
    <div class="col-xl-6">
        <form method="post" action="{{ $isNew ? route('admin.email-templates.store') : route('admin.email-templates.update', $template) }}" class="panel p-3 p-md-4">@csrf
            @unless ($isNew) @method('put') @endunless
            @if (! $template->is_system)
                <div class="mb-3"><label class="form-label" for="name">Name</label>
                    <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $template->name) }}" maxlength="120" required placeholder="e.g. Kick-off call scheduling"></div>
            @endif
            <div class="mb-3"><label class="form-label" for="subject">Subject</label>
                <input class="form-control @error('subject') is-invalid @enderror" id="subject" name="subject" value="{{ old('subject', $template->subject) }}" maxlength="200" required></div>
            <label class="form-label" for="body">Message</label>
            <textarea class="form-control @error('body') is-invalid @enderror" id="body" name="body" rows="14" required>{{ old('body', $template->body) }}</textarea>
            <div class="form-text">Leave a blank line between paragraphs. The first paragraph is shown as the heading. Put a button on its own line: <span class="font-monospace">{button:Label|{payment_link}}</span></div>
            <div class="small text-slate mt-3 mb-2">Click to insert:</div>
            <div class="d-flex flex-wrap gap-1 mb-3">
                @foreach ($placeholders as $key => $desc)
                    <button type="button" class="btn btn-sm btn-light font-monospace" data-insert="{{ '{'.$key.'}' }}" data-target="#body" title="{{ $desc }}">{{ '{'.$key.'}' }}</button>
                @endforeach
            </div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="cc_team" name="cc_team" value="1" @checked(old('cc_team', $template->cc_team))><label class="form-check-label" for="cc_team">CC the team ({{ app(\App\Services\Settings\SettingsService::class)->get('email', 'team_cc') }})</label></div>
            <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" @checked(old('is_enabled', $template->is_enabled))><label class="form-check-label" for="is_enabled">{{ $template->is_system ? 'Send this email' : 'Available to send' }}</label></div>
            <button class="btn btn-primary mt-4" type="submit">{{ $isNew ? 'Create email' : 'Save email' }}</button>
        </form>
    </div>
    <div class="col-xl-6">
        <div class="panel h-100">
            <div class="panel-h"><h2>Preview</h2><span class="small text-slate">Saved version, sample data</span></div>
            <div class="p-3" style="background:#EEF2F8">
                @if ($preview)
                    <div class="small text-slate mb-2">Subject: <b class="text-body">{{ $preview['subject'] }}</b></div>
                    <iframe title="Email preview" srcdoc="{{ $preview['html'] }}" style="width:100%;height:620px;border:0;border-radius:12px;background:#fff" sandbox></iframe>
                @else
                    <div class="text-slate small p-4 text-center">Save to see a preview.</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
