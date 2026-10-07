export type InstallState = 'manual' | 'available' | 'pending' | 'accepted' | 'dismissed' | 'error' | 'installed';

interface InstallChoice { outcome?: string }
interface InstallPrompt extends Event {
  prompt: () => Promise<unknown>;
  userChoice?: Promise<InstallChoice>;
}

/** One public-page lifetime only. No saved event or installation history survives navigation. */
export function createInstallController(target: Window, changed: (state: InstallState) => void) {
  let active = true;
  let installed = false;
  let state: InstallState = 'manual';
  let deferred: InstallPrompt | null = null;
  const consumed = new WeakSet<Event>();
  let generation = 0;
  const display = typeof target.matchMedia === 'function' ? target.matchMedia('(display-mode: standalone)') : null;
  const standalone = () => display?.matches === true || (target.navigator as Navigator & { standalone?: boolean }).standalone === true;
  const modernDisplay = display && typeof display.addEventListener === 'function' && typeof display.removeEventListener === 'function';
  const legacyDisplay = display && !modernDisplay && typeof display.addListener === 'function' && typeof display.removeListener === 'function';
  function update(next: InstallState) { state = next; changed(next); }
  function markInstalled() {
    if (!active) return;
    installed = true; deferred = null; generation++;
    update('installed');
  }
  function displayChanged() { if (standalone()) markInstalled(); }
  function capture(event: Event) {
    if (active && standalone()) markInstalled();
    if (!active || installed || state === 'pending' || consumed.has(event) || typeof (event as Partial<InstallPrompt>).prompt !== 'function') return;
    event.preventDefault();
    deferred = event as InstallPrompt;
    update('available');
  }
  target.addEventListener('beforeinstallprompt', capture);
  target.addEventListener('appinstalled', markInstalled);
  if (modernDisplay) display.addEventListener('change', displayChanged);
  else if (legacyDisplay) display.addListener(displayChanged);
  if (standalone()) markInstalled(); else update('manual');

  return {
    async install() {
      if (!active || installed || !deferred) return;
      if (standalone()) { markInstalled(); return; }
      const event = deferred;
      // Consume synchronously: two clicks cannot reuse the browser's one-use event.
      deferred = null;
      consumed.add(event);
      const request = ++generation;
      update('pending');
      try {
        const result = await event.prompt();
        const choice = event.userChoice ? await event.userChoice : result as InstallChoice | undefined;
        if (!active || installed || generation !== request) return;
        if (standalone()) { markInstalled(); return; }
        // An accepted request is not installation proof. Only observed browser signals set installed.
        update(choice?.outcome === 'accepted' ? 'accepted' : choice?.outcome === 'dismissed' ? 'dismissed' : 'manual');
      } catch {
        if (active && !installed && generation === request) {
          if (standalone()) markInstalled(); else update('error');
        }
      }
    },
    dispose() {
      active = false; deferred = null; generation++;
      target.removeEventListener('beforeinstallprompt', capture);
      target.removeEventListener('appinstalled', markInstalled);
      if (modernDisplay) display.removeEventListener('change', displayChanged);
      else if (legacyDisplay) display.removeListener(displayChanged);
    },
  };
}
