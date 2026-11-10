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
}

export interface SoundKitDetail extends SoundKitSummary {
  summary: string | null;
}
