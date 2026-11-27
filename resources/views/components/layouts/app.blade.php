@props(['title' => 'Dashboard', 'section' => 'Pages'])
@php
    $user = auth()->user();
@endphp
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · HESB Digital Center</title>
    <script>
        // Dark is the default; respect an explicit choice. Runs before paint to avoid a flash.
        if (localStorage.getItem('theme') === 'light') document.documentElement.classList.remove('dark');
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen">
<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <div id="sidebar-backdrop" class="fixed inset-0 z-30 bg-black/40 hidden lg:hidden" data-sidebar-close></div>
    <aside id="sidebar"
           class="fixed z-40 inset-y-0 left-0 w-[260px] -translate-x-full lg:translate-x-0 transition-transform bg-page lg:bg-transparent px-4 py-6 flex flex-col overflow-y-auto">
        @include('layouts.partials.sidebar', ['user' => $user])
    </aside>

    {{-- Main --}}
    <div class="flex-1 min-w-0 lg:ml-[260px] px-4 sm:px-6 pb-10">
        <header class="sticky top-0 z-20 -mx-4 sm:-mx-6 px-4 sm:px-6 pt-5 pb-4 bg-page/80 backdrop-blur">
            <div class="flex items-center gap-3">
                <button type="button" class="lg:hidden icon-tile bg-card shadow-card" data-sidebar-open aria-label="Open menu">
                    <x-icon name="menu" />
                </button>
                <div class="min-w-0">
                    <p class="text-xs text-ink-muted">
                        {{ $section }} <span class="text-ink">/ {{ $title }}</span>
                    </p>
                    <h1 class="text-sm font-bold truncate">{{ $title }}</h1>
                </div>

                <div class="ml-auto flex items-center gap-2 sm:gap-3">
                    {{ $actions ?? '' }}
                    <button type="button" class="icon-tile size-9 bg-card shadow-card text-ink-muted hover:text-brand-300" data-theme-toggle aria-label="Toggle colour theme">
                        <x-icon name="sun" class="size-4 hidden dark:block" />
                        <x-icon name="moon" class="size-4 dark:hidden" />
                    </button>
                    <div class="hidden sm:flex items-center gap-3 pl-3 border-l border-line">
                        <div class="text-right leading-tight">
                            <p class="text-xs font-bold">{{ $user->name }}</p>
                            <p class="text-[11px] text-ink-muted">{{ $user->company?->name ?? $user->role->label() }}</p>
                        </div>
                        <span class="grid place-items-center size-9 rounded-control bg-brand-300 text-white text-xs font-bold">
                            {{ \Illuminate\Support\Str::of($user->name)->explode(' ')->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join('') }}
                        </span>
                    </div>
                </div>
            </div>
        </header>

        @if (session('status'))
            <div class="mb-5 card !py-3 flex items-center gap-3 border-l-4 border-good" role="status">
                <x-icon name="check" class="size-5 text-good" />
                <p class="text-sm">{{ session('status') }}</p>
            </div>
        @endif
        @if ($errors->any() && ! ($hideErrors ?? false))
            <div class="mb-5 card !py-3 border-l-4 border-bad" role="alert">
                <div class="flex items-center gap-3">
                    <x-icon name="alert" class="size-5 text-bad" />
                    <p class="text-sm font-bold">Please fix the following</p>
                </div>
                <ul class="mt-2 ml-8 list-disc text-sm text-ink-soft space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <main>
            {{ $slot }}
        </main>

        <footer class="mt-10 text-xs text-ink-muted flex flex-wrap justify-between gap-2">
            <p>© {{ now()->year }} HESB Digital Center · Amounts in RM '000</p>
            <p>Signed in as {{ $user->role->label() }}</p>
        </footer>
    </div>
</div>
@stack('scripts')
</body>
</html>
