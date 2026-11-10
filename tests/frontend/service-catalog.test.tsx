import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Editorial from '../../resources/js/Pages/Editorial';
import ServiceCatalog, { type PublicService } from '../../resources/js/Pages/ServiceCatalog';
import { defaultSiteContent, type EditorialDescriptor, type SiteContent } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';

vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));

const chrome: SiteContent = { ...defaultSiteContent, schema_version: 2 };
const metadata: PageMetadata = { title: 'Synthetic', description: 'Synthetic', canonicalUrl: 'https://audio.example.test/services', imageUrl: 'https://audio.example.test/i.jpg', imageAlt: 'Studio', type: 'website', robots: 'noindex, nofollow' };
const priced: PublicService = { slug: 'mix-review', title: 'Synthetic mix review', description: 'Plain <b>text</b>.', sections: [{ label: 'Scope', text: 'Two mixes.' }], price: 'USD 250.00', path: '/services/mix-review', requestHref: '/contact?service=mix-review' };
const quoted: PublicService = { ...priced, slug: 'other', title: 'Synthetic other', description: '', sections: [], price: 'Quoted on request', path: '/services/other', requestHref: '/contact?service=other' };
const intro = 'Synthetic intro.';

describe('public service pages', () => {
  it('lists each service with the server-supplied price text and a labelled request link', () => {
    render(<ServiceCatalog siteContent={chrome} services={[priced, quoted]} service={null} intro={intro} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Services' })).toBeInTheDocument();
    const list = screen.getByRole('list', { name: 'Services' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(2);
    expect(within(list).getByText('USD 250.00')).toBeInTheDocument();
    expect(within(list).getByText('Quoted on request')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Request Synthetic mix review' })).toHaveAttribute('href', '/contact?service=mix-review');
    expect(screen.getByRole('link', { name: 'View Synthetic other' })).toHaveAttribute('href', '/services/other');
    expect(screen.getByText('Plain <b>text</b>.')).toBeInTheDocument();
    expect(document.querySelector('main b')).toBeNull();
  });

  it('says plainly when nothing is listed, without a request link', () => {
    render(<ServiceCatalog siteContent={chrome} services={[]} service={null} intro={intro} metadata={metadata} />);
    expect(screen.getByText(/No services are listed right now/)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Request/ })).not.toBeInTheDocument();
  });

  it('shows one service with indicative terms, no payment wording and a single request action', () => {
    render(<ServiceCatalog siteContent={chrome} services={[]} service={priced} intro={intro} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Synthetic mix review' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'What to expect' })).toBeInTheDocument();
    expect(screen.getByText('Two mixes.')).toBeInTheDocument();
    expect(screen.getByText(/books nothing and takes no payment/)).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: 'Request this service' })).toHaveLength(1);
    expect(screen.queryByRole('button', { name: /pay|buy|checkout|cart/i })).not.toBeInTheDocument();
  });
});

describe('contact form service prefill', () => {
  const about: EditorialDescriptor = { section: 'contact', kind: 'page', path: '/contact', title: 'Contact', description: 'Reach out.', paragraphs: [], entries: [], email: null, contactHref: null, video: null };
  const token = 'a'.repeat(64);

  it('starts the subject and message from the server-validated service and names it', () => {
    render(<Editorial siteContent={chrome} editorial={about} metadata={metadata} contactInquiryEnabled contactInquiryPrivacyNotice="Notice." contactInquiryNoticeToken={token}
      contactServiceRequest={{ title: 'Synthetic mix review', subject: 'Service request', message: 'I would like to request this service: Synthetic mix review\n\n' }} />);
    expect(screen.getByLabelText(/^Subject/)).toHaveValue('Service request');
    expect((screen.getByLabelText(/^Message/) as HTMLTextAreaElement).value).toContain('Synthetic mix review');
    expect(screen.getByText(/books nothing and takes no payment/)).toBeInTheDocument();
  });

  it('leaves the form empty without a service', () => {
    render(<Editorial siteContent={chrome} editorial={about} metadata={metadata} contactInquiryEnabled contactInquiryPrivacyNotice="Notice." contactInquiryNoticeToken={token} />);
    expect(screen.getByLabelText(/^Subject/)).toHaveValue('');
    expect(screen.getByLabelText(/^Message/)).toHaveValue('');
    expect(screen.queryByText(/books nothing/)).not.toBeInTheDocument();
  });
});
