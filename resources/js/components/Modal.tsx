import { useEffect, useId, useRef, type KeyboardEvent, type ReactNode } from 'react';
import { Icon } from './Icon';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

/** Tab order inside the dialog, skipping anything the browser cannot focus right now. */
function tabStops(root: HTMLElement) {
  return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(element => !element.closest('[hidden], [inert]') && (typeof element.checkVisibility !== 'function' || element.checkVisibility()));
}

/**
 * A native modal dialog makes the page behind it inert, but Tab and Shift+Tab at its ends leave the page for the
 * browser UI instead of wrapping. Wrap them so keyboard focus stays in the dialog until it is closed (Escape and the
 * Close button still close it, so this is not a keyboard trap).
 */
function wrapTab(event: KeyboardEvent<HTMLDialogElement>) {
  if (event.key !== 'Tab' || event.altKey || event.ctrlKey || event.metaKey) return;
  const stops = tabStops(event.currentTarget);
  if (!stops.length) return;
  const first = stops[0];
  const last = stops[stops.length - 1];
  const active = document.activeElement;
  if (event.shiftKey && (active === first || active === event.currentTarget)) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && active === last) {
    event.preventDefault();
    first.focus();
  }
}

export function Modal({ title, eyebrow, onClose, children }: { title: string; eyebrow?: string; onClose: () => void; children: ReactNode }) {
  const dialog = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  useEffect(() => {
    const node = dialog.current;
    const previous = document.activeElement;
    node?.showModal();
    const originalOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      node?.close();
      document.body.style.overflow = originalOverflow;
      if (previous instanceof HTMLElement) previous.focus();
    };
  }, []);
  return <dialog ref={dialog} aria-labelledby={titleId} className="modal" onCancel={onClose} onKeyDown={wrapTab} onClick={event => { if (event.target === event.currentTarget) onClose(); }}>
    <div className="modal-inner">
      <div className="modal-header"><div>{eyebrow && <p className="eyebrow">{eyebrow}</p>}<h2 id={titleId}>{title}</h2></div><button type="button" className="icon-button" aria-label="Close dialog" onClick={onClose}><Icon name="close" /></button></div>
      {children}
    </div>
  </dialog>;
}
