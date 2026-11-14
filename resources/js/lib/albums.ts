import type { SiteImageSource } from './site-content';

/** The prepared cover art of a release; alt text is staff-entered and required before a release can be published. */
export interface AlbumCover {
  alt: string; width: number; height: number; jpeg: SiteImageSource[]; webp: SiteImageSource[];
}

export interface AlbumSummary {
  slug: string; path: string; title: string; artist: string; type: 'album' | 'ep' | 'single'; typeLabel: string;
  /** ISO date (YYYY-MM-DD). */
  releaseDate: string; trackCount: number; cover: AlbumCover | null;
}

export interface AlbumDetail extends AlbumSummary {
  description: string | null;
  tracks: { position: number; title: string; durationSeconds: number; duration: string }[];
  links: { platform: string; label: string; url: string }[];
}

/** Long, locale-independent date for a stored ISO date, shown in UTC so the day never shifts. */
export function releaseDateLabel(isoDate: string): string {
  const parsed = new Date(`${isoDate}T00:00:00Z`);
  return Number.isNaN(parsed.getTime()) ? isoDate : parsed.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' });
}
