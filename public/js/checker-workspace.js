(() => {
    const root = document.querySelector('[data-checker-workspace]');
    if (!root) return;
    const form = root.querySelector('[data-checker-form]');
    const viewer = root.querySelector('.checker-viewer');
    const frame = root.querySelector('[data-checker-frame]');
    const divider = root.querySelector('[data-checker-divider]');
    const tools = root.querySelector('.checker-tools');
    new ResizeObserver(() => root.style.setProperty('--checker-tools-height', `${tools.offsetHeight}px`)).observe(tools);
    function setMode(mode) {
        root.dataset.mode = mode;
        form.hidden = mode === 'pdf';
        viewer.hidden = mode === 'form';
        divider.hidden = mode !== 'split';
        root.querySelectorAll('[data-checker-mode][aria-pressed]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.checkerMode === mode)));
    }
    root.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.checkerMode) setMode(button.dataset.checkerMode);
        if (button.dataset.checkerPreview) {
            // Only an explicit document selection changes the PDF; VAT/COA edits never reload it.
            if (frame.getAttribute('src') !== button.dataset.checkerPreview) frame.src = button.dataset.checkerPreview;
            frame.hidden = false;
            root.querySelector('[data-checker-empty]').hidden = true;
            root.querySelector('[data-checker-pdf-title]').textContent = `Invoice ${button.dataset.checkerTitle}`;
            setMode('split');
        }
    });
    function resize(percent) {
        percent = Math.max(35, Math.min(65, percent));
        root.style.setProperty('--checker-left', `${percent}fr`);
        root.style.setProperty('--checker-right', `${100 - percent}fr`);
        divider.setAttribute('aria-valuenow', String(Math.round(percent)));
    }
    divider.addEventListener('pointerdown', event => {
        divider.setPointerCapture(event.pointerId);
        root.classList.add('checker-resizing');
    });
    divider.addEventListener('pointermove', event => {
        if (!divider.hasPointerCapture(event.pointerId)) return;
        const bounds = root.getBoundingClientRect();
        resize((event.clientX - bounds.left) / bounds.width * 100);
    });
    divider.addEventListener('lostpointercapture', () => root.classList.remove('checker-resizing'));
    divider.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        resize(event.key === 'Home' ? 35 : event.key === 'End' ? 65 : Number(divider.getAttribute('aria-valuenow')) + (event.key === 'ArrowLeft' ? -2 : 2));
    });
})();
