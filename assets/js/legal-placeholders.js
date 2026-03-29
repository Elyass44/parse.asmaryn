(function () {
    var el = document.querySelector('[data-legal-content]');
    if (!el) return;
    el.innerHTML = el.innerHTML.replace(/\[(?:TO FILL|À RENSEIGNER)[^\]]*\]/g, function (match) {
        return '<mark class="bg-amber-200 dark:bg-amber-800/60 text-amber-900 dark:text-amber-200 px-1 py-0.5 rounded text-[0.85em] font-semibold not-italic">' + match + '</mark>';
    });
})();
