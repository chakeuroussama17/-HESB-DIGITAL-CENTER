// Theme toggle (dark default) and the mobile sidebar. Page-specific modules
// (grid, charts) are imported by the views that need them.
document.addEventListener('click', (e) => {
    if (e.target.closest('[data-theme-toggle]')) {
        const dark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', dark ? 'dark' : 'light');
        document.dispatchEvent(new CustomEvent('themechange', { detail: { dark } }));
    }

    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    if (!sidebar) return;
    if (e.target.closest('[data-sidebar-open]')) {
        sidebar.classList.remove('-translate-x-full');
        backdrop.classList.remove('hidden');
    } else if (e.target.closest('[data-sidebar-close]')) {
        sidebar.classList.add('-translate-x-full');
        backdrop.classList.add('hidden');
    }
});

// Confirm dangerous actions: <form data-confirm="Are you sure?">
document.addEventListener('submit', (e) => {
    const msg = e.target.dataset?.confirm;
    if (msg && !window.confirm(msg)) e.preventDefault();
});
