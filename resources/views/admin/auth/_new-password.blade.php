<div class="mb-3"><label class="form-label" for="password">New password</label>
    <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" autocomplete="new-password" required>
    @error('password')<div class="invalid-feedback">{{ $message }}</div>@else<div class="form-text">At least 12 characters with upper and lower case letters, a number and a symbol.</div>@enderror</div>
<div><label class="form-label" for="password_confirmation">Confirm new password</label>
    <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
