import type { AlbumCover } from './albums';

/** A listed sound kit. Counts come from the verified manifest; no file names, hashes, storage paths or download target are ever sent. */
export interface SoundKitSummary {
  slug: string;
  path: string;
  title: string;
  memberCount: number;
  totalDurationSeconds: number;
  /** Staff-entered price in integer minor units, with its explicit currency. */
  priceMinor: number;
  currency: string;
  /** False until a kit checkout exists; the page then shows a notice instead of a purchase control. */
  purchasable: boolean;
  /** The staff-approved cover art, or null (nothing is drawn). It is a public asset of its own, never part of the kit archive. */
  cover: AlbumCover | null;
}

/** The staff-approved demo: a tagged preview of the kit, served by an opaque address. Never the kit archive or its samples. */
export interface SoundKitDemo {
  url: string;
  durationSeconds: number;
  waveform: number[];
}

export interface SoundKitDetail extends SoundKitSummary {
  summary: string | null;
  demo: SoundKitDemo | null;
}
