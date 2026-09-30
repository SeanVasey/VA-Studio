import { Head } from '@inertiajs/react';
import type { PageMetadata } from '../lib/catalog';

/** Matches the server fallback keys so Inertia adopts, replaces and removes tags on navigation. */
export function MetadataHead({ metadata }: { metadata: PageMetadata }) {
  return <Head title={metadata.title}>
    <meta head-key="description" name="description" content={metadata.description} />
    <meta head-key="robots" name="robots" content={metadata.robots} />
    <link head-key="canonical" rel="canonical" href={metadata.canonicalUrl} />
    <meta head-key="og:site_name" property="og:site_name" content="VASEY.AUDIO" />
    <meta head-key="og:type" property="og:type" content={metadata.type} />
    <meta head-key="og:title" property="og:title" content={metadata.title} />
    <meta head-key="og:description" property="og:description" content={metadata.description} />
    <meta head-key="og:url" property="og:url" content={metadata.canonicalUrl} />
    <meta head-key="og:image" property="og:image" content={metadata.imageUrl} />
    <meta head-key="og:image:alt" property="og:image:alt" content={metadata.imageAlt} />
    {metadata.imageWidth != null && <meta head-key="og:image:width" property="og:image:width" content={String(metadata.imageWidth)} />}
    {metadata.imageHeight != null && <meta head-key="og:image:height" property="og:image:height" content={String(metadata.imageHeight)} />}
    {metadata.imageType != null && <meta head-key="og:image:type" property="og:image:type" content={metadata.imageType} />}
    <meta head-key="twitter:card" name="twitter:card" content="summary_large_image" />
    <meta head-key="twitter:title" name="twitter:title" content={metadata.title} />
    <meta head-key="twitter:description" name="twitter:description" content={metadata.description} />
    <meta head-key="twitter:image" name="twitter:image" content={metadata.imageUrl} />
    <meta head-key="twitter:image:alt" name="twitter:image:alt" content={metadata.imageAlt} />
  </Head>;
}
