@php
    $raw = (string) ($exception?->getMessage() ?? '');
    $generic = in_array($raw, ['', 'Invalid signature.', 'This action is unauthorized.', 'Forbidden'], true);
    $admin = request()->is('admin*');
@endphp
@include('errors.layout', [
    'code' => 403,
    'title' => $admin ? 'You don’t have access to this page' : 'You can’t open this page',
    'message' => $admin ? 'Your role doesn’t include this menu. Ask a super admin if you need it.' : ($generic ? 'This link may have expired. Enter your email on the next page and we’ll send you a new one straight away.' : $raw),
    'action' => $admin ? url('/admin') : route('account.login'),
    'actionLabel' => $admin ? 'Back to the dashboard' : 'Get a new link',
])
