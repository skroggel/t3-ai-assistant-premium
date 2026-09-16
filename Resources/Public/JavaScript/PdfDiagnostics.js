(() => {
    'use strict';

    const selector = '[data-region-id]';

    const setActive = (target, active) => {
        const page = target.closest('.aiassistant-pdf-diagnostics__page');
        const regionId = target.dataset.regionId;
        if (!page || !regionId) {
            return;
        }

        page.querySelectorAll(selector).forEach((candidate) => {
            if (candidate.dataset.regionId === regionId) {
                candidate.classList.toggle('is-active', active);
            }
        });
    };

    document.addEventListener('pointerover', (event) => {
        const target = event.target.closest?.(selector);
        if (target) {
            setActive(target, true);
        }
    });

    document.addEventListener('pointerout', (event) => {
        const target = event.target.closest?.(selector);
        if (!target || event.relatedTarget?.closest?.(selector)?.dataset.regionId === target.dataset.regionId) {
            return;
        }
        setActive(target, false);
    });

    document.addEventListener('focusin', (event) => {
        const target = event.target.closest?.(selector);
        if (target) {
            setActive(target, true);
        }
    });

    document.addEventListener('focusout', (event) => {
        const target = event.target.closest?.(selector);
        if (target) {
            setActive(target, false);
        }
    });
})();
