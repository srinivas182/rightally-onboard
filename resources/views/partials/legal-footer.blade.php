@php $company = app(\App\Services\Settings\SettingsService::class)->group('company'); @endphp
<footer class="small py-4 px-3 text-center {{ ($dark ?? false) ? 'site-foot-dark' : 'text-slate' }}">
    © {{ now()->year }} {{ $company['legal_name'] }}@if ($company['dba']) d/b/a {{ $company['dba'] }}@endif ·
    <a href="{{ route('legal', 'privacy') }}" class="{{ ($dark ?? false) ? '' : 'text-slate' }}">{{ __('Privacy Policy') }}</a> ·
    <a href="{{ route('legal', 'terms') }}" class="{{ ($dark ?? false) ? '' : 'text-slate' }}">{{ __('Terms of Use') }}</a> ·
    <a href="mailto:{{ $company['support_email'] }}" class="{{ ($dark ?? false) ? '' : 'text-slate' }}">{{ $company['support_email'] }}</a>
</footer>
