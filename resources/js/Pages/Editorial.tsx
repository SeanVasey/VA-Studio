import type { MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '../components/Icon';
import { MetadataHead } from '../components/MetadataHead';
import { PersistentPlayer } from '../components/PersistentPlayer';
import { EditorialVideo } from '../components/EditorialVideo';
import { ContactInquiryForm } from '../components/ContactInquiryForm';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { InstallApp } from '../components/InstallApp';
import { siteContentHref, type EditorialDescriptor, type SiteContent } from '../lib/site-content';
import type { PageMetadata } from '../lib/catalog';

interface EditorialProps {
  siteContent: SiteContent;
  editorial: EditorialDescriptor;
  sitePreview?: boolean;
  sitePreviewBase?: string | null;
  metadata: PageMetadata;
  contactInquiryEnabled?: boolean;
  contactInquiryPrivacyNotice?: string | null;
  contactInquiryNoticeToken?: string | null;
}

export default function Editorial({ siteContent, editorial, sitePreview = false, sitePreviewBase = null, metadata, contactInquiryEnabled = false, contactInquiryPrivacyNotice = null, contactInquiryNoticeToken = null }: EditorialProps) {
  const href = (path: string) => siteContentHref(path, sitePreviewBase);
  function navigate(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    router.visit(event.currentTarget.href, { preserveScroll: false, onSuccess: () => (document.getElementById('editorial-title') ?? document.getElementById('detail-title') ?? document.getElementById('catalog-title'))?.focus({ preventScroll: true }) });
  }
  const chrome = { content: siteContent, homeHref: href('/'), href, onNavigate: navigate };
  return <>
    <MetadataHead metadata={metadata} publicInstallation={!sitePreview} />
    <a className="skip-link" href="#main">Skip to content</a>
    {sitePreview && <div className="preview-banner">PRIVATE CONTENT PREVIEW <span>Visible only to staff. Purchasing and external actions are disabled.</span></div>}
    <SiteHeader {...chrome} currentPath={`/${editorial.section}`} />
    <main id="main" className="editorial-page section-pad">
      <div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / {editorial.section}</p>
        {editorial.kind === 'entry' && <a className="text-link editorial-back" href={href(`/${editorial.section}`)} onClick={navigate}>Back to {editorial.section === 'blog' ? 'blog' : 'videos'} <Icon name="arrow" size={16} /></a>}
        <h1 id="editorial-title" tabIndex={-1}>{editorial.title}</h1><p className="editorial-description">{editorial.description}</p>
      </div>
      {editorial.paragraphs.length > 0 && <div className="editorial-prose">{editorial.paragraphs.map((paragraph, index) => <p key={index}>{paragraph}</p>)}</div>}
      {editorial.kind === 'collection' && <div className="editorial-list">{editorial.entries.map((entry, index) => <article className="editorial-entry" key={entry.slug}>
        <span className="editorial-index" aria-hidden="true">{String(index + 1).padStart(2, '0')}</span><div><h2><a href={href(entry.path)} onClick={navigate}>{entry.title}</a></h2><p>{entry.description}</p><a className="text-link" href={href(entry.path)} onClick={navigate} aria-label={`${editorial.section === 'blog' ? 'Read' : 'View'} ${entry.title}`}>{editorial.section === 'blog' ? 'Read article' : 'View video'} <Icon name="arrow" size={16} /></a></div>
      </article>)}</div>}
      {!sitePreview && editorial.section === 'contact' && contactInquiryEnabled && contactInquiryPrivacyNotice && contactInquiryNoticeToken && <ContactInquiryForm privacyNotice={contactInquiryPrivacyNotice} noticeToken={contactInquiryNoticeToken} />}
      {editorial.email && <div className="editorial-contact"><p className="eyebrow">Email</p><p>{editorial.email}</p>{!sitePreview && editorial.contactHref ? <a className="button" href={editorial.contactHref}>Open email <Icon name="northeast" size={18} /></a> : <span className="fine-print">Email action disabled in private preview.</span>}</div>}
      {editorial.video && <EditorialVideo key={`${editorial.path}:${editorial.video.provider}:${editorial.video.videoId}:${sitePreview}`} video={editorial.video} title={editorial.title} privatePreview={sitePreview} />}
      {editorial.kind === 'entry' && editorial.relatedTracks && editorial.relatedTracks.length > 0 && <section aria-labelledby="related-tracks-title">
        <h2 id="related-tracks-title">Related tracks</h2>
        <p className="fine-print">{sitePreview ? 'Current catalog availability is shown here. Track links are disabled in private preview.' : 'Selected by VASEY.AUDIO. Availability is checked when you open a track.'}</p>
        <ol className="editorial-list">{editorial.relatedTracks.map((track, index) => <li className="editorial-entry" key={`${index}:${track.title}`}>
          <span className="editorial-index" aria-hidden="true">{String(index + 1).padStart(2, '0')}</span>
          <div><h3>{!sitePreview && track.href ? <a href={track.href} onClick={navigate}>{track.title}</a> : track.title}</h3><p>{track.artist}</p></div>
        </li>)}</ol>
      </section>}
    </main>
    <SiteFooter {...chrome}>{!sitePreview && <InstallApp />}</SiteFooter>
    <PersistentPlayer tracks={[]} purchasingDisabled={sitePreview} catalogUrl={href('/#catalog')} onNavigate={navigate} onLicense={track => { if (!sitePreview) router.visit(track.shareUrl); }} />
  </>;
}
