/** A listed track inside a collection. There is no offer, price or license here: each track is licensed on its own page. */
export interface CollectionTrack {
  id: string; slug: string;
  /** Path of the track's own page, where its license options are shown. */
  path: string;
  title: string; artist: string; bpm: number; musicalKey: string; genre: string; durationSeconds: number; artworkUrl: string | null;
}

export interface CollectionSummary {
  slug: string; path: string; title: string; trackCount: number;
  /** Titles of the first few listed tracks. */
  preview: string[];
}

export interface CollectionDetail {
  slug: string; path: string; title: string; trackCount: number; description: string | null; tracks: CollectionTrack[];
}
