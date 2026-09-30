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
        // and replace the menu, and stops at the user's next key or pointer press so a reopened menu keeps its focus.
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
        for (const type of ['keydown', 'pointerdown']) {
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
