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
        if (focus) {
            tab.focus();
        }
    };

    document.addEventListener('click', (event) => {
        const tab = event.target.closest?.('[data-pdf-diagnostics-tab]');
        if (tab) {
            activateTab(tab);
        }
    });

    document.addEventListener('keydown', (event) => {
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
        const target = event.target.closest?.(bindings.map((binding) => binding.selector).join(','));
        const binding = target ? resolveBinding(target) : null;
        if (target && binding) {
            setActive(target, binding, true);
        }
    });

    document.addEventListener('pointerout', (event) => {
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
        const target = event.target.closest?.(bindings.map((binding) => binding.selector).join(','));
        const binding = target ? resolveBinding(target) : null;
        if (target && binding) {
            setActive(target, binding, true);
        }
    });

    document.addEventListener('focusout', (event) => {
        const target = event.target.closest?.(bindings.map((binding) => binding.selector).join(','));
        const binding = target ? resolveBinding(target) : null;
        if (target && binding) {
            setActive(target, binding, false);
        }
    });
})();
