import { useEffect, useRef, useState } from 'react';

export function usePrivateInquiryRead<T>(read: (signal: AbortSignal) => Promise<{ kind: 'loaded'; value: T } | { kind: 'unavailable' }>) {
  const [value, setValue] = useState<T | null>(null);
  const [state, setState] = useState<'idle' | 'loading' | 'loaded' | 'error'>('idle');
  const generation = useRef(0); const pending = useRef<AbortController | null>(null); const deadline = useRef<number | null>(null);
  const summary = useRef<HTMLDivElement>(null); const trigger = useRef<HTMLButtonElement>(null);
  function cancel() {
    generation.current++; pending.current?.abort(); pending.current = null;
    if (deadline.current !== null) window.clearTimeout(deadline.current);
    deadline.current = null;
  }
  function clear() { cancel(); setValue(null); setState('idle'); }
  useEffect(() => {
    window.addEventListener('pagehide', clear);
    return () => { window.removeEventListener('pagehide', clear); cancel(); };
    // Each owner keys this view to its immutable locator.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  useEffect(() => { if (state === 'loaded' || state === 'error') summary.current?.focus(); }, [state]);
  async function load() {
    if (pending.current) return;
    setValue(null); setState('loading');
    const current = ++generation.current; const controller = new AbortController(); pending.current = controller;
    deadline.current = window.setTimeout(() => {
      if (current !== generation.current) return;
      cancel(); setValue(null); setState('error');
    }, 20_000);
    const result = await read(controller.signal);
    if (current !== generation.current) return;
    cancel(); setValue(result.kind === 'loaded' ? result.value : null); setState(result.kind === 'loaded' ? 'loaded' : 'error');
  }
  return { value, state, summary, trigger, load, hide: () => { clear(); window.requestAnimationFrame(() => trigger.current?.focus()); } };
}

