import { imageSrcSet } from '../lib/site-content';
import type { AlbumCover as Cover } from '../lib/albums';

/** Square cover art with its staff-entered description. Without art nothing is drawn: no placeholder cover is invented. */
export function AlbumCover({ cover, sizes, priority = false }: { cover: Cover | null; sizes: string; priority?: boolean }) {
  if (cover === null) return null;
  const fallback = cover.jpeg[cover.jpeg.length - 1];
  return <picture>
    {cover.webp.length > 0 && <source type="image/webp" srcSet={imageSrcSet(cover.webp)} sizes={sizes} />}
    <img className="album-cover" src={fallback.url} srcSet={imageSrcSet(cover.jpeg)} sizes={sizes} width={cover.width} height={cover.height}
      alt={cover.alt} loading={priority ? 'eager' : 'lazy'} decoding="async" />
  </picture>;
}
