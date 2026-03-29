(function () {
    var banner = document.getElementById('gdpr-banner');
    var dismiss = document.getElementById('gdpr-dismiss');
    if (!banner || !dismiss) return;
    if (!localStorage.getItem('gdpr_consent')) {
        banner.style.display = '';
        dismiss.focus();
    }
    function hide() {
        localStorage.setItem('gdpr_consent', '1');
        banner.style.display = 'none';
    }
    dismiss.addEventListener('click', hide);
    dismiss.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); hide(); }
    });
})();
