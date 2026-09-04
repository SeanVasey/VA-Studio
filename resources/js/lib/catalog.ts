export interface Offer {
  id: string;
  licenseVersionId: string;
  licenseName: string;
  priceMinor: number;
  currency: string;
  deliverableRoles: string[];
}

export interface Track {
  id: string;
  slug: string;
  title: string;
  artist: string;
  bpm: number;
  musicalKey: string;
  genre: string;
  mood: string;
  durationSeconds: number;
  artworkUrl: string | null;
  previewUrl: string | null;
  waveform: number[];
  tags: string[];
  offers: Offer[];
  shareUrl: string;
}

export interface LicenseTier {
  id: string;
  name: string;
  version: string | number;
  type: string;
  features: string[];
  requiredAssetRoles: string[];
}

export interface CartLine { track: Track; offer: Offer }
export interface StorefrontProps {
  tracks: Track[];
  licenseTiers: LicenseTier[];
  selectedTrackSlug?: string;
  designPreview?: boolean;
}

export const fileRoleLabels: Record<string, string> = {
  tagged_preview: 'Tagged preview', preview_tagged: 'Tagged preview', download_mp3: 'Untagged MP3', mp3: 'MP3', untagged_mp3: 'Untagged MP3', wav: 'WAV',
  master_wav: 'WAV master', stems: 'Track stems', stems_zip: 'Track stems', artwork: 'Cover artwork',
};

export function formatMoney(minor: number, currency = 'USD'): string {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency, maximumFractionDigits: minor % 100 ? 2 : 0 }).format(minor / 100);
}

export function formatTime(seconds: number): string {
  const safe = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0;
  return `${Math.floor(safe / 60)}:${String(safe % 60).padStart(2, '0')}`;
}

export function filterTracks(tracks: Track[], query: string, genre: string, sort: string): Track[] {
  const terms = query.toLocaleLowerCase().trim().split(/\s+/).filter(Boolean);
  const result = tracks.filter(track => {
    const haystack = [track.title, track.artist, track.genre, track.mood, track.musicalKey, ...track.tags].join(' ').toLocaleLowerCase();
    return (genre === 'All sounds' || genre === track.genre) && terms.every(term => haystack.includes(term));
  });
  if (sort === 'tempo') result.sort((a, b) => a.bpm - b.bpm || a.title.localeCompare(b.title));
  if (sort === 'title') result.sort((a, b) => a.title.localeCompare(b.title));
  return result;
}

export function availableOffer(offer: Offer): boolean {
  return Number.isSafeInteger(offer.priceMinor) && offer.priceMinor >= 0 && /^[A-Z]{3}$/.test(offer.currency);
}

export function safeMediaUrl(value: string | null | undefined): string | undefined {
  if (!value) return undefined;
  if (value.includes('\\')) return undefined;
  // Published assets are served through the application's authorization-aware media routes.
  try {
    const url = new URL(value, window.location.origin);
    return url.origin === window.location.origin && /^https?:$/.test(url.protocol) ? url.href : undefined;
  } catch { return undefined; }
}

export function cartSubtotals(lines: CartLine[]): Array<{ currency: string; amount: number }> {
  const totals = new Map<string, number>();
  for (const { offer } of lines) totals.set(offer.currency, (totals.get(offer.currency) ?? 0) + offer.priceMinor);
  return Array.from(totals, ([currency, amount]) => ({ currency, amount }));
}
