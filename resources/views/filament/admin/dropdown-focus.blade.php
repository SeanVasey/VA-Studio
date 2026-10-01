{{-- Keyboard focus for Filament dropdown menus (WCAG 2.4.7, 2.4.3). Filament marks a focused menu item only with a background
     barely distinguishable from the panel. When a dialog opened from a menu item is dismissed, Filament refocuses that item,
     which the closing menu hides and the following Livewire update replaces, so focus fell to the page. A submitted dialog
     closes without that event, as it does for every Filament action, so this covers dismissal and Escape only. --}}
<style>
    .fi-dropdown-list-item:focus-visible {
        outline: 2px solid currentColor;
        outline-offset: -2px;
    }
</style>
<script>
    (() => {
        if (window.vaseyDropdownFocus) {
            return;
        }
        window.vaseyDropdownFocus = true;
        // Filament observes the trigger and panel that existed at init. Livewire can replace either
        // while preserving the Alpine component, leaving the new panel unobserved and ARIA stale.
        // Keep one observer in the component's existing lifecycle slot, but bind it to current DOM.
        // Its ordinary destroy() still disconnects it; the discovery observer retains no components.
        const ariaBindings = new WeakMap();
        const bindAria = (dropdown) => {
            if (! dropdown.isConnected || ! window.Alpine?.$data) {
                return;
            }
            const component = window.Alpine.$data(dropdown);
            // $data() falls back to an ancestor's scope until a new nested root initializes.
            // Never disconnect that ancestor's observer on behalf of an uninitialized child.
            if (component.$el !== dropdown || typeof component.syncAria !== 'function' || ! component.panelId) {
                return;
            }
            const previous = ariaBindings.get(dropdown);
            if (previous?.observer === component.observer) {
                return;
            }
            previous?.observer.disconnect();
            component.observer?.disconnect();
            let trigger = null;
            let panel = null;
            const set = (element, attribute, value) => {
                if (element.getAttribute(attribute) !== value) {
                    element.setAttribute(attribute, value);
                }
            };
            const sync = () => {
                const nextTrigger = dropdown.querySelector(':scope > .fi-dropdown-trigger button, :scope > .fi-dropdown-trigger a, :scope > .fi-dropdown-trigger [tabindex]');
                const nextPanel = dropdown.querySelector(':scope > .fi-dropdown-panel');
                if (trigger !== nextTrigger || panel !== nextPanel) {
                    observer.disconnect();
                    trigger = nextTrigger;
                    panel = nextPanel;
                    observer.observe(dropdown, { childList: true, subtree: true });
                    if (panel) observer.observe(panel, { attributes: true, attributeFilter: ['id', 'style'] });
                    if (trigger) observer.observe(trigger, { attributes: true, attributeFilter: ['aria-controls', 'aria-expanded', 'aria-haspopup'] });
                }
                if (! trigger || ! panel) {
                    if (trigger) {
                        trigger.removeAttribute('aria-controls');
                        trigger.removeAttribute('aria-haspopup');
                        set(trigger, 'aria-expanded', 'false');
                    }
                    return;
                }
                set(panel, 'id', component.panelId);
                set(trigger, 'aria-haspopup', 'true');
                set(trigger, 'aria-controls', component.panelId);
                set(trigger, 'aria-expanded', panel.style.display === 'block' ? 'true' : 'false');
                dropdown.querySelector(':scope > .fi-dropdown-trigger')?.removeAttribute('aria-expanded');
            };
            const observer = new MutationObserver(sync);
            component.observer = observer;
            component.syncAria = sync;
            ariaBindings.set(dropdown, { observer });
            // Both nodes can be absent during the first morph boundary. Observe the root even
            // then, so their later insertion can rebind without relying on an attribute repair.
            observer.observe(dropdown, { childList: true, subtree: true });
            sync();
        };
        const discoverAria = (node) => {
            if (! (node instanceof Element)) {
                return;
            }
            if (node.matches('.fi-dropdown[x-data="filamentDropdown"]')) bindAria(node);
            for (const dropdown of node.querySelectorAll('.fi-dropdown[x-data="filamentDropdown"]')) bindAria(dropdown);
        };
        document.addEventListener('alpine:initialized', () => discoverAria(document.documentElement));
        const discovery = new MutationObserver(records => {
            for (const record of records) {
                const dropdown = record.target instanceof Element ? record.target.closest('.fi-dropdown[x-data="filamentDropdown"]') : null;
                if (dropdown) bindAria(dropdown);
                for (const node of record.addedNodes) discoverAria(node);
            }
        });
        // This HEAD observer can see an inserted node before Alpine initializes it. Filament's
        // init assigns the panel id, so that attribute change supplies a bounded second discovery.
        discovery.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['id'] });
        let opener = null;
        let dialog = null;
        let lastInput = 0;
        const triggerOf = (element) => element?.closest('.fi-dropdown')
            ?.querySelector(':scope > .fi-dropdown-trigger button, :scope > .fi-dropdown-trigger a, :scope > .fi-dropdown-trigger [tabindex]') ?? null;
        const visible = (element) => element.checkVisibility ? element.checkVisibility() : element.getClientRects().length > 0;
        // Focus is lost on the page, a hidden element, the menu item Filament refocuses, or the dialog that is closing.
        const lost = (active) => active === null || active === document.body || ! visible(active)
            || active.closest('.fi-dropdown-panel, .fi-modal:not(.fi-modal-open)') !== null;
        // Keeps focus on the menu's button while it drifts, for as long as Livewire's update may keep the button disabled
        // and replace the menu, and stops at the user's next input so a reopened menu keeps its focus.
        const returnFocus = (trigger) => {
            const started = performance.now();
            const step = () => {
                if (! trigger?.isConnected || lastInput > started || performance.now() - started > 5000) {
                    return;
                }
                const active = document.activeElement;
                if (lost(active)) {
                    trigger.focus({ preventScroll: true });
                }
                if (lost(active) || active === trigger) {
                    requestAnimationFrame(step);
                }
            };
            requestAnimationFrame(step);
        };
        // A keyboard activation may not produce a click, and a click may not move focus, so remember the menu either way.
        const remember = (event) => {
            const item = event.target.closest?.('.fi-dropdown-panel .fi-dropdown-list-item');
            if (item) {
                opener = triggerOf(item);
                dialog = null;
            } else if (! event.target.closest?.('.fi-modal')) {
                opener = null;
                dialog = null;
            }
        };
        document.addEventListener('focusin', remember, true);
        document.addEventListener('click', remember, true);
        // A key pressed while focus is lost, such as a second Escape or the Ctrl that silences a screen reader, does not stop
        // the return, and Tab ends it by moving focus. A key on a focused control, or a press or click anywhere, stops it: a
        // screen reader may activate a button with mouse events alone, then move focus into the menu it opened.
        document.addEventListener('keydown', (event) => {
            if (! lost(document.activeElement)) {
                lastInput = event.timeStamp;
            }
        }, true);
        for (const type of ['pointerdown', 'mousedown', 'click']) {
            document.addEventListener(type, (event) => {
                lastInput = event.timeStamp;
            }, true);
        }
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && event.target.closest?.('.fi-dropdown-panel')) {
                returnFocus(triggerOf(event.target));
            }
        }, true);
        // Only the dialog opened from the menu item hands focus back; a nested dialog closing inside it does not.
        document.addEventListener('x-modal-opened', (event) => {
            if (opener !== null && dialog === null) {
                dialog = event.detail?.id ?? null;
            }
        });
        window.addEventListener('modal-closed', (event) => {
            if (dialog === null || event.detail?.id !== dialog) {
                return;
            }
            const trigger = opener;
            opener = null;
            dialog = null;
            returnFocus(trigger);
        });
    })();
</script>
