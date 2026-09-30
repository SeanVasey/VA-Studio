import { imageSrcSet, type SiteImageSet, type SiteImages } from '../lib/site-content';

const largest = (set: SiteImageSet) => set.jpeg[set.jpeg.length - 1];

/** The home hero: the release's desktop and mobile images, or the built-in pair. The heading is printed over it. */
export function HeroPicture({ image }: { image: SiteImages['hero'] }) {
  if (!image) {
    return <picture><source media="(max-width: 700px)" srcSet="/images/storefront-hero-mobile.jpg" /><img src="/images/storefront-hero.jpg" alt="Audio production console in the VASEY.AUDIO visual world" width="2400" height="890" fetchPriority="high" /></picture>;
  }
  const { desktop, mobile } = image;

  return <picture>
    <source media="(max-width: 700px)" type="image/webp" srcSet={imageSrcSet(mobile.webp)} sizes="100vw" width={mobile.width} height={mobile.height} />
    <source media="(max-width: 700px)" type="image/jpeg" srcSet={imageSrcSet(mobile.jpeg)} sizes="100vw" width={mobile.width} height={mobile.height} />
    <source type="image/webp" srcSet={imageSrcSet(desktop.webp)} sizes="100vw" />
    <img src={largest(desktop).url} srcSet={imageSrcSet(desktop.jpeg)} sizes="100vw" alt={image.alt} width={desktop.width} height={desktop.height} fetchPriority="high" />
  </picture>;
}

/** The studio section image, or the built-in one. It fills half the width on wide screens and the full width below 900 px. */
export function StudioPicture({ image }: { image: SiteImages['studio'] }) {
  if (!image) {
    return <img src="/images/video-studio.jpg" alt="VASEY.AUDIO production studio visual" width="1440" height="630" loading="lazy" />;
  }
  const sizes = '(max-width: 900px) 100vw, 50vw';

  return <picture>
    <source type="image/webp" srcSet={imageSrcSet(image.webp)} sizes={sizes} />
    <img src={largest(image).url} srcSet={imageSrcSet(image.jpeg)} sizes={sizes} alt={image.alt} width={image.width} height={image.height} loading="lazy" />
  </picture>;
}
