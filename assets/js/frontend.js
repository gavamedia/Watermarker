/**
 * Watermarker — Frontend upload page logic.
 */
(function () {
    'use strict';

    var cfg       = window.watermarkerConfig || {};
    var dropZone  = document.getElementById('wm-drop-zone');
    var fileInput = document.getElementById('wm-file-input');

    if (!dropZone || !fileInput) return; // No upload area (e.g. letterhead not configured).

    var uploadBtn     = document.getElementById('wm-upload-btn');
    var progressFill  = document.getElementById('wm-progress-fill');
    var processingTxt = document.getElementById('wm-processing-text');
    var resultText    = document.getElementById('wm-result-text');
    var errorText     = document.getElementById('wm-error-text');
    var downloadBtn   = document.getElementById('wm-download-btn');
    var resetBtn      = document.getElementById('wm-reset-btn');
    var retryBtn      = document.getElementById('wm-retry-btn');
    var applyToggle   = document.getElementById('wm-apply-toggle');

    // ---- State helpers -------------------------------------------------

    function setState(state) {
        dropZone.className = 'wm-upload-area';
        if (state) dropZone.classList.add(state);
    }

    function setDone(success) {
        dropZone.className = 'wm-upload-area is-done';
        dropZone.classList.add(success ? 'is-success' : 'is-error');
    }

    function reset() {
        setState('');
        fileInput.value = '';
        if (progressFill) progressFill.style.width = '0%';
    }

    // ---- Drag & drop ---------------------------------------------------

    var dragCounter = 0;

    document.addEventListener('dragenter', function (e) {
        e.preventDefault();
        dragCounter++;
        if (!dropZone.classList.contains('is-processing') && !dropZone.classList.contains('is-done')) {
            setState('is-dragover');
        }
    });

    document.addEventListener('dragleave', function (e) {
        e.preventDefault();
        dragCounter--;
        if (dragCounter <= 0) {
            dragCounter = 0;
            if (dropZone.classList.contains('is-dragover')) {
                setState('');
            }
        }
    });

    document.addEventListener('dragover', function (e) {
        e.preventDefault();
    });

    document.addEventListener('drop', function (e) {
        e.preventDefault();
        dragCounter = 0;

        if (dropZone.classList.contains('is-processing') || dropZone.classList.contains('is-done')) return;

        var files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFile(files[0]);
        }
    });

    // Clicking anywhere in the zone opens the file picker (except when processing/done).
    dropZone.addEventListener('click', function (e) {
        if (dropZone.classList.contains('is-processing') || dropZone.classList.contains('is-done')) return;
        // Don't double-trigger if they clicked the actual button.
        if (e.target === uploadBtn) return;
        fileInput.click();
    });

    if (uploadBtn) {
        uploadBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            fileInput.click();
        });
    }

    fileInput.addEventListener('change', function () {
        if (fileInput.files.length > 0) {
            handleFile(fileInput.files[0]);
        }
    });

    // Reset / retry.
    if (resetBtn) resetBtn.addEventListener('click', function (e) { e.stopPropagation(); reset(); });
    if (retryBtn) retryBtn.addEventListener('click', function (e) { e.stopPropagation(); reset(); });

    // ---- Apply-to toggle -----------------------------------------------

    var applyAll = '1'; // Default: all pages.

    if (applyToggle) {
        var toggleBtns = applyToggle.querySelectorAll('.wm-toggle-btn');
        for (var i = 0; i < toggleBtns.length; i++) {
            toggleBtns[i].addEventListener('click', function () {
                for (var j = 0; j < toggleBtns.length; j++) {
                    toggleBtns[j].classList.remove('is-active');
                }
                this.classList.add('is-active');
                applyAll = this.getAttribute('data-value');
            });
        }
    }

    // ---- Upload --------------------------------------------------------

    function handleFile(file) {
        // Client-side size check.
        if (cfg.maxSize && file.size > cfg.maxSize) {
            showError('File too large. Maximum size is ' + formatBytes(cfg.maxSize) + '.');
            return;
        }

        setState('is-processing');
        if (processingTxt) processingTxt.textContent = 'Uploading ' + file.name + '\u2026';
        if (progressFill) progressFill.style.width = '0%';

        var formData = new FormData();
        formData.append('action', 'watermarker_upload');
        formData.append('nonce', cfg.nonce);
        formData.append('apply_all', applyAll);
        formData.append('file', file);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.ajaxUrl, true);

        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable && progressFill) {
                // Use 0–70% for upload, 70–100% for server processing.
                var pct = Math.round((e.loaded / e.total) * 70);
                progressFill.style.width = pct + '%';
            }
        });

        xhr.upload.addEventListener('load', function () {
            if (processingTxt) processingTxt.textContent = 'Applying letterhead\u2026';
            if (progressFill) progressFill.style.width = '75%';
        });

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;

            if (progressFill) progressFill.style.width = '100%';

            var response;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                showError('Unexpected server response. Please try again.');
                return;
            }

            if (response.success && response.data) {
                showSuccess(response.data);
            } else {
                showError((response.data && response.data.message) || 'An unknown error occurred.');
            }
        };

        xhr.onerror = function () {
            showError('Network error. Please check your connection and try again.');
        };

        xhr.send(formData);
    }

    function showSuccess(data) {
        if (resultText) resultText.textContent = data.message || 'Done!';
        if (downloadBtn) {
            downloadBtn.href = data.download_url;
            downloadBtn.setAttribute('download', data.filename || 'document.pdf');
            downloadBtn.textContent = 'Download Now';
        }
        setDone(true);
    }

    function showError(msg) {
        if (errorText) errorText.textContent = msg;
        setDone(false);
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
})();
