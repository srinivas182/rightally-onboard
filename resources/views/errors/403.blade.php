@php
    $raw = (string) ($exception?->getMessage() ?? '');
    $generic = in_array($raw, ['', 'Invalid signature.', 'This action is unauthorized.', 'Forbidden'], true);
    $admin = request()->is('admin*');
@endphp
@include('errors.layout', [
    'code' => 403,
    'title' => $admin ? 'You don’t have access to this page' : 'You can’t open this page',
    'message' => $admin ? 'Your role doesn’t include this menu. Ask a super admin if you need it.' : ($generic ? 'This link may have expired, or it belongs to someone else. Open the latest link from your email, or start again.' : $raw),
    'action' => $admin ? url('/admin') : null,
    'actionLabel' => $admin ? 'Back to the dashboard' : null,
])
