{{-- Keyboard focus for Filament dropdown menus (WCAG 2.4.7, 2.4.3). Filament marks a focused menu item only with a background
     barely distinguishable from the panel. After a dialog it refocuses the menu item that opened it, which the closing menu
     hides and the following Livewire update replaces, so focus fell to the page. --}}
<style>
    .fi-dropdown-list-item:not(.fi-disabled):not([disabled]):focus-visible {
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
        const triggerOf = (element) => element?.closest('.fi-dropdown')
            ?.querySelector(':scope > .fi-dropdown-trigger button, :scope > .fi-dropdown-trigger a, :scope > .fi-dropdown-trigger [tabindex]') ?? null;
        const visible = (element) => element.checkVisibility ? element.checkVisibility() : element.getClientRects().length > 0;
        // Keeps focus on the menu's button while it drifts to the page, a hidden element, a menu item or the dialog that is
        // closing, for as long as a slow Livewire update may take to replace the menu; stops once the user moves focus elsewhere.
        const returnFocus = (trigger, frames) => requestAnimationFrame(() => {
            if (! trigger?.isConnected) {
                return;
            }
            const active = document.activeElement;
            const lost = active === null || active === document.body || ! visible(active)
                || active.closest('.fi-dropdown-panel, .fi-modal:not(.fi-modal-open)') !== null;
            if (lost) {
                trigger.focus({ preventScroll: true });
            }
            if (frames > 0 && (lost || active === trigger)) {
                returnFocus(trigger, frames - 1);
            }
        });
        // A keyboard activation may not produce a click, and a click may not move focus, so remember the menu either way.
        const remember = (event) => {
            const item = event.target.closest?.('.fi-dropdown-panel .fi-dropdown-list-item');
            if (item) {
                opener = triggerOf(item);
            } else if (event.type === 'focusin' && ! event.target.closest?.('.fi-modal')) {
                opener = null;
            }
        };
        document.addEventListener('focusin', remember, true);
        document.addEventListener('click', remember, true);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && event.target.closest?.('.fi-dropdown-panel')) {
                returnFocus(triggerOf(event.target), 30);
            }
        }, true);
        window.addEventListener('modal-closed', () => {
            const trigger = opener;
            opener = null;
            returnFocus(trigger, 90);
        });
    })();
</script>
