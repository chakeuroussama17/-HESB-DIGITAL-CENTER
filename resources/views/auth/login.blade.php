<x-layouts.guest title="Sign in">
    <h2 class="text-[32px] font-bold text-brand-300 leading-tight">Welcome Back</h2>
    <p class="mt-1 text-sm font-bold text-ink-muted">Enter your email and password to sign in</p>

    @if (session('status'))
        <p class="mt-6 badge-good !text-xs !py-2" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ url('/login') }}" class="mt-8 space-y-5" novalidate>
        @csrf
        <div>
            <label for="email" class="label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="field @error('email') !border-bad @enderror" placeholder="you@company.com">
            @error('email')<p class="mt-1.5 text-xs text-bad">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password" class="label">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="field" placeholder="Your password">
            @error('password')<p class="mt-1.5 text-xs text-bad">{{ $message }}</p>@enderror
        </div>
        <div class="flex items-center justify-between">
            <label class="inline-flex items-center gap-2 text-xs text-ink cursor-pointer">
                <input type="checkbox" name="remember" value="1" class="peer sr-only">
                <span class="relative w-9 h-5 rounded-full bg-line peer-checked:bg-brand-300 transition after:absolute after:top-0.5 after:left-0.5 after:size-4 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-4"></span>
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-xs font-bold text-brand-300 hover:underline">Forgot password?</a>
        </div>
        <button type="submit" class="btn-primary w-full h-[45px]">Sign in</button>
    </form>
</x-layouts.guest>
