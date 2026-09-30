import { imageSrcSet, type SiteImageSet, type SiteImages } from '../lib/site-content';

const largest = (set: SiteImageSet) => set.jpeg[set.jpeg.length - 1];

type Box = [media: string, width: string, height: string];

/*
 * `sizes` must be the width each image is painted at, or browsers choose files that are too small. Both boxes use
 * `object-fit: cover`, which paints the image at the larger of the box width and the box height × the image's own
 * width / height, usually far wider than the box. Each row is [media query, box width, box height] from
 * resources/css/app.css, so change them together.
 *
 * Studio: 320, 360, 450 or 500 px tall. Up to 900 px it spans the page less its margins (20 px, then 4.5vw); above
 * that it fills the first of two columns, 1.1fr and 1fr, so 11/21 of the width the margins and gap (5vw, then 8vw) leave.
 */
const STUDIO: Box[] = [
  ['(max-width: 600px)', '100vw - 40px', '320px'],
  ['(max-width: 900px)', '91vw', '360px'],
  ['(max-width: 1200px)', '86vw * 11 / 21', '450px'],
  ['', '(92vw - 2 * clamp(20px, 4.5vw, 80px)) * 11 / 21', '500px'],
];

/*
 * Hero: the full width, as tall as its min-height or its copy, whichever is taller. How the copy wraps is unknown
 * here, so it is taken at the built-in copy's tallest: three heading lines (line height 0.84 up to 600 px, then 0.81,
 * at the heading's font size), two description lines and the fixed rest. From 1201 to 1599 px, for example, that
 * rest is 64 + 52 px padding, a 15 px eyebrow, 38 and 44 px margins, two 24 px description lines, a 24 px gap and the
 * 48 px button: 333 px. Longer copy is painted wider still. The mobile image is used up to 700 px.
 */
const HERO_MOBILE: Box[] = [
  ['(max-width: 600px)', '100vw', 'max(695px, 500.2px + 3 * 0.84 * clamp(84px, 21.5vw, 129px))'],
  ['', '100vw', 'max(580px, 325.8px + 3 * 0.81 * 16vw)'],
];
const HERO_DESKTOP: Box[] = [
  ['(max-width: 900px)', '100vw', 'max(580px, 325.8px + 3 * 0.81 * 16vw)'],
  ['(max-width: 1200px)', '100vw', 'max(600px, 329px + 3 * 0.81 * 15vw)'],
  ['(min-width: 1600px)', '100vw', 'max(710px, 355px + 3 * 0.81 * 190px)'],
  ['', '100vw', 'max(625px, 333px + 3 * 0.81 * clamp(100px, 12.8vw, 186px))'],
];

/** Each row's painted width: the larger of the box width and the box height × this image's aspect ratio. */
function coverSizes(set: SiteImageSet, rows: Box[]): string {
  return rows.map(([media, width, height]) => `${media} max(${width}, ${set.width} / ${set.height} * ${height})`.trim()).join(', ');
}

/** The home hero: the release's desktop and mobile images, or the built-in pair. The heading is printed over it. */
export function HeroPicture({ image }: { image: SiteImages['hero'] }) {
  if (!image) {
    return <picture><source media="(max-width: 700px)" srcSet="/images/storefront-hero-mobile.jpg" /><img src="/images/storefront-hero.jpg" alt="Audio production console in the VASEY.AUDIO visual world" width="2400" height="890" fetchPriority="high" /></picture>;
  }
  const { desktop, mobile } = image;
  const [mobileSizes, desktopSizes] = [coverSizes(mobile, HERO_MOBILE), coverSizes(desktop, HERO_DESKTOP)];

  return <picture>
    <source media="(max-width: 700px)" type="image/webp" srcSet={imageSrcSet(mobile.webp)} sizes={mobileSizes} width={mobile.width} height={mobile.height} />
    <source media="(max-width: 700px)" type="image/jpeg" srcSet={imageSrcSet(mobile.jpeg)} sizes={mobileSizes} width={mobile.width} height={mobile.height} />
    <source type="image/webp" srcSet={imageSrcSet(desktop.webp)} sizes={desktopSizes} />
    <img src={largest(desktop).url} srcSet={imageSrcSet(desktop.jpeg)} sizes={desktopSizes} alt={image.alt} width={desktop.width} height={desktop.height} fetchPriority="high" />
  </picture>;
}

/** The studio section image, or the built-in one. It fills half the width on wide screens and the full width below 900 px. */
export function StudioPicture({ image }: { image: SiteImages['studio'] }) {
  if (!image) {
    return <img src="/images/video-studio.jpg" alt="VASEY.AUDIO production studio visual" width="1440" height="630" loading="lazy" />;
  }
  const sizes = coverSizes(image, STUDIO);

  return <picture>
    <source type="image/webp" srcSet={imageSrcSet(image.webp)} sizes={sizes} />
    <img src={largest(image).url} srcSet={imageSrcSet(image.jpeg)} sizes={sizes} alt={image.alt} width={image.width} height={image.height} loading="lazy" />
  </picture>;
}
