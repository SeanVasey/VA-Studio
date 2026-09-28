import { useEffect, useState } from 'react';
import { readSavedSelections, resolveSelections, restoreCartSelections, savedCartSelection, type CartLine, type LicenseTier, type Track } from './catalog';

// Storage contains identities only. A catalog page is never a complete availability list.
export function useCart(tracks: Track[], paginated: boolean, enabled = true) {
  const [cart, setCart] = useState(() => {
    const selections = enabled ? readSavedSelections(paginated) : [];
    const restored = restoreCartSelections(selections, tracks);
    return { selections: paginated ? selections : restored.lines.map(savedCartSelection), lines: restored.lines,
      unavailable: paginated ? 0 : restored.unavailable, pending: paginated && selections.length > 0, error: false, tiers: [] as LicenseTier[] };
  });
  const [attempt, setAttempt] = useState(0);
  const selectionKey = JSON.stringify(cart.selections);
  useEffect(() => {
    if (!enabled) return;
    let active = true;
    const controller = new AbortController();
    const selections = JSON.parse(selectionKey) as typeof cart.selections;
    if (!paginated || selections.length === 0) {
      const checked = restoreCartSelections(selections, tracks);
      setCart(current => ({ ...current, selections: checked.lines.map(savedCartSelection), lines: checked.lines,
        unavailable: current.unavailable + checked.unavailable, pending: false, error: false }));
    } else {
      setCart(current => ({ ...current, pending: true, error: false }));
      void resolveSelections(selections.map(item => item.trackId), controller.signal).then(result => {
        if (!active) return;
        const checked = restoreCartSelections(selections, result.tracks);
        setCart(current => ({ ...current, selections: checked.lines.map(savedCartSelection), lines: checked.lines, tiers: result.licenseTiers,
          unavailable: current.unavailable + checked.unavailable, pending: false, error: false }));
      }).catch(() => {
        if (active) setCart(current => ({ ...current, pending: false, error: true }));
      });
    }
    return () => { active = false; controller.abort(); };
  }, [tracks, paginated, selectionKey, attempt, enabled]);
  useEffect(() => {
    if (!enabled) return;
    try { sessionStorage.setItem('vaseyaudio-cart-v1', selectionKey); } catch { /* In-memory selections remain usable. */ }
  }, [selectionKey, enabled]);
  const setLines = (update: (lines: CartLine[]) => CartLine[]) => setCart(current => {
    // Do not overwrite unresolved saved choices with a new, incomplete cart.
    if (!enabled || current.pending || current.error) return current;
    const lines = update(current.lines);
    return { ...current, lines, selections: lines.map(savedCartSelection), pending: paginated && lines.length > 0 };
  });
  return { ...cart, setLines, retry: () => setAttempt(value => value + 1) };
}
