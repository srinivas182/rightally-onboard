@extends('layouts.admin')
@section('title', 'Email templates')
@section('menu', 'email_templates')
@section('content')
@php
    $tz = \App\Support\BusinessClock::timezone();
    $pill = ['sent' => 'st-live', 'delivered' => 'st-live', 'opened' => 'st-live', 'queued' => 'st-wait', 'logged' => 'st-susp', 'failed' => 'st-fail', 'bounced' => 'st-fail'];
@endphp
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0">Email templates</h1>
    <a class="btn btn-primary" href="{{ route('admin.email-templates.create') }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-plus"/></svg>New custom email</a>
</div>
<ul class="nav nav-tabs tabs-scroll mb-3" role="tablist" data-remember-tab="email-templates">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#automatic">Automatic emails</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#custom">Custom emails</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sent">Recently sent</a></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="automatic" role="tabpanel"><div class="panel">
        <div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>Email</th><th>Sent when</th><th>CC team</th><th>Status</th><th>Last edited</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            @foreach ($system as $t)
                <tr>
                    <td><b>{{ $t->name }}</b><div class="small text-slate text-truncate" style="max-width:320px">{{ $t->subject }}</div></td>
                    <td class="small">{{ $t->trigger_description }}</td>
                    <td>@if ($t->cc_team)<span class="st st-live">Yes</span>@else<span class="st st-draft">No</span>@endif</td>
                    <td>@if ($t->is_enabled)<span class="st st-live">On</span>@else<span class="st st-draft">Off</span>@endif</td>
                    <td class="small">{{ $t->updated_at->copy()->setTimezone($tz)->format('M j, Y') }}</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.email-templates.edit', $t) }}">Edit</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="p-3 small text-slate border-top">Automatic emails are sent at a set moment in the billing cycle. You can change their wording or switch them off, but not delete them.</div>
    </div></div>

    <div class="tab-pane fade" id="custom" role="tabpanel"><div class="panel">
        @if ($custom->isEmpty())
            <div class="p-4 p-md-5 text-center"><h2 class="h5">No custom emails yet</h2>
                <p class="text-slate mb-3">Custom emails are reusable one-off messages, such as kick-off scheduling or a go-live checklist, sent from a customer’s page.</p>
                <a class="btn btn-primary" href="{{ route('admin.email-templates.create') }}">Create a custom email</a></div>
        @else
        <div class="table-responsive"><table class="table">
            <thead><tr><th>Email</th><th>Subject</th><th>Last edited</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            @foreach ($custom as $t)
                <tr><td><b>{{ $t->name }}</b></td><td class="small">{{ $t->subject }}</td><td class="small">{{ $t->updated_at->copy()->setTimezone($tz)->format('M j, Y') }}</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.email-templates.edit', $t) }}">Edit</a></td></tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </div></div>

    <div class="tab-pane fade" id="sent" role="tabpanel"><div class="panel">
        @if ($recent->isEmpty())
            <div class="p-4 text-center text-slate">No emails sent yet.</div>
        @else
        <div class="table-responsive"><table class="table">
            <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
            <tbody>
            @foreach ($recent as $l)
                <tr><td class="small text-nowrap">{{ $l->created_at->copy()->setTimezone($tz)->format('M j, g:i A') }}</td>
                    <td class="small">{{ $l->to_email }}@if ($l->cc)<div class="text-slate">CC {{ count($l->cc) }}</div>@endif</td>
                    <td class="small">{{ $l->subject }}</td>
                    <td><span class="st {{ $pill[$l->status] ?? 'st-draft' }}">{{ ucfirst($l->status) }}</span>@if ($l->error)<div class="small text-slate mt-1" style="max-width:280px">{{ $l->error }}</div>@endif</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </div></div>
</div>
@endsection
