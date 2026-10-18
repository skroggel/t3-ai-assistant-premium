(() => {
    'use strict';

    const bindings = [
        { selector: '[data-region-id]', attribute: 'regionId' },
        { selector: '[data-layout-region-id]', attribute: 'layoutRegionId' },
    ];

    const setActive = (target, binding, active) => {
        const page = target.closest('.aiassistant-pdf-diagnostics__page');
        const regionId = target.dataset[binding.attribute];
        if (!page || !regionId) {
            return;
        }

        page.querySelectorAll(binding.selector).forEach((candidate) => {
            if (candidate.dataset[binding.attribute] === regionId) {
                candidate.classList.toggle('is-active', active);
            }
        });
    };

    const resolveBinding = (target) => bindings.find((binding) => target.matches(binding.selector));

    const activateLayers = (page, selected) => {
        page.querySelectorAll('[data-pdf-diagnostics-layer]').forEach((layer) => {
            layer.hidden = layer.dataset.pdfDiagnosticsLayer !== selected;
        });
        page.querySelectorAll('[data-pdf-diagnostics-text-layer]').forEach((layer) => {
            layer.hidden = layer.dataset.pdfDiagnosticsTextLayer !== selected;
        });
    };

    const chunkRegionIds = (chunk) => new Set(
        (chunk.dataset.regionIds || '').split(',').filter(Boolean),
    );

    const clearChunkHighlight = (page, except = null, clearLocks = false) => {
        page.querySelectorAll('[data-pdf-diagnostics-chunk]').forEach((chunk) => {
            if (chunk === except) {
                return;
            }
            chunk.classList.remove('is-active');
            if (clearLocks) {
                chunk.setAttribute('aria-pressed', 'false');
            }
        });
        page.querySelectorAll('[data-pdf-diagnostics-layer="lines"]').forEach((region) => {
            region.classList.remove('is-chunk-active');
        });
    };

    const activateChunk = (chunk) => {
        const page = chunk.closest('.aiassistant-pdf-diagnostics__page');
        if (!page) {
            return;
        }

        clearChunkHighlight(page, chunk);
        const regionIds = chunkRegionIds(chunk);
        chunk.classList.add('is-active');
        page.querySelectorAll('[data-pdf-diagnostics-layer="lines"]').forEach((region) => {
            region.classList.toggle('is-chunk-active', regionIds.has(region.dataset.regionId));
        });
    };

    const restoreLockedChunk = (page) => {
        const locked = page.querySelector('[data-pdf-diagnostics-chunk][aria-pressed="true"]');
        if (locked) {
            activateChunk(locked);
            locked.setAttribute('aria-pressed', 'true');
            return;
        }
        clearChunkHighlight(page);
    };

    const activateTab = (tab, focus = false) => {
        const workspace = tab.closest('[data-pdf-diagnostics-tabs]');
        const selected = tab.dataset.pdfDiagnosticsTab;
        if (!workspace || !selected) {
            return;
        }

        workspace.querySelectorAll('[data-pdf-diagnostics-tab]').forEach((candidate) => {
            const active = candidate === tab;
            candidate.classList.toggle('is-active', active);
            candidate.setAttribute('aria-selected', active ? 'true' : 'false');
            candidate.tabIndex = active ? 0 : -1;
        });
        workspace.querySelectorAll('[data-pdf-diagnostics-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.pdfDiagnosticsPanel !== selected;
        });

        const page = tab.closest('.aiassistant-pdf-diagnostics__page');
        if (page) {
            page.classList.toggle('is-chunk-mode', selected === 'chunks');
            if (selected !== 'chunks') {
                clearChunkHighlight(page, null, true);
            }
            const selectedLayer = selected === 'layout'
                ? 'layout'
                : selected === 'chunks'
                    ? 'lines'
                    : page.querySelector('[data-pdf-diagnostics-granularity-option].is-active')?.dataset.pdfDiagnosticsGranularityOption || 'lines';
            activateLayers(page, selectedLayer);
        }
        if (focus) {
            tab.focus();
        }
    };

    const activateGranularity = (button, focus = false) => {
        const page = button.closest('.aiassistant-pdf-diagnostics__page');
        const selected = button.dataset.pdfDiagnosticsGranularityOption;
        if (!page || !selected) {
            return;
        }

        page.querySelectorAll('[data-pdf-diagnostics-granularity-option]').forEach((candidate) => {
            const active = candidate === button;
            candidate.classList.toggle('is-active', active);
            candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        activateLayers(page, selected);

        const targetTab = page.querySelector('[data-pdf-diagnostics-tab="text"]');
        if (targetTab) {
            activateTab(targetTab);
        }
        if (focus) {
            button.focus();
        }
    };

    document.addEventListener('click', (event) => {
        const tab = event.target.closest?.('[data-pdf-diagnostics-tab]');
        if (tab) {
            activateTab(tab);
            return;
        }
        const granularity = event.target.closest?.('[data-pdf-diagnostics-granularity-option]');
        if (granularity) {
            activateGranularity(granularity);
            return;
        }
        const chunk = event.target.closest?.('[data-pdf-diagnostics-chunk]');
        if (chunk) {
            const lock = chunk.getAttribute('aria-pressed') !== 'true';
            const page = chunk.closest('.aiassistant-pdf-diagnostics__page');
            if (page) {
                clearChunkHighlight(page, null, true);
                if (lock) {
                    activateChunk(chunk);
                    chunk.setAttribute('aria-pressed', 'true');
                }
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        const chunk = event.target.closest?.('[data-pdf-diagnostics-chunk]');
        if (chunk && ['Enter', ' '].includes(event.key)) {
            event.preventDefault();
            chunk.click();
            return;
        }
        const granularity = event.target.closest?.('[data-pdf-diagnostics-granularity-option]');
        if (granularity && ['ArrowLeft', 'ArrowRight'].includes(event.key)) {
            const options = [...granularity.closest('[data-pdf-diagnostics-granularity]').querySelectorAll('[data-pdf-diagnostics-granularity-option]')];
            const direction = event.key === 'ArrowRight' ? 1 : -1;
            const next = options[(options.indexOf(granularity) + direction + options.length) % options.length];
            event.preventDefault();
            activateGranularity(next, true);
            return;
        }
        const tab = event.target.closest?.('[data-pdf-diagnostics-tab]');
        if (!tab || !['ArrowLeft', 'ArrowRight'].includes(event.key)) {
            return;
        }
        const tabs = [...tab.closest('[role="tablist"]').querySelectorAll('[data-pdf-diagnostics-tab]')];
        const direction = event.key === 'ArrowRight' ? 1 : -1;
        const next = tabs[(tabs.indexOf(tab) + direction + tabs.length) % tabs.length];
        event.preventDefault();
        activateTab(next, true);
    });

    document.addEventListener('pointerover', (event) => {
        const chunk = event.target.closest?.('[data-pdf-diagnostics-chunk]');
        if (chunk) {
            activateChunk(chunk);
            return;
        }
        const target = event.target.closest?.(bindings.map((binding) => binding.selector).join(','));
        const binding = target ? resolveBinding(target) : null;
        if (target && binding) {
            setActive(target, binding, true);
        }
    });

    document.addEventListener('pointerout', (event) => {
        const chunk = event.target.closest?.('[data-pdf-diagnostics-chunk]');
        if (chunk) {
            if (!event.relatedTarget?.closest?.('[data-pdf-diagnostics-chunk]')) {
                const page = chunk.closest('.aiassistant-pdf-diagnostics__page');
                if (page) {
                    restoreLockedChunk(page);
                }
            }
            return;
        }
        const target = event.target.closest?.(bindings.map((binding) => binding.selector).join(','));
        const binding = target ? resolveBinding(target) : null;
        if (!target || !binding) {
            return;
        }
        const related = event.relatedTarget?.closest?.(binding.selector);
        if (related?.dataset[binding.attribute] === target.dataset[binding.attribute]) {
            return;
        }
        setActive(target, binding, false);
    });

    document.addEventListener('focusin', (event) => {
        const chunk = event.target.closest?.('[data-pdf-diagnostics-chunk]');
        if (chunk) {
            activateChunk(chunk);
            return;
        }
        const target = event.target.closest?.(bindings.map((binding) => binding.selector).join(','));
        const binding = target ? resolveBinding(target) : null;
        if (target && binding) {
            setActive(target, binding, true);
        }
    });

    document.addEventListener('focusout', (event) => {
        const chunk = event.target.closest?.('[data-pdf-diagnostics-chunk]');
        if (chunk) {
            const page = chunk.closest('.aiassistant-pdf-diagnostics__page');
            if (page) {
                restoreLockedChunk(page);
            }
            return;
        }
        const target = event.target.closest?.(bindings.map((binding) => binding.selector).join(','));
        const binding = target ? resolveBinding(target) : null;
        if (target && binding) {
            setActive(target, binding, false);
        }
    });
})();
