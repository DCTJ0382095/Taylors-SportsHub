/* Navigation, receipt preview, upload validation and form feedback are initialized by this script. */

document.addEventListener('DOMContentLoaded', () => {
    // Browser interactions are initialized after the document is ready.
    // Mobile pressed-state support is enabled through a passive touch listener.
    document.addEventListener('touchstart', () => { /* An empty passive touch handler is registered for mobile pressed-state support. */ }, { passive: true });

    // Temporary status messages are rendered through the shared toast helper.
    window.showToast = function(message, type = 'info', duration = 3000) {
        // A temporary accessible toast is created with the requested message and type.
        let container = document.getElementById('toast-container');
        if (!container) {
            // The live notification container is created on first use.
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast-pill toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('visible'));

        setTimeout(() => {
            // The toast is hidden after the display period and removed after the transition.
            toast.classList.remove('visible');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, duration);
    };

    // Account-menu visibility and keyboard access are managed.
    const userMenuPill = document.querySelector('.user-menu-pill');
    const userDropdown = document.querySelector('.user-dropdown');
    const setAccountMenuOpen = (open) => {
        // Account-menu visibility, inert state and expanded state are synchronized.
        // Menu updates are skipped when required elements are absent.
        if (!userDropdown || !userMenuPill) return;
        userDropdown.inert = !open;
        userDropdown.classList.toggle('show', open);
        userMenuPill.setAttribute('aria-expanded', String(open));
    };
    if (userMenuPill && userDropdown) {
        // Account-menu listeners are attached when both menu elements are present.
        userMenuPill.addEventListener('click', (e) => {
            // The account menu is toggled without propagating the opening click.
            e.stopPropagation();
            const isOpen = userDropdown.classList.contains('show');
            setAccountMenuOpen(!isOpen);
        });
        document.addEventListener('click', (e) => {
            // The account menu is closed after an outside click.
            // The account menu is closed after a click outside the dropdown.
            if (!userDropdown.contains(e.target)) setAccountMenuOpen(false);
        });
        document.addEventListener('focusin', (e) => {
            // The account menu is closed when focus leaves the trigger and menu.
            // The account menu is closed after focus moves outside the menu controls.
            if (e.target !== userMenuPill && !userDropdown.contains(e.target)) setAccountMenuOpen(false);
        });
    }

    // Mobile navigation, focus containment and background inertness are managed.
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const mobileDrawer = document.querySelector('.mobile-drawer');
    const mobileDrawerClose = document.querySelector('.mobile-drawer-close');
    const mobileDrawerBackdrop = document.querySelector('.mobile-drawer-backdrop');
    let closeDrawer = () => { /* A harmless close callback is retained until drawer initialization. */ };
    const setBackgroundInert = (active) => {
        // Background page regions are made inert while an overlay is active.
        document.querySelectorAll('main, .site-footer').forEach(el => el.inert = active);
    };
    const trapFocus = (event, surface) => {
        // Keyboard focus is wrapped between visible controls within the active surface.
        // Focus trapping is skipped for keys other than Tab.
        if (event.key !== 'Tab') return;
        // Only visible focusable controls are retained for focus wrapping.
        const items = [...surface.querySelectorAll('a[href],button:not(:disabled),input:not(:disabled),textarea,[tabindex="0"]')].filter(el => el.getClientRects().length);
        // Focus wrapping is skipped when no visible controls are available.
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { /* Reverse tabbing is wrapped from the first control to the last. */  event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { /* Forward tabbing is wrapped from the last control to the first. */  event.preventDefault(); first.focus(); }
    };

    if (mobileMenuBtn && mobileDrawer) {
        // Drawer listeners are attached when navigation elements are present.
        const toggleDrawer = (open) => {
            // Drawer visibility, scroll locking, background inertness and focus are synchronized.
            mobileDrawer.inert = !open;
            mobileDrawer.classList.toggle('open', open);
            mobileDrawerBackdrop?.classList.toggle('open', open);
            mobileMenuBtn.setAttribute('aria-expanded', String(open));
            document.body.style.overflow = open ? 'hidden' : '';
            setBackgroundInert(open);
            // Focus is moved into the open drawer or restored to the trigger after closure.
            if (open) mobileDrawerClose?.focus(); else mobileMenuBtn.focus();
        };
        closeDrawer = () => toggleDrawer(false);
        mobileDrawer.addEventListener('keydown', event => trapFocus(event, mobileDrawer));
        window.matchMedia('(min-width: 1101px)').addEventListener('change', event => {
            // An open drawer is closed after the desktop breakpoint is entered.
            // The open drawer is closed at the desktop breakpoint.
            if (event.matches && mobileDrawer.classList.contains('open')) toggleDrawer(false);
        });

        mobileMenuBtn.addEventListener('click', () => toggleDrawer(!mobileDrawer.classList.contains('open')));
        // Drawer closure is attached to the close button when present.
        if (mobileDrawerClose) mobileDrawerClose.addEventListener('click', () => toggleDrawer(false));
        // Drawer closure is attached to the backdrop when present.
        if (mobileDrawerBackdrop) mobileDrawerBackdrop.addEventListener('click', () => toggleDrawer(false));
    }

    // Open navigation is dismissed through the Escape key.
    document.addEventListener('keydown', (e) => {
        // Escape-key navigation handling is registered on the document.
        if (e.key === 'Escape') {
            // Open navigation surfaces are dismissed after Escape is pressed.
            if (userDropdown && userDropdown.classList.contains('show')) {
                // The account menu is closed and focus is restored to the trigger.
                setAccountMenuOpen(false);
                userMenuPill.focus();
            }
            if (mobileDrawer && mobileDrawer.classList.contains('open')) {
                // The mobile drawer is closed through the shared callback.
                closeDrawer();
            }
        }
    });

    // The floating return-to-top control is managed.
    const backToTopBtn = document.getElementById('back-to-top');
    if (backToTopBtn) {
        // Return-to-top interactions are attached when the button is present.
        window.addEventListener('scroll', () => {
            // Button visibility is updated after the scroll threshold is crossed.
            backToTopBtn.classList.toggle('visible', window.scrollY > 300);
        }, { passive: true });

        backToTopBtn.addEventListener('click', () => {
            // The page is scrolled to the top with reduced-motion preferences respected.
            window.scrollTo({
                top: 0,
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'
            });
        });
    }

    // Receipt preview and zoom interactions are managed through a dialog.
    let receiptModal = document.getElementById('receipt-lightbox');
    let currentZoom = 1.0;
    let receiptTrigger = null;

    function initReceiptLightbox() {
        // The receipt preview dialog is initialized on first use.
        if (!receiptModal) {
            // Dialog markup and zoom controls are created only when no dialog exists.
            receiptModal = document.createElement('div');
            receiptModal.id = 'receipt-lightbox';
            receiptModal.className = 'receipt-lightbox-modal';
            receiptModal.setAttribute('role', 'dialog');
            receiptModal.setAttribute('aria-modal', 'true');
            receiptModal.setAttribute('aria-labelledby', 'receipt-modal-title');
            receiptModal.innerHTML = `
                <div class="receipt-lightbox-box">
                    <div class="receipt-lightbox-header">
                        <div>
                            <h4 id="receipt-modal-title">Payment Receipt Preview</h4>
                            <div id="receipt-modal-meta" style="font-size: 0.8rem; color: #86868b; margin-top: 2px;">Click image to toggle 2x zoom or use controls</div>
                        </div>
                        <div class="receipt-lightbox-toolbar">
                            <button type="button" class="receipt-tool-btn" id="receipt-zoom-out" title="Zoom Out">−</button>
                            <span id="receipt-zoom-level" style="font-size: 0.82rem; font-weight: 600; color: #1d1d1f; min-width: 44px; text-align: center;">100%</span>
                            <button type="button" class="receipt-tool-btn" id="receipt-zoom-in" title="Zoom In">+</button>
                            <button type="button" class="receipt-tool-btn" id="receipt-zoom-reset" title="Reset Zoom" style="width: auto; padding: 0 10px; border-radius: 980px; font-size: 0.78rem;">Reset</button>
                            <a href="#" id="receipt-open-raw" target="_blank" class="receipt-tool-btn" title="Open in new tab" style="text-decoration: none;">↗</a>
                            <button type="button" class="receipt-tool-btn receipt-close-btn" id="receipt-modal-close" title="Close preview"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                        </div>
                    </div>
                    <div class="receipt-lightbox-viewport" id="receipt-viewport">
                        <img id="receipt-lightbox-img" src="" alt="Payment Receipt" draggable="false">
                    </div>
                    <div class="receipt-lightbox-footer">
                        <span id="receipt-lightbox-caption">Official transfer verification evidence</span>
                        <button type="button" id="receipt-modal-done" style="padding: 6px 16px; background: #1d1d1f; color: #fff; border: none; border-radius: 980px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">Done</button>
                    </div>
                </div>
            `;
            document.body.appendChild(receiptModal);

            const img = document.getElementById('receipt-lightbox-img');
            const zoomInBtn = document.getElementById('receipt-zoom-in');
            const zoomOutBtn = document.getElementById('receipt-zoom-out');
            const zoomResetBtn = document.getElementById('receipt-zoom-reset');
            const zoomLevel = document.getElementById('receipt-zoom-level');
            const closeBtn = document.getElementById('receipt-modal-close');
            const doneBtn = document.getElementById('receipt-modal-done');

            const setZoom = (z) => {
                // Zoom is rounded and constrained between fifty and three hundred percent.
                currentZoom = Math.min(3.0, Math.max(0.5, Math.round(z * 100) / 100));
                if (img) {
                    // The image scale and zoomed state are updated together.
                    img.style.transform = `scale(${currentZoom})`;
                    img.classList.toggle('zoomed', currentZoom > 1.0);
                }
                if (zoomLevel) zoomLevel.textContent = `${Math.round(currentZoom * 100)}%`;
            };

            zoomInBtn?.addEventListener('click', () => setZoom(currentZoom + 0.25));
            zoomOutBtn?.addEventListener('click', () => setZoom(currentZoom - 0.25));
            zoomResetBtn?.addEventListener('click', () => setZoom(1.0));

            img?.addEventListener('click', () => {
                // The preview is toggled between normal scale and double scale.
                setZoom(currentZoom > 1.0 ? 1.0 : 2.0);
            });

            const closeModal = () => {
                // The dialog is closed, zoom is reset, and triggering focus is restored.
                receiptModal.classList.remove('show');
                setZoom(1.0);
                document.body.style.overflow = '';
                setBackgroundInert(false);
                receiptTrigger?.focus();
            };

            closeBtn?.addEventListener('click', closeModal);
            doneBtn?.addEventListener('click', closeModal);
            receiptModal.addEventListener('click', (e) => {
                // The dialog is dismissed after a direct backdrop click.
                // The dialog is dismissed only for a direct backdrop click.
                if (e.target === receiptModal) closeModal();
            });

            document.addEventListener('keydown', (e) => {
                // Focus trapping and zoom shortcuts are handled only while the dialog is visible.
                // Dialog keyboard handling is skipped while the preview is hidden.
                if (!receiptModal.classList.contains('show')) return;
                trapFocus(e, receiptModal);
                // The dialog is closed after Escape is pressed.
                if (e.key === 'Escape') closeModal();
                // Zoom is increased by one quarter after the increase shortcut.
                if (e.key === '+' || e.key === '=') setZoom(currentZoom + 0.25);
                // Zoom is decreased by one quarter after the decrease shortcut.
                if (e.key === '-' || e.key === '_') setZoom(currentZoom - 0.25);
                // Zoom is reset after the zero key is pressed.
                if (e.key === '0') setZoom(1.0);
            });
        }
    }

    window.openReceiptZoom = function(url, title = 'Payment Receipt Preview', caption = 'Official transfer verification evidence') {
        // Receipt content is assigned and the preview is opened with focus moved inside.
        receiptTrigger = document.activeElement;
        initReceiptLightbox();
        const img = document.getElementById('receipt-lightbox-img');
        const titleEl = document.getElementById('receipt-modal-title');
        const captionEl = document.getElementById('receipt-lightbox-caption');
        const rawLink = document.getElementById('receipt-open-raw');

        // The receipt image URL is assigned when the image element is present.
        if (img) img.src = url;
        // The preview title is assigned when the title element is present.
        if (titleEl) titleEl.textContent = title;
        // The preview caption is assigned when the caption element is present.
        if (captionEl) captionEl.textContent = caption;
        // The original receipt URL is assigned to the external link.
        if (rawLink) rawLink.href = url;

        currentZoom = 1.0;
        if (img) {
            // A newly opened receipt is restored to the default image scale.
            img.style.transform = 'scale(1)';
            img.classList.remove('zoomed');
        }
        const zoomLevel = document.getElementById('receipt-zoom-level');
        // The displayed zoom level is restored to one hundred percent.
        if (zoomLevel) zoomLevel.textContent = '100%';

        receiptModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        setBackgroundInert(true);
        document.getElementById('receipt-modal-close')?.focus();
    };

    // Receipt-preview listeners are attached to marked trigger elements.
    document.querySelectorAll('[data-receipt-zoom]').forEach(trigger => {
        // Each receipt-preview trigger is assigned a click listener.
        trigger.addEventListener('click', (e) => {
            // Receipt metadata is read from the trigger before the dialog is opened.
            e.preventDefault();
            const url = trigger.getAttribute('data-receipt-zoom');
            const title = trigger.getAttribute('data-receipt-title') || 'Payment Receipt Preview';
            const caption = trigger.getAttribute('data-receipt-caption') || 'Official transfer verification evidence';
            // The preview is opened only when a receipt URL is available.
            if (url) window.openReceiptZoom(url, title, caption);
        });
    });

    // Receipt selection, drag-and-drop validation and file metadata are managed.
    const fileInput = document.getElementById('receipt');
    const dropzone = document.getElementById('receipt-dropzone');
    const previewContainer = document.getElementById('receipt-upload-preview');

    if (fileInput) {
        // Receipt validation and preview handlers are attached when the file field is present.
        const fieldError = document.getElementById('receipt-error');
        const handleFileSelected = (file) => {
            // A selected receipt is checked before filename and size details are displayed.
            // Validation is skipped when no file is selected.
            if (!file) return;
            // Previous receipt validation feedback is cleared.
            if (fieldError) fieldError.textContent = '';
            fileInput.removeAttribute('aria-invalid');
            const validTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            if (!validTypes.includes(file.type)) {
                // Unsupported file types are rejected and the selected file is cleared.
                // Allowed receipt formats are explained after a type error.
                if (fieldError) fieldError.textContent = 'Choose a JPG, PNG, WebP or PDF file, then retry.';
                fileInput.setAttribute('aria-invalid', 'true');
                fileInput.value = '';
                if (previewContainer) { /* The previous preview is hidden after a type validation failure. */  previewContainer.hidden = true; previewContainer.style.display = 'none'; }
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                // Files above five megabytes are rejected and the selected file is cleared.
                // The five-megabyte limit is explained after a size error.
                if (fieldError) fieldError.textContent = 'File exceeds 5 MB. Choose a smaller file, then retry.';
                fileInput.setAttribute('aria-invalid', 'true');
                fileInput.value = '';
                if (previewContainer) { /* The previous preview is hidden after a size validation failure. */  previewContainer.hidden = true; previewContainer.style.display = 'none'; }
                return;
            }

            if (previewContainer) {
                // Accepted file metadata is displayed in the upload preview.
                const nameEl = previewContainer.querySelector('.preview-name');
                const sizeEl = previewContainer.querySelector('.preview-size');
                const isPdf = file.type === 'application/pdf';

                // The selected filename is displayed when the preview label is present.
                if (nameEl) nameEl.textContent = file.name;
                if (sizeEl) sizeEl.textContent = `${(file.size / (1024 * 1024)).toFixed(2)} MB · ${isPdf ? 'PDF Document' : 'Image Receipt'}`;

                previewContainer.style.display = 'flex';
                previewContainer.hidden = false;
            }
        };

        fileInput.addEventListener('change', () => {
            // Native file-picker changes are forwarded to receipt validation.
            if (fileInput.files && fileInput.files[0]) {
                // The first selected file is validated when a file is available.
                handleFileSelected(fileInput.files[0]);
            }
        });

        if (dropzone) {
            // Drag-and-drop listeners are attached when the upload area is present.
            ['dragenter', 'dragover'].forEach(name => {
                // Enter and hover events are assigned the active drop-zone treatment.
                dropzone.addEventListener(name, (e) => {
                    // Default drag handling is suppressed and the active drop state is applied.
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('drag-over');
                });
            });
            ['dragleave', 'drop'].forEach(name => {
                // Leave and drop events are assigned drop-state cleanup.
                dropzone.addEventListener(name, (e) => {
                    // Default drag handling is suppressed and the active drop state is removed.
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('drag-over');
                });
            });
            dropzone.addEventListener('drop', (e) => {
                // Dropped files are forwarded to the native file field and validation helper.
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
                    // The dropped file collection is assigned before the first file is validated.
                    fileInput.files = e.dataTransfer.files;
                    handleFileSelected(e.dataTransfer.files[0]);
                }
            });
        }
    }

    // Submitter values are preserved before buttons are disabled for pending submissions.
    document.querySelectorAll('form').forEach(form => {
        // Submission feedback handlers are attached to each form.
        form.addEventListener('submit', event => {
            // Pending submission labels and duplicate-submit protection are applied to opted-in buttons.
            const button = event.submitter;
            // Submission feedback is skipped for buttons without a pending label.
            if (!button?.dataset.pendingLabel) return;
            if (form.dataset.submitting) { /* A repeated submission is prevented while the form is marked submitting. */  event.preventDefault(); return; }
            form.dataset.submitting = 'true';
            if (button.name) {
                // The submitter's name and value are retained through a hidden field before disabling.
                const hidden = document.createElement('input');
                hidden.type = 'hidden'; hidden.name = button.name; hidden.value = button.value;
                form.appendChild(hidden);
            }
            button.textContent = button.dataset.pendingLabel;
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach(item => item.disabled = true);
        });
    });
    window.addEventListener('pageshow', event => { /* Persisted pages are reloaded after restoration from browser history. */  if (event.persisted) window.location.reload(); });
    document.querySelector('[data-focus-error]')?.focus();

    // Page-link copying is handled with success and failure feedback.
    document.querySelectorAll('[data-copy-link]').forEach(btn => {
        // Clipboard listeners are attached to each copy-link control.
        btn.addEventListener('click', (e) => {
            // The configured link or current page URL is copied after default navigation is prevented.
            e.preventDefault();
            const textToCopy = btn.getAttribute('data-copy-link') || window.location.href;
            navigator.clipboard.writeText(textToCopy).then(() => {
                // Successful copying is acknowledged through the toast helper when available.
                // Successful copying is acknowledged when the toast helper is available.
                if (window.showToast) window.showToast('Link copied to clipboard!', 'success');
            }).catch(() => {
                // Clipboard failure is reported through the toast helper when available.
                // Failed copying is reported when the toast helper is available.
                if (window.showToast) window.showToast('Failed to copy link.', 'error');
            });
        });
    });
});
