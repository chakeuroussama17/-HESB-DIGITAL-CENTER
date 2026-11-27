<x-layouts.guest title="Choose a new password">
    <h2 class="text-[32px] font-bold text-brand-300 leading-tight">New password</h2>
    <p class="mt-1 text-sm font-bold text-ink-muted">At least 10 characters, with letters and numbers.</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required class="field">
            @error('email')<p class="mt-1.5 text-xs text-bad">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password" class="label">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="field">
            @error('password')<p class="mt-1.5 text-xs text-bad">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password_confirmation" class="label">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="field">
        </div>
        <button type="submit" class="btn-primary w-full h-[45px]">Save password</button>
    </form>
</x-layouts.guest>
