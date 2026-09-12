export interface Offer {
  id: string;
  licenseVersionId: string;
  offerRevisionId: string;
  licenseName: string;
  priceMinor: number;
  currency: string;
  deliverableRoles: string[];
  licenseUrl?: string;
}

export interface LicenseDisclosure {
  quoteId?: string;
  disclosureSchema?: number;
  disclosureHash?: string;
  offerId: string;
  offerRevisionId: string;
  licenseVersionId: string;
  name: string;
  version: number;
  type: string;
  features: string[];
  deliverableRoles: string[];
  termsText: string;
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
export interface PageMetadata {
  title: string;
  description: string;
  canonicalUrl: string;
  imageUrl: string;
  imageAlt: string;
  type: 'website' | 'music.song';
  robots: 'index, follow' | 'noindex, nofollow';
}
export interface CatalogFilters { q: string; genre: string; sort: 'featured' | 'tempo' | 'title' }
export interface CatalogPage {
  filters: CatalogFilters;
  previousUrl: string | null;
  nextUrl: string | null;
  restartUrl: string;
  currentUrl: string;
  hasCursor: boolean;
}
export interface StorefrontProps {
  tracks: Track[];
  licenseTiers: LicenseTier[];
  selectedTrackSlug?: string;
  selectedTrack?: Track | null;
  catalogPage?: CatalogPage;
  designPreview?: boolean;
  metadata?: PageMetadata;
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


export function savedCartSelection({ track, offer }: CartLine) {
  return { trackId: track.id, offerId: offer.id, licenseVersionId: offer.licenseVersionId, offerRevisionId: offer.offerRevisionId };
}

export function restoreCartSelections(value: unknown, tracks: Track[]): { lines: CartLine[]; unavailable: number } {
  if (!Array.isArray(value)) return { lines: [], unavailable: 0 };
  const lines: CartLine[] = [];
  let unavailable = 0;
  const seen = new Set<string>();
  const validId = (id: unknown) => (typeof id === 'string' && id.length > 0 && id.length <= 128) || (typeof id === 'number' && Number.isSafeInteger(id) && id > 0);
  for (const item of value.slice(0, 100)) {
    if (!item || typeof item !== 'object' || !['trackId', 'offerId', 'licenseVersionId', 'offerRevisionId'].every(key => validId(item[key]))) { unavailable++; continue; }
    const track = tracks.find(track => String(track.id) === String(item.trackId));
    const offer = track?.offers.find(offer => String(offer.id) === String(item.offerId) && String(offer.licenseVersionId) === String(item.licenseVersionId) && String(offer.offerRevisionId) === String(item.offerRevisionId) && availableOffer(offer));
    if (!track || !offer || seen.has(String(track.id))) { unavailable++; continue; }
    seen.add(String(track.id));
    // Current verified catalog data supplies displayed money and files, never browser storage.
    lines.push({ track, offer });
  }
  return { lines, unavailable };
}

export type SavedSelection = ReturnType<typeof savedCartSelection>;

export function readSavedSelections(numericTrackIds = false): SavedSelection[] {
  try {
    const value: unknown = JSON.parse(sessionStorage.getItem('vaseyaudio-cart-v1') ?? '[]');
    if (!Array.isArray(value)) return [];
    return value.slice(0, 10).filter(item => item && typeof item === 'object' && (!numericTrackIds || (/^[1-9][0-9]*$/.test(String(item.trackId)) && Number.isSafeInteger(Number(item.trackId)))) && ['trackId', 'offerId', 'licenseVersionId', 'offerRevisionId'].every(key =>
      (typeof item[key] === 'string' && item[key].length > 0 && item[key].length <= 128) || (typeof item[key] === 'number' && Number.isSafeInteger(item[key]) && item[key] > 0)
    )).map(item => ({ trackId: String(item.trackId), offerId: String(item.offerId), licenseVersionId: String(item.licenseVersionId), offerRevisionId: String(item.offerRevisionId) }));
  } catch { return []; }
}

export async function resolveSelections(ids: string[], signal: AbortSignal): Promise<{ tracks: Track[]; licenseTiers: LicenseTier[] }> {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  const response = await fetch('/catalog/selections', { method: 'POST', credentials: 'same-origin', signal,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
    body: JSON.stringify({ trackIds: [...new Set(ids)] }),
  });
  if (!response.ok) throw new Error('Selection lookup unavailable');
  const result = await response.json();
  const strings = (value: unknown): value is string[] => Array.isArray(value) && value.every(item => typeof item === 'string');
  const publicTrack = (track: Track) => track && typeof track.id === 'string' && ids.includes(track.id) &&
    [track.slug, track.title, track.artist, track.musicalKey, track.genre, track.shareUrl].every(value => typeof value === 'string') &&
    (track.mood === null || typeof track.mood === 'string') && Number.isFinite(track.bpm) && Number.isFinite(track.durationSeconds) &&
    strings(track.tags) && Array.isArray(track.waveform) && track.waveform.every(peak => typeof peak === 'number' && Number.isFinite(peak)) &&
    [track.previewUrl, track.artworkUrl].every(value => value === null || typeof value === 'string') &&
    Array.isArray(track.offers) && track.offers.every(offer => offer &&
      [offer.id, offer.offerRevisionId, offer.licenseVersionId, offer.licenseName].every(value => typeof value === 'string') &&
      availableOffer(offer) && strings(offer.deliverableRoles));
  const publicTier = (tier: LicenseTier) => tier && [tier.id, tier.name, tier.type].every(value => typeof value === 'string') &&
    (typeof tier.version === 'string' || Number.isSafeInteger(tier.version)) && strings(tier.features) && strings(tier.requiredAssetRoles);
  if (!result || !Array.isArray(result.tracks) || !Array.isArray(result.licenseTiers) || result.tracks.length > 10 ||
    !result.tracks.every(publicTrack) || !result.licenseTiers.every(publicTier) || new Set(result.tracks.map((track: Track) => track.id)).size !== result.tracks.length) {
    throw new Error('Invalid selection response');
  }
  return result;
}
