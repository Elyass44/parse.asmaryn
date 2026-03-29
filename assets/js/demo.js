(function () {

    // ── Element refs ──────────────────────────────────────────────
    var dropZone         = document.getElementById('drop-zone');
    var fileInput        = document.getElementById('file-input');
    var dropIdle         = document.getElementById('drop-idle');
    var dropFilename     = document.getElementById('drop-filename');
    var dropFilenameText = document.getElementById('drop-filename-text');
    var submitWrap       = document.getElementById('submit-wrap');
    var submitBtn        = document.getElementById('submit-btn');
    var stateUpload      = document.getElementById('state-upload');
    var stateLoading     = document.getElementById('state-loading');
    var stateResult      = document.getElementById('state-result');
    var stateError       = document.getElementById('state-error');
    var resultTree       = document.getElementById('result-tree');
    var errorMessage     = document.getElementById('error-message');
    var copyBtn          = document.getElementById('copy-btn');
    var selectedFile     = null;

    // ── State machine ─────────────────────────────────────────────
    function showState(name) {
        stateUpload.hidden  = name !== 'upload';
        stateLoading.hidden = name !== 'loading';
        stateResult.hidden  = name !== 'result';
        stateError.hidden   = name !== 'error';
    }

    function showError(msg) {
        errorMessage.textContent = msg;
        showState('error');
    }

    // ── File handling ─────────────────────────────────────────────
    function applyFile(file) {
        if (!file || file.type !== 'application/pdf') return;
        selectedFile = file;
        dropIdle.hidden = true;
        dropFilenameText.textContent = file.name;
        dropFilename.hidden = false;
        submitWrap.hidden = false;
    }

    function resetUpload() {
        selectedFile = null;
        fileInput.value = '';
        dropFilenameText.textContent = '';
        dropFilename.hidden = true;
        dropIdle.hidden = false;
        submitWrap.hidden = true;
        showState('upload');
    }

    fileInput.addEventListener('change', function (e) {
        if (e.target.files[0]) applyFile(e.target.files[0]);
    });

    dropZone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropZone.classList.add('drop-active');
    });
    dropZone.addEventListener('dragleave', function () {
        dropZone.classList.remove('drop-active');
    });
    dropZone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropZone.classList.remove('drop-active');
        if (e.dataTransfer.files[0]) applyFile(e.dataTransfer.files[0]);
    });

    document.getElementById('reset-btn').addEventListener('click', resetUpload);
    document.getElementById('error-reset-btn').addEventListener('click', resetUpload);

    // ── Copy button ───────────────────────────────────────────────
    copyBtn.addEventListener('click', function () {
        var text = resultTree.textContent;
        if (!text) return;
        navigator.clipboard.writeText(text).then(function () {
            copyBtn.textContent = MESSAGES.copied;
            setTimeout(function () { copyBtn.textContent = MESSAGES.copy; }, 2000);
        });
    });

    // ── Upload & submit ───────────────────────────────────────────
    submitBtn.addEventListener('click', function () {
        if (!selectedFile) return;
        showState('loading');
        var formData = new FormData();
        formData.append('file', selectedFile);
        fetch('/api/parse', { method: 'POST', body: formData })
            .then(function (res) {
                if (res.status === 429) { showError(MESSAGES.errorRateLimited); return null; }
                if (!res.ok)            { showError(MESSAGES.errorGeneric);     return null; }
                return res.json();
            })
            .then(function (data) { if (data) startPolling(data.job_id); })
            .catch(function () { showError(MESSAGES.errorGeneric); });
    });

    // ── Polling ───────────────────────────────────────────────────
    function startPolling(jobId) {
        var start    = Date.now();
        var timeout  = 60000;
        var interval = 1500;
        var inFlight = false;
        var timer = setInterval(function () {
            if (inFlight) return;
            if (Date.now() - start > timeout) {
                clearInterval(timer);
                showError(MESSAGES.errorTimeout);
                return;
            }
            inFlight = true;
            fetch('/api/parse/' + jobId)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status === 'done') {
                        clearInterval(timer);
                        resultTree.textContent = JSON.stringify(data.result, null, 2);
                        showState('result');
                    } else if (data.status === 'failed') {
                        clearInterval(timer);
                        var code = data.error && data.error.code;
                        showError(code === 'SCANNED_PDF' ? MESSAGES.errorScannedPdf : MESSAGES.errorGeneric);
                    }
                })
                .catch(function () {})
                .finally(function () { inFlight = false; });
        }, interval);
    }

})();
