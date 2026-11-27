@props(['title' => 'Sign in'])
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · HESB Digital Center</title>
    <script>if (localStorage.getItem('theme') === 'light') document.documentElement.classList.remove('dark');</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
<div class="min-h-screen grid lg:grid-cols-2">
    <div class="flex flex-col px-6 sm:px-12 py-8">
        <a href="{{ route('login') }}" class="flex items-center gap-3">
            <span class="grid place-items-center size-9 rounded-control bg-brand-300 text-white font-black text-sm">H</span>
            <span class="text-sm font-bold tracking-wide">HESB DIGITAL CENTER</span>
        </a>
        <div class="flex-1 flex items-center">
            <div class="w-full max-w-[400px] mx-auto lg:ml-[12%]">
                {{ $slot }}
            </div>
        </div>
        <p class="text-xs text-ink-muted">© {{ now()->year }} HESB · Group financial reporting</p>
    </div>

    {{-- Purity-style hero panel --}}
    <div class="hidden lg:block p-4">
        <div class="h-full rounded-bl-[40px] rounded-[25px] bg-banner relative overflow-hidden flex items-end p-12">
            <svg class="absolute inset-0 w-full h-full opacity-30" viewBox="0 0 600 800" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0 520 C120 460 200 600 320 540 S520 420 600 470 L600 800 L0 800Z" fill="#4FD1C5" opacity=".35"/>
                <path d="M0 600 C140 560 240 680 360 620 S520 540 600 580 L600 800 L0 800Z" fill="#4FD1C5" opacity=".45"/>
                <polyline points="40,380 120,330 200,350 280,260 360,290 440,200 520,230 580,150" fill="none" stroke="#81E6D9" stroke-width="3"/>
            </svg>
            <div class="relative text-white max-w-md">
                <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-brand-200">Weekly financial flash</p>
                <p class="mt-3 text-3xl font-bold leading-tight">Every company. Every week. One version of the numbers.</p>
                <p class="mt-3 text-sm text-white/70">Submit actuals and forecasts, track performance against AMP and see the whole group at a glance.</p>
            </div>
        </div>
    </div>
</div>
</body>
</html>
