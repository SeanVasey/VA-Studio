export interface SiteContent {
  schema_version: 1 | 2 | 3;
  hero: { eyebrow: string; title: string; line_two: string; description: string };
  studio: { eyebrow: string; title: string; line_two: string; lead: string; paragraphs: string[] };
  footer: { description: string };
  navigation: { label: string; href: '/' | '/#catalog' | '/#licenses' | '/#studio' | '/about' | '/contact' | '/blog' | '/videos' }[];
  seo: { title: string; description: string };
}

/** One prepared size of a site image (D-25). */
export interface SiteImageSource { url: string; width: number; height: number }
/** Every prepared size of one image, smallest first; width and height are the largest JPEG's. */
export interface SiteImageSet { width: number; height: number; jpeg: SiteImageSource[]; webp: SiteImageSource[] }
/** Images a published release sets; null keeps the built-in image for that place. */
export interface SiteImages {
  hero: { alt: string; desktop: SiteImageSet; mobile: SiteImageSet } | null;
  studio: (SiteImageSet & { alt: string }) | null;
}

export const builtInSiteImages: SiteImages = { hero: null, studio: null };

export function imageSrcSet(sources: SiteImageSource[]): string {
  return sources.map(source => `${source.url} ${source.width}w`).join(', ');
}

// Keep the isolated design preview aligned with SiteContentSchema::defaults().
// Public and staff pages receive their verified snapshot from the server.
export const defaultSiteContent: SiteContent = {
  schema_version: 1,
  hero: {
    eyebrow: 'INDEPENDENT SOUND. DISTINCT IDENTITY.', title: 'SOUND', line_two: 'WITH INTENT.',
    description: 'Beats with character. Sound with depth.\nOriginal music and production by Sean Vasey.',
  },
  studio: {
    eyebrow: '03 / BEHIND THE SOUND', title: 'CRAFT FIRST.', line_two: 'ALWAYS.', lead: 'From the first note to the last detail.',
    paragraphs: [
      'Sean Vasey brings over two decades of composition, music production, and sound design to a practice shaped by hip-hop, classical music, and the space between them.',
      'Original beats. Bespoke composition. Detailed sonic worlds. Built with intention, for artists with something to say.',
    ],
  },
  footer: { description: 'Independent sound.\nA studio/VASEY venture.' },
  navigation: [{ label: 'The catalog', href: '/#catalog' }, { label: 'Licensing', href: '/#licenses' }, { label: 'The studio', href: '/#studio' }],
  seo: {
    title: 'VASEY.AUDIO — Sound with intent',
    description: 'Original music, beats and sound design by Sean Vasey. Explore the VASEY.AUDIO catalog and listen to published previews.',
  },
};

export interface EditorialEntrySummary {
  slug: string;
  title: string;
  description: string;
  path: string;
}

export interface EditorialDescriptor {
  section: 'about' | 'contact' | 'blog' | 'videos';
  kind: 'page' | 'collection' | 'entry';
  path: string;
  title: string;
  description: string;
  paragraphs: string[];
  entries: EditorialEntrySummary[];
  email: string | null;
  contactHref: string | null;
  video: { provider: 'youtube' | 'vimeo'; videoId: string; watchUrl: string } | null;
}

/** A private preview never sends an internal content link outside its saved release. */
export function siteContentHref(href: string, previewBase: string | null = null): string {
  if (!previewBase) return href;
  return previewBase + (href === '/' ? '' : href.startsWith('/#') ? href.slice(1) : href);
}
