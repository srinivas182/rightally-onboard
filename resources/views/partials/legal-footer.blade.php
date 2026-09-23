@php $company = app(\App\Services\Settings\SettingsService::class)->group('company'); @endphp
<footer class="small text-slate py-4 px-3 text-center">
    © {{ now()->year }} {{ $company['legal_name'] }}@if ($company['dba']) d/b/a {{ $company['dba'] }}@endif ·
    <a href="{{ route('legal', 'privacy') }}" class="text-slate">Privacy Policy</a> ·
    <a href="{{ route('legal', 'terms') }}" class="text-slate">Terms of Use</a> ·
    <a href="mailto:{{ $company['support_email'] }}" class="text-slate">{{ $company['support_email'] }}</a>
</footer>
