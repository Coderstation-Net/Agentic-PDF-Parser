// public/js/app.js

$(function() {
    const $pdfUrlInput = $('#pdf-url');
    const pdfUrl = $pdfUrlInput.val() || '';
    const $pdfViewer = $('#pdf-viewer');
    const $saveContextBtn = $('#save-context-btn');
    const $fileInput = $('#file-input');
    const $uploadForm = $('.upload-section');
    const $contextPageCount = $('#context-page-count');
    const $dropZone = $('#drop-zone');

    function checkPrerequisites() {
        let allSentenceFixed = true;
        let allFineTuned = true;
        let hasPages = false;

        $('.context-page').each(function() {
            hasPages = true;
            const $page = $(this);
            const hasText = $page.find('.page-editor').text().trim().length > 0;
            
            const isSentenceFixed = !hasText || ($page.find('.fixed-editor').text().indexOf('Click "Sentence Fixer"') === -1);
            const isFineTuned = !hasText || ($page.find('.qa-row').length > 0);

            if (!isSentenceFixed) allSentenceFixed = false;
            if (!isFineTuned) allFineTuned = false;

            const $regenFineTuneBtn = $page.find('.regen-btn[data-action="fine_tune_page"]');
            const $regenEmbeddingsBtn = $page.find('.regen-btn[data-action="generate_embeddings_page"]');
            const $pageTuneBtn = $page.find('.page-tune-btn');
            const $pageEmbeddingsBtn = $page.find('.page-embeddings-btn');

            if (isSentenceFixed) {
                $regenFineTuneBtn.prop('disabled', false).attr('title', 'Generate Q&A dataset based on this page text');
                $pageTuneBtn.prop('disabled', false).attr('title', 'Re-execute Fine Tuning for this page');
            } else {
                $regenFineTuneBtn.prop('disabled', true).attr('title', 'Sentence Fixer must be done first');
                $pageTuneBtn.prop('disabled', true).attr('title', 'Sentence Fixer must be done first');
            }

            if (isFineTuned) {
                $regenEmbeddingsBtn.prop('disabled', false).attr('title', 'Compute vector embeddings for this page context');
                $pageEmbeddingsBtn.prop('disabled', false).attr('title', 'Re-execute Embeddings for this page');
            } else {
                $regenEmbeddingsBtn.prop('disabled', true).attr('title', 'Fine Tuning must be done first');
                $pageEmbeddingsBtn.prop('disabled', true).attr('title', 'Fine Tuning must be done first');
            }
        });

        if (pdfUrl && hasPages) {
            if (allSentenceFixed) {
                $('#train-ai-btn').prop('disabled', false).attr('title', 'Generate fine-tuning Q&A dataset across all pages');
            } else {
                $('#train-ai-btn').prop('disabled', true).attr('title', 'Sentence Fixer must be done first');
            }

            if (allFineTuned) {
                $('#embeddings-btn').prop('disabled', false).attr('title', 'Generate vector representation for all pages');
            } else {
                $('#embeddings-btn').prop('disabled', true).attr('title', 'Fine Tuning must be done first');
            }
        }
    }

    function resetHeaderIcons() {
        $('#train-ai-btn').prop('disabled', false).removeClass('disabled').find('i').attr('class', 'fa-solid fa-sliders me-1');
        $('#embeddings-btn').prop('disabled', false).removeClass('disabled').find('i').attr('class', 'fa-solid fa-database me-1');
        $('#global-fix-btn, #global-fix-btn-header').prop('disabled', false).removeClass('disabled').find('i').attr('class', 'fa-solid fa-wand-magic-sparkles me-1');
        checkPrerequisites();
    }

    // PDF.js Setup
    const pdfjsLib = window['pdfjs-dist/build/pdf'];
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'public/js/pdf.worker.min.js';

    function renderQaCardHtml(qas, isEditing = false) {
        if (!qas || !qas.length) {
            return `
            <div class="text-center py-5 border rounded bg-light border-dashed">
                <i class="fa-solid fa-sliders fa-3x text-muted mb-3 opacity-50"></i>
                <h6 class="fw-bold">Fine-Tuning Dataset</h6>
                <p class="small text-muted mb-0">Generate 20 Q&A pairs for this page by clicking "Fine Tuning" in the header.</p>
            </div>`;
        }

        let html = `
        <div class="qa-list style-scrollbar" style="min-height: 300px; max-height: 100%; overflow-y: auto; padding-right: 5px;">
            <div class="d-flex flex-column gap-3 pb-2">`;
            
        qas.forEach((qa, idx) => {
            const displayDel = isEditing ? 'display: inline-block;' : 'display: none;';
            const editClass = isEditing ? 'border bg-white' : '';
            const contentEditable = isEditing ? 'true' : 'false';
            
            // Safe html escaping
            const safeQ = $('<div>').text(qa.question).html().replace(/\n/g, '<br>');
            const safeA = $('<div>').text(qa.answer).html().replace(/\n/g, '<br>');

            html += `
                <div class="qa-row card shadow-sm border border-light bg-white rounded-3">
                    <div class="card-body p-3 position-relative">
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 fw-bold">Q&A Pair ${idx + 1}</span>
                            <button type="button" class="btn btn-sm btn-outline-danger qa-delete-btn" style="${displayDel}" data-bs-toggle="tooltip" data-bs-placement="top" title="Remove QA"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                        <div class="mb-3">
                            <label class="fw-bold text-muted small text-uppercase mb-1 ms-1" style="letter-spacing: 0.5px;"><i class="fa-solid fa-circle-question me-1 text-primary"></i> Question</label>
                            <div class="qa-q-text text-dark fw-medium p-2 bg-light rounded-3 border border-secondary border-opacity-10 ${editClass}" contenteditable="${contentEditable}">${safeQ}</div>
                        </div>
                        <div>
                            <label class="fw-bold text-muted small text-uppercase mb-1 ms-1" style="letter-spacing: 0.5px;"><i class="fa-solid fa-comment-dots me-1 text-secondary"></i> Answer</label>
                            <div class="qa-a-text text-secondary p-2 bg-light rounded-3 border border-secondary border-opacity-10 ${editClass}" contenteditable="${contentEditable}">${safeA}</div>
                        </div>
                    </div>
                </div>`;
        });

        html += `
            </div>
        </div>`;
        return html;
    }

    function renderEmbeddingsHtml(vector, pNum) {
        let gridHtml = '';
        vector.forEach(v => {
            const color = v >= 0 ? '#4cc9f0' : '#f72585';
            const bg = v >= 0 ? 'rgba(76, 201, 240, 0.1)' : 'rgba(247, 37, 133, 0.1)';
            gridHtml += `<span class="px-2 py-1 rounded border border-secondary border-opacity-25" style="color: ${color}; background-color: ${bg}; min-width: 68px; text-align: center;">${Number(v).toFixed(5)}</span>`;
        });

        return `
            <div class="p-3 border rounded bg-white shadow-sm" style="font-size: 0.82rem;">
                <div class="position-relative">
                    <div class="position-absolute top-0 end-0 p-2 me-2 small text-white-50 font-monospace fw-bold" style="font-size: 0.65rem; z-index: 10; pointer-events: none; letter-spacing: 0.5px;">
                        SNOWFLAKE-ARCTIC-EMBED2
                    </div>
                    <div class="bg-dark p-3 rounded style-scrollbar mt-1" style="min-height: 300px; max-height: 600px; overflow-y: auto; border: 1px solid #343a40; box-shadow: inset 0 2px 5px rgba(0,0,0,0.2);">
                        <div class="d-flex flex-wrap gap-2 font-monospace" style="font-size: 0.7rem;">
                            ${gridHtml}
                        </div>
                    </div>
                </div>
            </div>`;
    }

    // Toast Notification System
    window.showToast = function(msg, tone = 'primary', duration = 4000) {
        const $container = $('#toast-container');
        if (!$container.length) return;
        
        const toastId = 'toast-' + Date.now();
        const toastHTML = `
            <div id="${toastId}" class="toast align-items-center text-white bg-${tone} border-0 mb-2 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fa-solid fa-circle-info me-2"></i> ${msg}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" data-bs-toggle="tooltip" data-bs-placement="top" title="Dismiss notification"></button>
                </div>
            </div>`;
        
        $container.append(toastHTML);
        const $toastElement = $('#' + toastId);
        const toast = new bootstrap.Toast($toastElement[0], { autohide: true, delay: duration });
        toast.show();
        
        $toastElement.on('hidden.bs.toast', function () {
            $(this).remove();
        });
    };

    // Header Actions
    // Header Actions
    $('#train-ai-btn').on('click', async function() {
        if (!pdfUrl) return;
        const $btn = $(this);
        const $icon = $btn.find('i');
        
        $btn.prop('disabled', true).addClass('disabled');
        $icon.removeClass('fa-sliders').addClass('fa-spinner fa-spin');
        showToast('Generating Fine-Tuning Q&A dataset...', 'info');
        
        const name = pdfUrl.split('/').pop() || 'document';
        
        // Start polling and show progress overlay immediately
        startProgressPolling(name, 'Fine Tuning Agent', 'fine_tuning');
        $('#progress-overlay').removeClass('d-none').addClass('d-flex');
        
        try {
            const res = await fetch(`?start_fine_tuning=1&filename=${encodeURIComponent(name)}`);
            const j = await res.json();
            
            if (!j.success) {
                showToast('Failed to start fine-tuning: ' + (j.error || 'Server error'), 'danger');
                $('#progress-overlay').addClass('d-none').removeClass('d-flex');
                $btn.prop('disabled', false).removeClass('disabled');
                $icon.removeClass('fa-spinner fa-spin').addClass('fa-sliders');
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
            }
        } catch (err) {
            showToast('Network error while running Fine-Tuning.', 'danger');
            $('#progress-overlay').addClass('d-none').removeClass('d-flex');
            $btn.prop('disabled', false).removeClass('disabled');
            $icon.removeClass('fa-spinner fa-spin').addClass('fa-sliders');
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
        }
    });

    $('#embeddings-btn').on('click', async function() {
        if (!pdfUrl) return;
        const $btn = $(this);
        const $icon = $btn.find('i');
        
        $btn.prop('disabled', true).addClass('disabled');
        $icon.removeClass('fa-database').addClass('fa-spinner fa-spin');
        showToast('Generating vector embeddings...', 'info');
        
        const name = pdfUrl.split('/').pop() || 'document';
        
        // Start polling and show progress overlay immediately
        startProgressPolling(name, 'Embeddings Agent', 'embeddings');
        $('#progress-overlay').removeClass('d-none').addClass('d-flex');
        
        try {
            const res = await fetch(`?start_embeddings=1&filename=${encodeURIComponent(name)}`);
            const j = await res.json();
            
            if (!j.success) {
                showToast('Failed to start embeddings: ' + (j.error || 'Server error'), 'danger');
                $('#progress-overlay').addClass('d-none').removeClass('d-flex');
                $btn.prop('disabled', false).removeClass('disabled');
                $icon.removeClass('fa-spinner fa-spin').addClass('fa-database');
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
            }
        } catch (err) {
            showToast('Network error while starting embeddings generation.', 'danger');
            $('#progress-overlay').addClass('d-none').removeClass('d-flex');
            $btn.prop('disabled', false).removeClass('disabled');
            $icon.removeClass('fa-spinner fa-spin').addClass('fa-database');
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
        }
    });
    $('#upload-parse-btn').on('click', () => $fileInput.click());
    $('#clear-btn').on('click', () => window.location.href = '?clear_file=1');
    $('#cancel-parse-btn').on('click', async function() {
        if (!pdfUrl) return;
        const name = pdfUrl.split('/').pop() || 'document';
        try {
            // Cancel parser, fixer, fine-tuning, and embeddings
            await fetch('?cancel_parse=1&filename=' + encodeURIComponent(name));
            await fetch('?cancel_sentence_fixer=1&filename=' + encodeURIComponent(name));
            await fetch('?cancel_fine_tuning=1&filename=' + encodeURIComponent(name));
            await fetch('?cancel_embeddings=1&filename=' + encodeURIComponent(name));
            showToast('Cancelling process...', 'warning');
            $(this).prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Cancelling');
        } catch (err) {
            console.error('Cancel failed', err);
        }
    });

    $fileInput.on('change', function() {
        if (this.files.length) {
            $uploadForm.submit();
        }
    });

    let progressInterval = null;
    let loadedPages = new Set();       // tracks parser-loaded page numbers
    let fixerLoadedPages = new Set(); // tracks fixer-loaded page numbers
    let fineTuningLoadedPages = new Set(); // tracks fine-tuning-loaded page numbers
    let embeddingsLoadedPages = new Set(); // tracks embeddings-loaded page numbers
    
    function renderContextBlocksJS(blocks) {
        if (!blocks || !blocks.length) return '';
        let html = '';
        let seen = new Set();
        blocks.forEach(b => {
            let text = (b.text || '').trim();
            if (!text || seen.has(text)) return;
            seen.add(text);
            
            let kind = b.kind || 'Text';
            if (kind.toLowerCase() === 'table' && text.includes('|')) {
                html += `<div class="extracted-table mb-3"><div class="page-block-text"><pre class="mb-0">${text.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre></div></div>`;
            } else {
                let paras = text.split(/\n\s*\n/);
                paras.forEach(p => {
                    if (p.trim()) {
                        html += `<div class="extracted-block mb-3"><div class="page-block-text">${p.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}</div></div>`;
                    }
                });
            }
        });
        return html;
    }

    async function checkNewPages(filename, total) {
        // If there are no loaded pages yet, clear the empty state message
        if (loadedPages.size === 0 && $('.context-page').length === 0) {
            $('#text-scroll-container').empty();
        }

        for (let i = 1; i <= total; i++) {
            if (!loadedPages.has(i)) {
                try {
                    const res = await fetch(`?get_page=1&filename=${encodeURIComponent(filename)}&page=${i}`);
                    if (res.ok) {
                        const pageData = await res.json();
                        if (pageData && !pageData.error) {
                            loadedPages.add(i);
                            
                            // Render page card
                            const tmpl = document.getElementById('page-card-template').content.cloneNode(true);
                            const $card = $(tmpl).find('.context-page');
                            
                            $card.attr('data-page', pageData.page_number || i);
                            $card.attr('data-source-page', pageData.source_page || i);
                            $card.attr('data-page-index', i - 1);
                            $card.attr('data-page-width', pageData.width || 0);
                            $card.attr('data-page-height', pageData.height || 0);
                            $card.find('.page-number-input').val(pageData.page_number || i);
                            
                            const blocksHtml = renderContextBlocksJS(pageData.blocks || []);
                            $card.find('.page-editor').html(blocksHtml);
                            
                            $('#text-scroll-container').append($card);
                            
                            // Trigger fade in
                            requestAnimationFrame(() => {
                                $card.css('opacity', '1');
                            });
                            
                            updatePageCountText();
                            applyAspectRatios();
                            
                            // Smoothly scroll and add premium glow highlight to page card
                            $card.addClass('highlight-glow');
                            setTimeout(() => $card.removeClass('highlight-glow'), 2000);
                            $card.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
                            
                            // Scroll PDF to this page
                            const $pdfTarget = $(`.pdf-page-wrapper[data-page="${pageData.source_page || i}"]`);
                            if ($pdfTarget.length) {
                                $pdfTarget[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }
                        }
                    }
                } catch (e) {
                    // Ignore errors, page might not exist yet
                }
            }
        }
    }

    async function checkNewFixedPages(filename, upToPage) {
        // Determine total pages from DOM (page cards already rendered by parser)
        const totalInDom = $('.context-page').length;
        const total = Math.max(upToPage || 0, totalInDom);
        if (total === 0) return;

        for (let i = 1; i <= total; i++) {
            if (!fixerLoadedPages.has(i)) {
                try {
                    const res = await fetch(`?get_fixed_page=1&filename=${encodeURIComponent(filename)}&page=${i}`);
                    if (res.ok) {
                        const pageData = await res.json();
                        if (pageData && !pageData.error) {
                            fixerLoadedPages.add(i);

                            // Find the card matching this page number
                            const $card = $(`.context-page[data-page="${i}"]`);
                            if ($card.length) {
                                const $editor = $card.find('.fixed-editor');
                                let fixedHtml = '';
                                if (pageData.fixed_blocks && pageData.fixed_blocks.length) {
                                    fixedHtml = renderContextBlocksJS(pageData.fixed_blocks);
                                } else if (pageData.fixed) {
                                    fixedHtml = pageData.fixed.replace(/\n/g, '<br>');
                                }

                                if (fixedHtml) {
                                    $editor.fadeOut(200, function() {
                                        $(this).html(fixedHtml).fadeIn(200);
                                    });
                                    // Auto-switch to Fixed Sentence tab
                                    $card.find('.tab-btn[data-tab="fixed"]').click();

                                    // Smoothly scroll and add premium glow highlight
                                    $card.addClass('highlight-glow');
                                    setTimeout(() => $card.removeClass('highlight-glow'), 2000);
                                    $card.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
                                }
                            }
                        }
                    }
                } catch (e) {
                    // File may not be ready yet — keep polling
                }
            }
        }
    }

    async function checkNewFineTunedPages(filename, upToPage) {
        const totalInDom = $('.context-page').length;
        const total = Math.max(upToPage || 0, totalInDom);
        if (total === 0) return;

        for (let i = 1; i <= total; i++) {
            if (!fineTuningLoadedPages.has(i)) {
                try {
                    const res = await fetch(`?get_fine_tuned_page=1&filename=${encodeURIComponent(filename)}&page=${i}`);
                    if (res.ok) {
                        const pageData = await res.json();
                        if (pageData && !pageData.error) {
                            fineTuningLoadedPages.add(i);

                            // Find the card matching this page number
                            const $card = $(`.context-page[data-page="${i}"]`);
                            if ($card.length) {
                                const $qaContainer = $card.find('.qa-container');
                                if (pageData.qa_pairs && pageData.qa_pairs.length) {
                                    const isEditing = $card.hasClass('editing');
                                    const html = renderQaCardHtml(pageData.qa_pairs, isEditing);
                                    $qaContainer.fadeOut(200, function() {
                                        $(this).html(html).fadeIn(200);
                                    });
                                    // Auto-switch tab to fine-tuning
                                    $card.find('.tab-btn[data-tab="fine-tuning"]').click();

                                    // Smoothly scroll and add premium glow highlight
                                    $card.addClass('highlight-glow');
                                    setTimeout(() => $card.removeClass('highlight-glow'), 2000);
                                    $card.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
                                }
                            }
                        }
                    }
                } catch (e) {
                    // File may not be ready yet — keep polling
                }
            }
        }
    }

    async function checkNewEmbeddingsPages(filename, upToPage) {
        const totalInDom = $('.context-page').length;
        const total = Math.max(upToPage || 0, totalInDom);
        if (total === 0) return;

        for (let i = 1; i <= total; i++) {
            if (!embeddingsLoadedPages.has(i)) {
                try {
                    const res = await fetch(`?get_embeddings_page=1&filename=${encodeURIComponent(filename)}&page=${i}`);
                    if (res.ok) {
                        const pageData = await res.json();
                        if (pageData && !pageData.error) {
                            embeddingsLoadedPages.add(i);

                            // Find the card matching this page number
                            const $card = $(`.context-page[data-page="${i}"]`);
                            if ($card.length) {
                                const $embContainer = $card.find('.embeddings-container');
                                if (pageData.embeddings && pageData.embeddings.length) {
                                    const vector = pageData.embeddings;
                                    const html = renderEmbeddingsHtml(vector, i);
                                    $embContainer.fadeOut(200, function() {
                                        $(this).html(html).fadeIn(200);
                                    });
                                    // Auto-switch tab to embeddings
                                    $card.find('.tab-btn[data-tab="embeddings"]').click();

                                    // Smoothly scroll and add premium glow highlight
                                    $card.addClass('highlight-glow');
                                    setTimeout(() => $card.removeClass('highlight-glow'), 2000);
                                    $card.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
                                }
                            }
                        }
                    }
                } catch (e) {
                    // File may not be ready yet — keep polling
                }
            }
        }
    }

    function startProgressPolling(filename, defaultTitle = 'Parsing Document', mode = 'parser') {
        if (progressInterval) clearInterval(progressInterval);
        
        const $overlay   = $('#progress-overlay');
        const $progressBar = $overlay.find('.progress-bar');
        const $percentText = $('#progress-percentage-text');
        const $title     = $('#progress-title');
        const $status    = $('#progress-status');
        const $cancelBtn = $('#cancel-parse-btn');

        // Support backward compatibility for boolean mode (isFixer = true/false)
        let runMode = mode;
        if (runMode === true) runMode = 'fixer';
        if (runMode === false) runMode = 'parser';

        // Reset overlay UI
        $progressBar.css('width', '0%').attr('aria-valuenow', 0);
        $percentText.text('0% (Page 0/0)');
        $title.text(defaultTitle);
        $status.text('Initializing agent pipeline...');
        $cancelBtn.prop('disabled', false).html('<i class="fa-solid fa-xmark me-2"></i> Cancel').show();

        // Dynamically assign premium animated icon based on runMode
        const $progressIcon = $('#progress-icon');
        let iconHtml = '<i class="fa-solid fa-circle-notch fa-spin text-primary"></i>';
        if (runMode === 'parser') {
            iconHtml = '<i class="fa-solid fa-file-pdf fa-spin-pulse text-danger"></i>';
        } else if (runMode === 'fixer') {
            iconHtml = '<i class="fa-solid fa-wand-magic-sparkles fa-bounce text-warning"></i>';
        } else if (runMode === 'fine_tuning') {
            iconHtml = '<i class="fa-solid fa-brain fa-pulse text-primary"></i>';
        } else if (runMode === 'embeddings') {
            iconHtml = '<i class="fa-solid fa-database fa-fade text-info"></i>';
        }
        $progressIcon.html(iconHtml);

        // Always use floating progress style so it never covers the entire page
        $overlay.addClass('floating-progress');

        // Reset the appropriate tracking set
        if (runMode === 'fixer') {
            fixerLoadedPages.clear();
        } else if (runMode === 'fine_tuning') {
            fineTuningLoadedPages.clear();
        } else if (runMode === 'embeddings') {
            embeddingsLoadedPages.clear();
        } else {
            loadedPages.clear();
        }

        let consecutiveErrors = 0;
        progressInterval = setInterval(async () => {
            try {
                // Use the PHP endpoints for parser, fixer, fine tuning and embeddings progress
                let fetchUrl = `?get_progress=1&filename=${encodeURIComponent(filename)}`;
                if (runMode === 'fixer') {
                    fetchUrl = `?get_progress_fixer=1&filename=${encodeURIComponent(filename)}`;
                } else if (runMode === 'fine_tuning') {
                    fetchUrl = `?get_progress_fine_tuning=1&filename=${encodeURIComponent(filename)}`;
                } else if (runMode === 'embeddings') {
                    fetchUrl = `?get_progress_embeddings=1&filename=${encodeURIComponent(filename)}`;
                }
                
                const res = await fetch(fetchUrl);
                if (res.ok) {
                    consecutiveErrors = 0; // reset error count on success
                    const data = await res.json();
                    if (data && (data.total > 0 || data.status)) {
                        const pct = parseInt(data.percent) || 0;
                        const page = parseInt(data.page) || 0;
                        const total = parseInt(data.total) || 0;
                        
                        const title = data.title || defaultTitle;
                        const status = data.status || `Processing page ${page} of ${total}...`;
                        
                        $progressBar.css('width', pct + '%').attr('aria-valuenow', pct);
                        $percentText.text(`${pct}% (Page ${page}/${total})`);
                        $title.text(title);
                        $status.text(status);
                        
                        // Load newly completed pages into UI
                        if (runMode === 'fixer') {
                            await checkNewFixedPages(filename, page);
                        } else if (runMode === 'fine_tuning') {
                            await checkNewFineTunedPages(filename, page);
                        } else if (runMode === 'embeddings') {
                            await checkNewEmbeddingsPages(filename, page);
                        } else {
                            await checkNewPages(filename, page);
                        }
                        
                        // Detect cancellation
                        if (data.status === 'cancelled') {
                            clearInterval(progressInterval);
                            progressInterval = null;
                            setTimeout(() => {
                                $overlay.addClass('d-none').removeClass('d-flex floating-progress');
                                if (runMode === 'parser') window.location.href = '?load_file=' + encodeURIComponent(filename);
                                else if (runMode === 'fixer') showToast('Sentence fixing cancelled.', 'warning');
                                else if (runMode === 'fine_tuning') showToast('Fine tuning cancelled.', 'warning');
                                else if (runMode === 'embeddings') showToast('Embeddings generation cancelled.', 'warning');
                                resetHeaderIcons();
                            }, 1500);
                        }
                        // Detect failure (close progress dialog immediately)
                        else if (data.status === 'failed') {
                            clearInterval(progressInterval);
                            progressInterval = null;
                            $overlay.addClass('d-none').removeClass('d-flex floating-progress');
                            if (runMode === 'parser') showToast('Parsing failed. Please check parser.log.', 'danger');
                            else if (runMode === 'fixer') showToast('Sentence fixing failed. Please check fixer.log.', 'danger');
                            else if (runMode === 'fine_tuning') showToast('Fine tuning failed. Please check fine_tuning.log.', 'danger');
                            else if (runMode === 'embeddings') showToast('Embeddings generation failed. Please check embeddings.log.', 'danger');
                            resetHeaderIcons();
                        }
                        // Detect normal completion: percent is 100 OR status is 'completed' / contains 'Successfully'
                        else if (pct >= 100 || data.status === 'completed' || (data.status && data.status.toLowerCase().includes('successfully'))) {
                            $title.text('Finalizing');
                            $status.text('Complete!');
                            $cancelBtn.hide();
                            clearInterval(progressInterval);
                            progressInterval = null;
                            
                            // Sweep for any remaining pages not yet loaded
                            if (runMode === 'fixer') {
                                await checkNewFixedPages(filename, total);
                            } else if (runMode === 'fine_tuning') {
                                await checkNewFineTunedPages(filename, total);
                            } else if (runMode === 'embeddings') {
                                await checkNewEmbeddingsPages(filename, total);
                            } else {
                                await checkNewPages(filename, total);
                            }
                            
                            setTimeout(() => {
                                $overlay.addClass('d-none').removeClass('d-flex floating-progress');
                                if (runMode === 'parser') window.location.href = '?load_file=' + encodeURIComponent(filename);
                                else if (runMode === 'fixer') {
                                    showToast('Sentence fixing completed!', 'success');
                                } else if (runMode === 'fine_tuning') {
                                    showToast('Fine-tuning completed!', 'success');
                                } else if (runMode === 'embeddings') {
                                    showToast('Embeddings completed!', 'success');
                                }
                                resetHeaderIcons();
                            }, 1000);
                        }
                    }
                } else {
                    consecutiveErrors++;
                    if (consecutiveErrors >= 5) {
                        throw new Error(`Server returned HTTP status ${res.status}`);
                    }
                }
            } catch (err) {
                consecutiveErrors++;
                if (consecutiveErrors >= 5) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                    $overlay.addClass('d-none').removeClass('d-flex floating-progress');
                    showToast('Connection error or server failure. Dialog closed.', 'danger');
                    resetHeaderIcons();
                }
            }
        }, 500);
    }

    async function loadAllEmbeddings(filename) {
        try {
            const res = await fetch(`?get_embeddings=1&filename=${encodeURIComponent(filename)}`);
            if (res.ok) {
                const data = await res.json();
                if (data && Array.isArray(data)) {
                    const embByPage = {};
                    data.forEach(emb => {
                        const pNum = parseInt(emb.page_number) || 1;
                        embByPage[pNum] = emb;
                    });

                    $('.context-page').each(function() {
                        const $page = $(this);
                        const pNum = parseInt($page.find('.page-number-input').val()) || parseInt($page.data('page'));
                        const pageEmb = embByPage[pNum];
                        const $embContainer = $page.find('.embeddings-container');
                        
                        if (pageEmb && pageEmb.embeddings && pageEmb.embeddings.length > 0) {
                            const vector = pageEmb.embeddings;
                            let html = renderEmbeddingsHtml(vector, pNum);
                            $embContainer.html(html);
                            // Auto-switch to embeddings tab
                            $page.find('.tab-btn[data-tab="embeddings"]').click();
                        }
                    });
                }
            }
        } catch (e) {
            console.error('Failed to load global embeddings', e);
        }
    }

    $uploadForm.on('submit', async function(ev) {
        ev.preventDefault();
        
        const files = $fileInput[0].files;
        if (!files || !files.length) return;
        
        const filename = files[0].name;
        
        // Prevent default only if we haven't confirmed overwrite yet
        if ($('input[name="overwrite"]').val() !== '1') {
            try {
                const res = await fetch('?check_file=1&filename=' + encodeURIComponent(filename));
                if (res.ok) {
                    const j = await res.json();
                    if (j.exists) {
                        const ok = confirm(`Overwrite "${filename}"?`);
                        if (!ok) {
                            window.location.href = '?load_file=' + encodeURIComponent(filename);
                            return;
                        }
                        if (!$('input[name="overwrite"]').length) {
                            $uploadForm.append('<input type="hidden" name="overwrite" value="1">');
                        } else {
                            $('input[name="overwrite"]').val('1');
                        }
                    } else {
                        // No file exists, proceed
                        if (!$('input[name="overwrite"]').length) {
                            $uploadForm.append('<input type="hidden" name="overwrite" value="0">');
                        }
                    }
                }
            } catch (err) { console.error('Check failed:', err); }
        }
        
        // Show progress overlay
        $('#progress-overlay').removeClass('d-none').addClass('d-flex');
        startProgressPolling(filename);
        
        // Use Fetch to upload file instead of standard form submit
        const formData = new FormData($uploadForm[0]);
        
        try {
            const uploadRes = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const uploadJson = await uploadRes.json();
            
            if (uploadJson.success) {
                // Update pdfUrl variable immediately
                $pdfUrlInput.val('contexts/' + encodeURIComponent(filename) + '/' + encodeURIComponent(filename));
                
                // Start background parsing
                const parseRes = await fetch('?start_parse=1&filename=' + encodeURIComponent(filename));
                const parseJson = await parseRes.json();
                if (!parseJson.success) {
                    showToast('Failed to start parsing: ' + (parseJson.error || 'Server error'), 'danger');
                    $('#progress-overlay').addClass('d-none').removeClass('d-flex');
                    if (progressInterval) {
                        clearInterval(progressInterval);
                        progressInterval = null;
                    }
                }
            } else {
                showToast(uploadJson.error || 'Upload failed', 'danger');
                $('#progress-overlay').addClass('d-none').removeClass('d-flex');
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
            }
        } catch (e) {
            console.error(e);
            showToast('Network error during upload', 'danger');
            $('#progress-overlay').addClass('d-none').removeClass('d-flex');
            if (progressInterval) clearInterval(progressInterval);
        }
    });

    // Page Actions (Delegated)
    // Page Actions (Delegated)
    function togglePageEditingState($page, isEditing) {
        const $editBtn = $page.find('.page-edit-btn');
        $editBtn.toggleClass('active', isEditing);
        $editBtn.find('.icon-edit').toggle(!isEditing);
        $editBtn.find('.icon-done').toggle(isEditing);
        
        $page.find('.page-number-input').prop('readonly', !isEditing);
        $page.find('.page-editor, .fixed-editor').attr('contenteditable', isEditing);
        
        $page.find('.qa-q-text, .qa-a-text').attr('contenteditable', isEditing).toggleClass('border bg-white', isEditing);
        $page.find('.qa-delete-btn').toggle(isEditing);
        
        if (isEditing) {
            $page.find('.tab-pane.active [contenteditable]').focus();
            $page.css({ height: 'auto', minHeight: ($page[0].scrollHeight + 30) + 'px' });
        } else {
            $page.css({ height: '', minHeight: '' });
            syncExtractedPageHeights();
        }
    }

    async function savePageData($page) {
        const pIdx = parseInt($page.data('page-index'));
        const page_number = parseInt($page.find('.page-number-input').val()) || (pIdx + 1);
        const source_page = parseInt($page.data('source-page')) || (pIdx + 1);
        const width = parseFloat($page.data('page-width')) || 0;
        const height = parseFloat($page.data('page-height')) || 0;

        // Extract blocks preserving actual arrangement
        let blocks = [];
        const $editor = $page.find('.page-editor');
        if ($editor.children('div').length > 0) {
            $editor.children('div').each(function() {
                const isTable = $(this).hasClass('extracted-table');
                const text = $(this).find('.page-block-text').text().trim();
                if (text) {
                    blocks.push({
                        kind: isTable ? 'Table' : 'Text',
                        text: text
                    });
                }
            });
        } else {
            const rawText = $editor.text().trim();
            const paragraphs = rawText.split(/\n\s*\n/);
            paragraphs.forEach(p => {
                if (p.trim()) {
                    blocks.push({
                        kind: 'Text',
                        text: p.trim()
                    });
                }
            });
        }

        // Extract fixed blocks preserving actual arrangement (as a single combined block)
        const $fixedEditor = $page.find('.fixed-editor');
        let fixedTexts = [];
        if ($fixedEditor.children('div').length > 0) {
            $fixedEditor.children('div').each(function() {
                const text = $(this).find('.page-block-text').text().trim();
                if (text) {
                    fixedTexts.push(text);
                }
            });
        } else {
            const rawText = $fixedEditor.text().trim();
            if (rawText) {
                fixedTexts.push(rawText);
            }
        }
        let fixedBlocks = [];
        if (fixedTexts.length > 0) {
            fixedBlocks.push({
                kind: 'Text',
                text: fixedTexts.join('\n\n')
            });
        }

        let qa_pairs = [];
        $page.find('.qa-container tbody .qa-row, .qa-container .qa-row').each(function() {
            const q = $(this).find('.qa-q-text').text().trim();
            const a = $(this).find('.qa-a-text').text().trim();
            if (q && a) {
                qa_pairs.push({
                    page_number: page_number,
                    question: q,
                    answer: a
                });
            }
        });

        const pageData = {
            page_number: page_number,
            source_page: source_page,
            width: width,
            height: height,
            blocks: blocks,
            fixed_blocks: fixedBlocks,
            qa_pairs: qa_pairs
        };

        const filename = $('#pdf-url').val().split('/').pop();
        
        $page.find('.page-save-btn, .page-edit-btn').prop('disabled', true);
        const $saveIcon = $page.find('.page-save-btn i');
        const origClass = $saveIcon.attr('class');
        $saveIcon.attr('class', 'fa-solid fa-spinner fa-spin');

        try {
            const res = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_page',
                    filename: filename,
                    page_index: pIdx,
                    page: pageData
                })
            });
            const j = await res.json();
            if (j.success) {
                showToast(`Page ${page_number} saved successfully!`, 'success');
                $page.removeClass('editing');
                togglePageEditingState($page, false);
            } else {
                showToast('Save failed: ' + (j.error || 'Server error'), 'danger');
            }
        } catch (err) {
            showToast('Save failed due to network error.', 'danger');
        } finally {
            $page.find('.page-save-btn, .page-edit-btn').prop('disabled', false);
            $saveIcon.attr('class', origClass);
        }
    }

    $(document).on('click', '.page-edit-btn', function() {
        const $page = $(this).closest('.context-page');
        const wasEditing = $page.hasClass('editing');
        
        if (wasEditing) {
            savePageData($page);
        } else {
            $page.addClass('editing');
            togglePageEditingState($page, true);
        }
    });

    $(document).on('click', '.page-save-btn', function() {
        const $page = $(this).closest('.context-page');
        savePageData($page);
    });

    $(document).on('click', '#global-fix-btn, #global-fix-btn-header', async function() {
        const $btn = $(this);
        if ($btn.hasClass('disabled')) return;
        if (!pdfUrl) {
            showToast('No document loaded.', 'warning');
            return;
        }

        const name = pdfUrl.split('/').pop() || 'document';
        $btn.addClass('disabled').prop('disabled', true).find('i').removeClass('fa-wand-magic-sparkles').addClass('fa-spinner fa-spin');
        
        // Start polling and show progress overlay immediately
        startProgressPolling(name, 'Sentence Fixer', true);
        $('#progress-overlay').removeClass('d-none').addClass('d-flex');
        
        try {
            const res = await fetch(`?start_sentence_fixer=1&filename=${encodeURIComponent(name)}`);
            const j = await res.json();
            
            if (!j.success) {
                showToast('Failed to start sentence fixer: ' + (j.error || 'Server error'), 'danger');
                $('#progress-overlay').addClass('d-none').removeClass('d-flex');
                $btn.removeClass('disabled').prop('disabled', false).find('i').removeClass('fa-spinner fa-spin').addClass('fa-wand-magic-sparkles');
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
            }
        } catch (err) {
            showToast('Network error while running global sentence fixer.', 'danger');
            $('#progress-overlay').addClass('d-none').removeClass('d-flex');
            $btn.removeClass('disabled').prop('disabled', false).find('i').removeClass('fa-spinner fa-spin').addClass('fa-wand-magic-sparkles');
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
        }
    });

    $(document).on('click', '.page-delete-btn', async function() {
        const $page = $(this).closest('.context-page');
        const pIdx = parseInt($page.data('page-index'));
        const pageNum = parseInt($page.find('.page-number-input').val()) || (pIdx + 1);
        if (confirm(`Remove page ${pageNum}? This will permanently delete it from the server.`)) {
            const filename = $('#pdf-url').val().split('/').pop();
            try {
                const res = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_page', page_number: pageNum, filename: filename })
                });
                const j = await res.json();
                if (j.success) {
                    $page.fadeOut(300, function() {
                        $(this).remove();
                        updatePageCountText();
                        showToast(`Page ${pageNum} deleted successfully.`, 'success');
                    });
                } else {
                    showToast('Delete failed: ' + (j.error || 'Server error'), 'danger');
                }
            } catch (err) {
                showToast('Delete failed due to network error.', 'danger');
            }
        }
    });

    $(document).on('click', '.qa-delete-btn', function() {
        if (confirm('Remove this Q&A pair?')) {
            $(this).closest('tr, .qa-row').fadeOut(300, function() { $(this).remove(); });
        }
    });

    $(document).on('click', '.page-add-btn', function() {
        const $p = $(this).closest('.context-page');
        const pIdx = parseInt($p.data('page-index'));
        const parentPageNum = parseInt($p.find('.page-number-input').val()) || (pIdx + 1);
        const newPageNum = parentPageNum + 1;

        // Clone the page card
        const $newP = $p.clone();
        $newP.removeClass('active').addClass('editing');
        
        // Update attributes and index
        $newP.attr('data-page-index', pIdx + 1);
        $newP.attr('data-page', newPageNum);
        $newP.attr('data-source-page', newPageNum);
        $newP.find('.page-number-input').val(newPageNum).prop('readonly', false);

        // Clear editor contents
        $newP.find('.page-editor').html('<div class="extracted-block mb-3"><div class="page-block-text">Enter new page text here...</div></div>').attr('contenteditable', true);
        $newP.find('.fixed-editor').html('<div class="text-muted fst-italic">Click "Sentence Fixer" to process this text.</div>').attr('contenteditable', true);
        
        // Reset Fine-Tuning and Embeddings tabs to empty states
        $newP.find('.qa-container').html(`
            <div class="text-center py-5 border rounded bg-light border-dashed">
                <i class="fa-solid fa-sliders fa-3x text-muted mb-3 opacity-50"></i>
                <h6 class="fw-bold">Fine-Tuning Dataset</h6>
                <p class="small text-muted mb-0">Generate 20 Q&A pairs for this page by clicking "Fine Tuning" in the header.</p>
            </div>
        `);
        $newP.find('.embeddings-container').html(`
            <div class="text-center py-5 border rounded bg-light border-dashed">
                <i class="fa-solid fa-code-branch fa-3x text-muted mb-3 opacity-50"></i>
                <h6 class="fw-bold">Vector Representation</h6>
                <p class="small text-muted mb-0">Generate a page-level vector embedding by clicking "Embeddings" in the header.</p>
            </div>
        `);

        // Show edit state icons and buttons
        const $editBtn = $newP.find('.page-edit-btn');
        $editBtn.addClass('active');
        $editBtn.find('.icon-edit').hide();
        $editBtn.find('.icon-done').show();
        $newP.find('.page-save-btn').show();

        // Increment data-page-index for all subsequent pages in DOM
        $p.nextAll('.context-page').each(function() {
            const currentIdx = parseInt($(this).attr('data-page-index'));
            $(this).attr('data-page-index', currentIdx + 1);
        });

        // Insert new page card in DOM
        $p.after($newP);
        updatePageCountText();
        applyAspectRatios();
        
        // Switch to the newly created page
        $newP.find('.tab-btn[data-tab="text"]').click();
        $newP.find('.page-editor').focus();
    });

    $(document).on('click', '.tab-btn', function() {
        const $p = $(this).closest('.context-page');
        const tab = $(this).data('tab');
        $(this).addClass('active').parent().siblings().find('.tab-btn').removeClass('active');
        $p.find('.tab-content-inner').removeClass('show active').filter('.' + tab).addClass('show active');
    });

    $(document).on('click', '.regen-btn', async function() {
        const $btn = $(this);
        const action = $btn.data('action');
        const $page = $btn.closest('.context-page');
        const pIdx = parseInt($page.data('page-index'));
        
        let qa_pairs = [];
        $page.find('.qa-container tbody .qa-row, .qa-container .qa-row').each(function() {
            const q = $(this).find('.qa-q-text').text().trim();
            const a = $(this).find('.qa-a-text').text().trim();
            if (q && a) {
                qa_pairs.push({
                    page_number: parseInt($page.find('.page-number-input').val()) || (pIdx + 1),
                    question: q,
                    answer: a
                });
            }
        });

        const pageData = {
            page_number: parseInt($page.find('.page-number-input').val()) || (pIdx + 1),
            source_page: parseInt($page.data('source-page')) || (pIdx + 1),
            width: parseFloat($page.data('page-width')) || 0,
            height: parseFloat($page.data('page-height')) || 0,
            blocks: [{ kind: 'Text', text: $page.find('.page-editor').text().trim() }],
            fixed_blocks: [{ kind: 'Text', text: $page.find('.fixed-editor').text().trim() }],
            qa_pairs: qa_pairs
        };

        const $icon = $btn.find('i');
        $btn.prop('disabled', true).addClass('disabled');
        $icon.removeClass('fa-arrows-rotate').addClass('fa-spinner fa-spin');

        // Also disable page-fix-btn / page-tune-btn / page-embeddings-btn and show spinner
        const $pageFixBtn = $page.find('.page-fix-btn');
        const $pageTuneBtn = $page.find('.page-tune-btn');
        const $pageEmbeddingsBtn = $page.find('.page-embeddings-btn');
        if (action === 'fix_sentences_page') {
            $pageFixBtn.prop('disabled', true);
            $pageFixBtn.find('i').attr('class', 'fa-solid fa-spinner fa-spin');
        } else if (action === 'fine_tune_page') {
            $pageTuneBtn.prop('disabled', true);
            $pageTuneBtn.find('i').attr('class', 'fa-solid fa-spinner fa-spin');
        } else if (action === 'generate_embeddings_page') {
            $pageEmbeddingsBtn.prop('disabled', true);
            $pageEmbeddingsBtn.find('i').attr('class', 'fa-solid fa-spinner fa-spin');
        }
        
        const filename = $('#pdf-url').val().split('/').pop() || 'document';

        if (action === 'fix_sentences_page') {
            $page.find('.qa-container').html(`
                <div class="text-center py-5 border rounded bg-light border-dashed">
                    <i class="fa-solid fa-sliders fa-3x text-muted mb-3 opacity-50"></i>
                    <h6 class="fw-bold">Fine-Tuning Dataset</h6>
                    <p class="small text-muted mb-0">Generate 20 Q&A pairs for this page by clicking "Fine Tuning" in the header.</p>
                </div>
            `);
            $page.find('.embeddings-container').html(`
                <div class="text-center py-5 border rounded bg-light border-dashed">
                    <i class="fa-solid fa-code-branch fa-3x text-muted mb-3 opacity-50"></i>
                    <h6 class="fw-bold">Vector Representation</h6>
                    <p class="small text-muted mb-0">Generate a page-level vector embedding by clicking "Embeddings" in the header.</p>
                </div>
            `);
            checkPrerequisites();
        } else if (action === 'fine_tune_page') {
            $page.find('.embeddings-container').html(`
                <div class="text-center py-5 border rounded bg-light border-dashed">
                    <i class="fa-solid fa-code-branch fa-3x text-muted mb-3 opacity-50"></i>
                    <h6 class="fw-bold">Vector Representation</h6>
                    <p class="small text-muted mb-0">Generate a page-level vector embedding by clicking "Embeddings" in the header.</p>
                </div>
            `);
            checkPrerequisites();
        }

        try {
            const res = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: action, filename: filename, page: pageData })
            });
            const j = await res.json();
            
            if (j.success && j.data) {
                if (action === 'fix_sentences_page') {
                    $page.find('.fixed-editor').fadeOut(200, function() {
                        $(this).html(j.data).fadeIn(200, function() {
                            checkPrerequisites();
                        });
                    });
                    showToast('Fixed sentence regenerated for this page.', 'success');
                } else if (action === 'fine_tune_page') {
                    const isEditing = $page.hasClass('editing');
                    const html = renderQaCardHtml(j.data, isEditing);
                    $page.find('.qa-container').hide().html(html).fadeIn(200, function() {
                        checkPrerequisites();
                    });
                    showToast('Q&A pairs regenerated for this page.', 'success');
                } else if (action === 'generate_embeddings_page') {
                    const vector = j.data.embeddings;
                    const html = renderEmbeddingsHtml(vector, pageData.page_number);
                    $page.find('.embeddings-container').hide().html(html).fadeIn(200, function() {
                        checkPrerequisites();
                    });
                    showToast('Embeddings regenerated for this page.', 'success');
                }
            } else {
                showToast('Regeneration failed: ' + (j.error || 'Server error'), 'danger');
            }
        } catch (err) {
            showToast('Network error during regeneration.', 'danger');
        } finally {
            $btn.prop('disabled', false).removeClass('disabled');
            $icon.removeClass('fa-spinner fa-spin').addClass('fa-arrows-rotate');
            
            if (action === 'fix_sentences_page') {
                $pageFixBtn.prop('disabled', false);
                $pageFixBtn.find('i').attr('class', 'fa-solid fa-wand-magic-sparkles');
            } else if (action === 'fine_tune_page') {
                $pageTuneBtn.prop('disabled', false);
                $pageTuneBtn.find('i').attr('class', 'fa-solid fa-sliders');
            } else if (action === 'generate_embeddings_page') {
                $pageEmbeddingsBtn.prop('disabled', false);
                $pageEmbeddingsBtn.find('i').attr('class', 'fa-solid fa-database');
            }
        }
    });

    $(document).on('click', '.page-fix-btn', function() {
        const $page = $(this).closest('.context-page');
        // Auto-switch to Fixed Sentence tab so the user can see the progress and output
        $page.find('.tab-btn[data-tab="fixed"]').click();
        // Trigger click of the inner regenerate button
        $page.find('.regen-btn[data-action="fix_sentences_page"]').click();
    });

    $(document).on('click', '.page-tune-btn', function() {
        const $page = $(this).closest('.context-page');
        // Auto-switch to Fine-Tuning tab so the user can see the progress and output
        $page.find('.tab-btn[data-tab="fine-tuning"]').click();
        // Trigger click of the inner regenerate button
        $page.find('.regen-btn[data-action="fine_tune_page"]').click();
    });

    $(document).on('click', '.page-embeddings-btn', function() {
        const $page = $(this).closest('.context-page');
        // Auto-switch to Embeddings tab so the user can see the progress and output
        $page.find('.tab-btn[data-tab="embeddings"]').click();
        // Trigger click of the inner regenerate button
        $page.find('.regen-btn[data-action="generate_embeddings_page"]').click();
    });

    // Utilities
    function updatePageCountText() {
        const count = $('.context-page').length;
        $contextPageCount.text(count ? `${count} pages` : '');
    }

    function applyAspectRatios() {
        $('.context-page').each(function() {
            const w = $(this).data('page-width');
            const h = $(this).data('page-height');
            if (w && h) $(this).css('min-height', ($(this).width() * (h / w)) + 'px');
        });
    }

    function syncExtractedPageHeights() {
        const h = $('.pdf-page-wrapper').first().height();
        if (h > 100) $('.context-page:not(.editing)').css('min-height', h + 'px');
    }

    // Page-level saves are handled directly by savePageData() on container click.

    // PDF View
    async function initPdf() {
        if (!pdfUrl || !$pdfViewer.length) return;
        try {
            const pdf = await pdfjsLib.getDocument(pdfUrl).promise;
            for (let i = 1; i <= pdf.numPages; i++) {
                const page = await pdf.getPage(i);
                const vp = page.getViewport({ scale: 1.5 });
                const $wrap = $('<div class="pdf-page-wrapper mx-auto mb-4"></div>').attr('data-page', i);
                const canvas = document.createElement('canvas');
                canvas.height = vp.height; canvas.width = vp.width;
                $wrap.append(canvas).append(`<div class="pdf-page-number">Page ${i}</div>`);
                $pdfViewer.append($wrap);
                await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
            }
            syncExtractedPageHeights();
            setupObservers();
        } catch (err) { console.error(err); }
    }

    // Synchronization Logic
    function setupObservers() {
        let lastScrolled = null; // 'pdf' or 'text'
        let scrollTimeout = null;
        let isSyncing = false;

        const handleScroll = (type) => {
            if (isSyncing) return;
            lastScrolled = type;
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => lastScrolled = null, 1000);
        };

        $('#pdf-scroll-container').on('scroll', () => handleScroll('pdf'));
        $('#text-scroll-container').on('scroll', () => handleScroll('text'));

        const obs = new IntersectionObserver(entries => {
            entries.forEach(e => {
                if (!e.isIntersecting || e.intersectionRatio < 0.3) return;
                
                const isPdfPage = $(e.target).hasClass('pdf-page-wrapper');
                
                // Only sync IF this side was the one the user scrolled
                if (isPdfPage && lastScrolled === 'pdf') {
                    const $target = $(`.context-page[data-source-page="${$(e.target).data('page')}"]`);
                    if ($target.length) {
                        isSyncing = true;
                        $target[0].scrollIntoView({ behavior: 'auto', block: 'nearest' });
                        setTimeout(() => { isSyncing = false; }, 120);
                        $('.context-page').removeClass('active border-primary border-2');
                        $target.addClass('active border-primary border-2');
                    }
                } else if (!isPdfPage && lastScrolled === 'text') {
                    const $target = $(`.pdf-page-wrapper[data-page="${$(e.target).data('source-page')}"]`);
                    if ($target.length) {
                        isSyncing = true;
                        $target[0].scrollIntoView({ behavior: 'auto', block: 'nearest' });
                        setTimeout(() => { isSyncing = false; }, 120);
                        $('.context-page').removeClass('active border-primary border-2');
                        $(e.target).addClass('active border-primary border-2');
                    }
                }
            });
        }, { threshold: [0.1, 0.3, 0.5] });

        $('.pdf-page-wrapper, .context-page').each((i, el) => obs.observe(el));
    }

    // Drag & Drop
    $pdfViewer.on('dragover', (e) => { e.preventDefault(); $dropZone.addClass('d-flex').removeClass('d-none'); });
    $pdfViewer.on('dragleave', () => $dropZone.addClass('d-none').removeClass('d-flex'));
    $pdfViewer.on('drop', (e) => {
        e.preventDefault(); $dropZone.addClass('d-none').removeClass('d-flex');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length && files[0].type === 'application/pdf') { $fileInput[0].files = files; $uploadForm.submit(); }
    });

    // Load Folder logic
    const $folderInput = $('#folder-input');
    const $serverFoldersList = $('#server-folders-list');

    // Open load folder modal and fetch existing server folders
    $('#load-folder-btn').on('click', function() {
        const modal = new bootstrap.Modal(document.getElementById('loadFolderModal'));
        modal.show();
        loadServerFolders();
    });

    $('#select-local-folder-btn').on('click', function() {
        $folderInput.click();
    });

    async function loadServerFolders() {
        $serverFoldersList.html(`
            <div class="text-center py-4 text-muted bg-white">
                <i class="fa-solid fa-spinner fa-spin me-2"></i> Loading folders...
            </div>
        `);
        try {
            const res = await fetch('?get_existing_folders=1');
            if (res.ok) {
                const folders = await res.json();
                if (folders && folders.length > 0) {
                    let html = '';
                    folders.forEach(f => {
                        const badgExtracted = getBadgeHtml(f.has_extracted, 'Extracted');
                        const badgFixed = getBadgeHtml(f.has_fixed, 'Fixed');
                        const badgFineTuning = getBadgeHtml(f.has_fine_tuning, 'Fine-Tuning');
                        const badgEmbeddings = getBadgeHtml(f.has_embeddings, 'Embeddings');

                        html += `
                            <a href="?load_file=${encodeURIComponent(f.name)}" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between p-3 border-0 border-bottom bg-white" style="transition: var(--transition);">
                                <div class="text-start">
                                    <h6 class="fw-bold mb-1 text-dark">${f.name}</h6>
                                    <small class="text-muted"><i class="fa-solid fa-file-pdf me-1"></i> ${f.pages} pages</small>
                                </div>
                                <div class="d-flex flex-wrap gap-1 justify-content-end" style="max-width: 50%;">
                                    ${badgExtracted}
                                    ${badgFixed}
                                    ${badgFineTuning}
                                    ${badgEmbeddings}
                                </div>
                            </a>`;
                    });
                    $serverFoldersList.html(html);
                } else {
                    $serverFoldersList.html(`
                        <div class="text-center py-5 text-muted bg-white">
                            <i class="fa-solid fa-folder-open fa-2x mb-2 opacity-50"></i>
                            <p class="small mb-0">No folders found on the server.</p>
                        </div>
                    `);
                }
            } else {
                $serverFoldersList.html(`
                    <div class="text-center py-4 text-danger bg-white">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i> Failed to load folders from server.
                    </div>
                `);
            }
        } catch (e) {
            $serverFoldersList.html(`
                <div class="text-center py-4 text-danger bg-white">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> Error loading server folders.
                </div>
            `);
        }
    }

    function getBadgeHtml(active, text) {
        if (active) {
            return `<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-1 fw-bold" style="font-size: 0.65rem;"><i class="fa-solid fa-circle-check me-1"></i>${text}</span>`;
        } else {
            return `<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-pill px-2 py-1 fw-normal opacity-50" style="font-size: 0.65rem;"><i class="fa-solid fa-circle me-1" style="font-size: 0.35rem; vertical-align: middle;"></i>${text}</span>`;
        }
    }

    // Handle local folder selection
    $folderInput.on('change', async function() {
        const files = this.files;
        if (!files || !files.length) return;

        let pdfFile = null;
        for (let i = 0; i < files.length; i++) {
            if (files[i].name.toLowerCase().endsWith('.pdf')) {
                pdfFile = files[i];
                break;
            }
        }

        if (!pdfFile) {
            showToast('No PDF file found in the selected folder. Please ensure the folder contains the original PDF file.', 'danger');
            this.value = ''; // clear input
            return;
        }

        const pdfName = pdfFile.name;
        let overwrite = false;

        // Hide modal
        const modalEl = document.getElementById('loadFolderModal');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();

        try {
            const checkRes = await fetch('?check_folder=1&foldername=' + encodeURIComponent(pdfName));
            if (checkRes.ok) {
                const check = await checkRes.json();
                if (check.exists) {
                    const loadDirect = confirm(`The document "${pdfName}" already exists on the server.\n\nClick OK to load it instantly from the server (highly recommended).\nClick Cancel to overwrite it by uploading files from your local folder.`);
                    if (loadDirect) {
                        window.location.href = '?load_file=' + encodeURIComponent(pdfName);
                        return;
                    }
                    const confirmOverwrite = confirm(`Are you sure you want to overwrite "${pdfName}" on the server? This will delete the existing parsed results.`);
                    if (!confirmOverwrite) {
                        this.value = '';
                        return;
                    }
                    overwrite = true;
                }
            }
        } catch (err) {
            console.error('Check folder failed', err);
        }

        // Show upload progress overlay
        const $overlay = $('#progress-overlay');
        const $progressBar = $overlay.find('.progress-bar');
        const $percentText = $('#progress-percentage-text');
        const $title = $('#progress-title');
        const $status = $('#progress-status');
        const $cancelBtn = $('#cancel-parse-btn');

        $progressBar.css('width', '0%').attr('aria-valuenow', 0);
        $percentText.text('0%');
        $title.text('Uploading Folder');
        $status.text('Reconstructing folder structure on server...');
        $cancelBtn.hide();
        $overlay.removeClass('d-none').addClass('d-flex');

        const formData = new FormData();
        for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
            formData.append('paths[]', files[i].webkitRelativePath || files[i].name);
        }
        if (overwrite) {
            formData.append('overwrite', '1');
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                $progressBar.css('width', percent + '%').attr('aria-valuenow', percent);
                $percentText.text(percent + '%');
            }
        };

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        showToast('Folder uploaded and loaded successfully!', 'success');
                        setTimeout(() => {
                            window.location.href = '?load_file=' + encodeURIComponent(response.filename);
                        }, 500);
                    } else {
                        showToast('Folder upload failed: ' + (response.error || 'Unknown error'), 'danger');
                        $overlay.addClass('d-none').removeClass('d-flex');
                    }
                } catch(err) {
                    showToast('Failed to parse folder upload response.', 'danger');
                    $overlay.addClass('d-none').removeClass('d-flex');
                }
            } else {
                showToast('Upload failed with status ' + xhr.status, 'danger');
                $overlay.addClass('d-none').removeClass('d-flex');
            }
        };

        xhr.onerror = function() {
            showToast('Network error during folder upload.', 'danger');
            $overlay.addClass('d-none').removeClass('d-flex');
        };

        xhr.send(formData);
    });

    // Dynamic Bootstrap 5 Tooltip handler
    $(document).on('mouseenter', '[data-bs-toggle="tooltip"]', function() {
        let titleText = $(this).attr('title') || $(this).attr('data-bs-title') || $(this).attr('data-bs-original-title') || '';
        if (!titleText) return;
        
        // If the element has a native title, move it to data-bs-original-title and remove title to prevent native tooltips
        if ($(this).attr('title')) {
            $(this).attr('data-bs-original-title', titleText);
            $(this).removeAttr('title');
        }
        
        let tooltip = bootstrap.Tooltip.getInstance(this);
        if (!tooltip) {
            tooltip = new bootstrap.Tooltip(this, {
                boundary: document.body,
                trigger: 'manual',
                title: titleText
            });
        } else {
            tooltip.setContent({ '.tooltip-inner': titleText });
        }
        
        tooltip.show();
    });

    $(document).on('mouseleave click', '[data-bs-toggle="tooltip"]', function() {
        const tooltip = bootstrap.Tooltip.getInstance(this);
        if (tooltip) {
            tooltip.hide();
        }
    });

    // Prevent click actions when button is visually disabled
    $(document).on('click', '[data-bs-toggle="tooltip"]', function(e) {
        if ($(this).prop('disabled') || $(this).hasClass('disabled')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }
    });

    // Clean up active tooltips on global changes to prevent lingering tooltips
    $(document).on('click', '.tab-btn, .nav-link', function() {
        $('.tooltip').remove();
    });
    $(window).on('scroll', function() {
        $('.tooltip').remove();
    });
    $('.modal').on('hide.bs.modal', function() {
        $('.tooltip').remove();
    });

    initPdf();
    setTimeout(applyAspectRatios, 500);
    checkPrerequisites();
});
