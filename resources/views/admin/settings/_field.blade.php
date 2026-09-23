{{-- Generic settings input. Vars: $group, $key, $label, $value, [$prefix], [$suffix], [$type], [$help], [$col], [$placeholder], [$secret] --}}
@php $name = $key; $id = "{$group}_{$key}"; $secret = $secret ?? false; @endphp
<div class="{{ $col ?? 'col-sm-6' }}">
    <label class="form-label" for="{{ $id }}">{{ $label }}</label>
    <div class="{{ isset($prefix) || isset($suffix) ? 'input-group' : '' }} @error($name, $group) has-validation @enderror">
        @isset($prefix)<span class="input-group-text">{{ $prefix }}</span>@endisset
        <input class="form-control {{ $secret ? 'font-monospace' : '' }} @error($name, $group) is-invalid @enderror" id="{{ $id }}" name="{{ $name }}"
               type="{{ $secret ? 'password' : ($type ?? 'text') }}"
               @if ($secret) value="" placeholder="{{ $value ? 'Saved ('.$value.'). Leave blank to keep.' : 'Not set' }}" autocomplete="new-password"
               @else value="{{ old($name, $value) }}" placeholder="{{ $placeholder ?? '' }}" @endif
               @isset($step) step="{{ $step }}" @endisset>
        @isset($suffix)<span class="input-group-text">{{ $suffix }}</span>@endisset
        @error($name, $group)<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    @isset($help)<div class="form-text">{{ $help }}</div>@endisset
</div>
