@extends('layouts.admin')
@section('title', 'Admins and roles')
@section('menu', 'admins')
@section('content')
@php $me = auth('admin')->user(); @endphp
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0">Admins and roles</h1>
</div>
<ul class="nav nav-tabs tabs-scroll mb-3" role="tablist" data-remember-tab="admins">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#admins">Admins</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#roles">Roles and menu access</a></li>
</ul>
<div class="tab-content">
    {{-- ======================= Admins ======================= --}}
    <div class="tab-pane fade show active" id="admins" role="tabpanel">
        <div class="panel">
            <div class="panel-h"><span class="small text-slate">{{ $admins->count() }} {{ Str::plural('admin', $admins->count()) }}</span>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#inviteModal"><svg class="ic me-1"><use href="#i-plus"/></svg>Invite admin</button></div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Name</th><th>Role</th><th>Two-factor</th><th>Last sign-in</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                @foreach ($admins as $a)
                    <tr>
                        <td><b>{{ $a->name }}</b><div class="small text-slate">{{ $a->email }}</div></td>
                        <td>{{ $a->role->name }}</td>
                        <td>@if ($a->hasTwoFactorEnabled())<span class="st st-live">On</span>@else<span class="st st-fail">Not set up</span>@endif</td>
                        <td class="small">{{ $a->last_login_at?->timezone(config('rightally.business_timezone'))->format('M j, g:i A') ?? '—' }}</td>
                        <td>
                            @if (! $a->is_active)<span class="st st-draft">Deactivated</span>
                            @elseif (! $a->hasAcceptedInvite())<span class="st st-wait">Invited</span>
                            @else<span class="st st-live">Active</span>@endif
                        </td>
                        <td class="text-end">
                            @if (! $me->is($a) && (! $a->isSuperAdmin() || $me->isSuperAdmin()))
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Manage</button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#edit{{ $a->id }}">Edit name or role</button></li>
                                        @if (! $a->hasAcceptedInvite())
                                            <li><form method="post" action="{{ route('admin.admins.resend', $a) }}">@csrf<button class="dropdown-item">Resend invitation</button></form></li>
                                        @endif
                                        @if ($a->hasTwoFactorEnabled())
                                            <li><form method="post" action="{{ route('admin.admins.reset-2fa', $a) }}">@csrf<button class="dropdown-item" data-confirm="Reset two-factor for {{ $a->name }}? Their authenticator app is removed; they’ll get sign-in codes by email until they set up a new one.">Reset two-factor</button></form></li>
                                        @endif
                                        <li><hr class="dropdown-divider"></li>
                                        <li><form method="post" action="{{ route('admin.admins.toggle', $a) }}">@csrf
                                            <button class="dropdown-item {{ $a->is_active ? 'text-danger' : '' }}" @if ($a->is_active) data-confirm="Deactivate {{ $a->name }}? They’ll be signed out immediately." @endif>{{ $a->is_active ? 'Deactivate' : 'Reactivate' }}</button></form></li>
                                    </ul>
                                </div>
                            @else
                                <span class="small text-slate">{{ $me->is($a) ? 'You' : '' }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="p-3 small text-slate border-top">Every sign-in needs a 6-digit code: from the admin’s authenticator app, or by email if they haven’t set one up. “Reset two-factor” removes an admin’s authenticator app (e.g. a lost phone); they then get codes by email and can set up a new app under My account. Invitations expire after 72 hours.</div>
        </div>
    </div>

    {{-- ======================= Roles ======================= --}}
    <div class="tab-pane fade" id="roles" role="tabpanel">
        <form method="post" action="{{ route('admin.roles.access') }}" class="panel">
            @csrf @method('put')
            <div class="panel-h"><h2>Menu access by role</h2>
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#roleModal"><svg class="ic me-1"><use href="#i-plus"/></svg>New role</button></div>
            <div class="table-responsive"><table class="table perm">
                <thead><tr><th>Menu</th>@foreach ($roles as $r)<th>{{ $r->name }}<div class="small fw-normal text-slate">{{ $r->admins_count }} {{ Str::plural('admin', $r->admins_count) }}</div></th>@endforeach</tr></thead>
                <tbody>
                @foreach ($menus as $key => $m)
                    <tr><td>{{ $m['label'] }}</td>
                        @foreach ($roles as $r)
                            <td>@if ($r->isSuperAdmin())✓@else<input class="form-check-input" type="checkbox" name="access[{{ $r->id }}][]" value="{{ $key }}" @checked($r->grants($key)) aria-label="{{ $r->name }}: {{ $m['label'] }}">@endif</td>
                        @endforeach</tr>
                @endforeach
                <tr><td></td>@foreach ($roles as $r)<td>@unless ($r->is_system)
                    <button type="submit" form="del{{ $r->id }}" class="btn btn-link btn-sm text-danger p-0" data-confirm="Delete the {{ $r->name }} role?">Delete</button>@endunless</td>@endforeach</tr>
                </tbody>
            </table></div>
            <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small text-slate">Super admin always has full access. Only super admins can make someone a super admin.</span>
                <button class="btn btn-primary btn-sm" type="submit">Save access</button>
            </div>
        </form>
        @foreach ($roles as $r)@unless ($r->is_system)<form id="del{{ $r->id }}" method="post" action="{{ route('admin.roles.destroy', $r) }}">@csrf @method('delete')</form>@endunless @endforeach
    </div>
</div>

{{-- Invite modal --}}
<div class="modal fade" id="inviteModal" tabindex="-1" aria-labelledby="inviteT"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="{{ route('admin.admins.store') }}">@csrf
    <div class="modal-header"><h5 class="modal-title" id="inviteT">Invite admin</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="iname">Name</label><input class="form-control" id="iname" name="name" value="{{ old('name') }}" required></div>
        <div class="mb-3"><label class="form-label" for="iemail">Email</label><input class="form-control" id="iemail" name="email" type="email" value="{{ old('email') }}" required></div>
        <div><label class="form-label" for="irole">Role</label><select class="form-select" id="irole" name="role_id" required>
            @foreach ($roles as $r)@if (! $r->isSuperAdmin() || $me->isSuperAdmin())<option value="{{ $r->id }}" @selected(old('role_id') == $r->id)>{{ $r->name }}</option>@endif @endforeach
        </select></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Send invitation</button></div>
</form></div></div>

{{-- Edit modals --}}
@foreach ($admins as $a)
<div class="modal fade" id="edit{{ $a->id }}" tabindex="-1" aria-labelledby="editT{{ $a->id }}"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="{{ route('admin.admins.update', $a) }}">@csrf @method('put')
    <div class="modal-header"><h5 class="modal-title" id="editT{{ $a->id }}">Edit {{ $a->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" value="{{ $a->name }}" required></div>
        <div><label class="form-label">Role</label><select class="form-select" name="role_id">
            @foreach ($roles as $r)@if (! $r->isSuperAdmin() || $me->isSuperAdmin())<option value="{{ $r->id }}" @selected($a->role_id === $r->id)>{{ $r->name }}</option>@endif @endforeach
        </select></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div>
</form></div></div>
@endforeach

{{-- New role modal --}}
<div class="modal fade" id="roleModal" tabindex="-1" aria-labelledby="roleT"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="{{ route('admin.roles.store') }}">@csrf
    <div class="modal-header"><h5 class="modal-title" id="roleT">New role</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="rname">Role name</label><input class="form-control" id="rname" name="name" placeholder="e.g. Billing" required></div>
        <div><label class="form-label" for="rdesc">Description <span class="text-slate">(optional)</span></label><input class="form-control" id="rdesc" name="description"></div>
        <div class="form-text mt-2">New roles start with Dashboard access. Tick more menus in the grid after saving.</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create role</button></div>
</form></div></div>
@endsection
