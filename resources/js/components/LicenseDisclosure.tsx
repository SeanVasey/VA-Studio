import { useEffect, useId, useState } from 'react';
import { safeMediaUrl, type LicenseDisclosure as Disclosure, type Offer } from '../lib/catalog';

export function LicenseDisclosure({ offer, defaultOpen = false }: { offer: Offer; defaultOpen?: boolean }) {
  const region = useId();
  const [open, setOpen] = useState(defaultOpen);
  const [attempt, setAttempt] = useState(0);
  const [result, setResult] = useState<{ key: string; data?: Disclosure; error?: string } | null>(null);
  const key = `${offer.id}:${offer.offerRevisionId}:${offer.licenseVersionId}:${offer.licenseUrl ?? ''}`;
  const current = result?.key === key ? result : null;
  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    let active = true;
    setResult(null);
    async function load() {
      try {
        const url = safeMediaUrl(offer.licenseUrl);
        if (!url) throw new Error('Unavailable license address');
        const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (!response.ok) throw new Error('Unavailable license');
        const data: Disclosure = await response.json();
        if (!data || data.offerId !== offer.id || data.offerRevisionId !== offer.offerRevisionId || data.licenseVersionId !== offer.licenseVersionId ||
          typeof data.name !== 'string' || !Number.isSafeInteger(data.version) || typeof data.type !== 'string' || typeof data.termsText !== 'string' ||
          !Array.isArray(data.features) || !data.features.every(value => typeof value === 'string') ||
          !Array.isArray(data.deliverableRoles) || !data.deliverableRoles.every(value => typeof value === 'string')) throw new Error('Mismatched license');
        if (active) setResult({ key, data });
      } catch {
        if (active) setResult({ key, error: 'These terms could not be loaded. The offer may have changed, or the connection was interrupted. Retry or reopen the track for its current licenses.' });
      }
    }
    void load();
    return () => { active = false; controller.abort(); };
  }, [open, attempt, key, offer.id, offer.offerRevisionId, offer.licenseVersionId, offer.licenseUrl]);

  return <section className="license-disclosure" aria-label="Full license terms">
    <button type="button" className="text-link" aria-expanded={open} aria-controls={region} onClick={() => setOpen(!open)}>{open ? 'Hide full terms' : 'Read full terms'}</button>
    {open && <div id={region} aria-busy={!current}>
      {!current && <p role="status">Loading the selected license…</p>}
      {current?.error && <div role="alert"><p>{current.error}</p><button type="button" className="button button-outline" onClick={() => setAttempt(value => value + 1)}>Retry terms</button></div>}
      {current?.data && <><h3>{current.data.name} · version {current.data.version}</h3><p className="fine-print">Published terms for this selection. Saving a selection does not establish a purchase or a rights grant.</p><div className="license-source" role="region" aria-label="Published license text" tabIndex={0}>{current.data.termsText}</div></>}
    </div>}
  </section>;
}
