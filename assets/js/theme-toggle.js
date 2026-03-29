document.addEventListener('click', function (e) {
    if (e.target.closest('#theme-toggle')) {
        var isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    }
});
