<x-layouts.guest title="Forgot password">
    <h2 class="text-[32px] font-bold text-brand-300 leading-tight">Reset password</h2>
    <p class="mt-1 text-sm font-bold text-ink-muted">We'll email you a link to choose a new one.</p>

    @if (session('status'))
        <p class="mt-6 badge-good !text-xs !py-2 !whitespace-normal" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <label for="email" class="label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus class="field" placeholder="you@company.com">
            @error('email')<p class="mt-1.5 text-xs text-bad">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="btn-primary w-full h-[45px]">Email reset link</button>
        <p class="text-center text-xs text-ink-muted">
            Remembered it? <a href="{{ route('login') }}" class="font-bold text-brand-300 hover:underline">Back to sign in</a>
        </p>
    </form>
</x-layouts.guest>
