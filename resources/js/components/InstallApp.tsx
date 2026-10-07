import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { createInstallController, type InstallState } from '../lib/install-app';

const messages: Partial<Record<InstallState, string>> = {
  pending: 'Waiting for your browser. Follow its installation instructions.',
  accepted: 'Installation request accepted. Follow your browser’s instructions to finish.',
  dismissed: 'Installation was dismissed. You can keep using the website or use your browser’s menu later.',
  error: 'The installation prompt could not be opened. Use your browser’s menu or keep using the website.',
};

/** Mounted only on public pages, regardless of whether the browser offers an install prompt. */
export function InstallApp() {
  const [state, setState] = useState<InstallState>('manual');
  const controller = useRef<ReturnType<typeof createInstallController> | null>(null);
  useEffect(() => {
    const current = createInstallController(window, setState);
    controller.current = current;
    return () => { current.dispose(); controller.current = null; };
  }, []);
  return state !== 'installed' ? <details className="install-help">
      <summary>Add VASEY.AUDIO to your device</summary>
      <div className="install-help-content">
        <p>Keep a shortcut to the public store. An internet connection is required; availability depends on your browser and device.</p>
        {(state === 'available' || state === 'pending') && <button className="button button-secondary" disabled={state === 'pending'} onClick={() => { void controller.current?.install(); }}>Install VASEY.AUDIO</button>}
        {messages[state] && <p role="status">{messages[state]}</p>}
        <ul>
          <li><strong>iPhone or iPad:</strong> open the public home page in Safari, choose Share, then Add to Home Screen. Turn on Open as Web App if shown, then choose Add.</li>
          <li><strong>Mac with Safari:</strong> open the public home page and choose File, then Add to Dock, if available.</li>
          <li><strong>Other browsers:</strong> look in the address bar or browser menu for Install app or Add to Home Screen. If neither is available, keep using the website.</li>
        </ul>
        <Link href="/" onSuccess={() => document.getElementById('catalog-title')?.focus({ preventScroll: true })}>Open the public home page</Link>
      </div>
    </details> : null;
}
