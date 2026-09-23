{{-- Coupon fields. $c is the coupon being edited, or null for a new one. $p is the field prefix for unique ids. --}}
@php
    $isOld = old('_form') === $p;
    $val = fn ($k, $d = null) => $isOld ? old($k, $d) : $d;
    $never = $isOld ? (bool) old('never_expires') : ($c ? $c->expires_on === null : false);
    $locked = $c?->hasBeenUsed();
@endphp
<input type="hidden" name="_form" value="{{ $p }}">
<div class="mb-3"><label class="form-label" for="{{ $p }}code">Coupon code</label>
    <input class="form-control text-uppercase font-monospace" id="{{ $p }}code" name="code" value="{{ $val('code', $c?->code) }}" @if ($locked) readonly aria-describedby="{{ $p }}lock" @endif maxlength="40" placeholder="e.g. SPRING20" required>
    @if ($locked)<div class="form-text" id="{{ $p }}lock">Used {{ $c->times_used }} {{ Str::plural('time', $c->times_used) }}, so it can’t be renamed.</div>@endif
</div>
<div class="mb-3"><label class="form-label" for="{{ $p }}name">Name</label>
    <input class="form-control" id="{{ $p }}name" name="name" value="{{ $val('name', $c?->name) }}" maxlength="120" placeholder="Internal name, e.g. NAR conference" required></div>
<div class="row g-3">
    <div class="col-6"><label class="form-label" for="{{ $p }}pct">Discount</label>
        <div class="input-group"><input type="number" class="form-control" id="{{ $p }}pct" name="percent_off" min="0.01" max="100" step="0.01" value="{{ $val('percent_off', $c ? rtrim(rtrim((string) $c->percent_off, '0'), '.') : null) }}" required><span class="input-group-text">%</span></div></div>
    <div class="col-6"><label class="form-label" for="{{ $p }}exp">Expiry date</label>
        <input type="date" class="form-control" id="{{ $p }}exp" name="expires_on" value="{{ $val('expires_on', $c?->expires_on?->toDateString()) }}" @disabled($never) data-expiry></div>
</div>
<div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" role="switch" id="{{ $p }}never" name="never_expires" value="1" @checked($never) data-never><label class="form-check-label" for="{{ $p }}never">Never expires</label></div>
<div class="row g-3 mt-1">
    <div class="col-6"><label class="form-label" for="{{ $p }}max">Usage limit <span class="text-slate">(optional)</span></label>
        <input type="number" class="form-control" id="{{ $p }}max" name="max_uses" min="1" value="{{ $val('max_uses', $c?->max_uses) }}" placeholder="No limit"></div>
</div>
<div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" role="switch" id="{{ $p }}act" name="is_active" value="1" @checked($isOld ? old('is_active') : ($c?->is_active ?? true))><label class="form-check-label" for="{{ $p }}act">Active</label></div>
<div class="form-text mt-2">Applies to the implementation fee only. Changing the discount affects new sign-ups, not signed agreements.</div>
