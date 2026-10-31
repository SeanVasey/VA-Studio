import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Editorial from '../../resources/js/Pages/Editorial';
import { defaultSiteContent, type EditorialDescriptor, type SiteContent } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';

vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));
const metadata: PageMetadata = { title: 'Privacy policy', description: 'Synthetic description', canonicalUrl: 'https://audio.example.test/legal/privacy', imageUrl: 'https://audio.example.test/images/storefront-hero.jpg', imageAlt: 'Studio artwork', type: 'website', robots: 'index, follow' };
const legal: EditorialDescriptor = {
  section: 'legal', kind: 'legal', path: '/legal/privacy', title: 'Synthetic privacy policy', description: 'Synthetic introduction',
  paragraphs: [], entries: [], email: null, contactHref: null, video: null,
  legal: { document: 'privacy', version: '1', effectiveDate: '2026-11-01', sections: [
    { heading: 'Who we are', paragraphs: ['<img src=x onerror=alert(1)>', 'Second line\nwith a break.'] },
    { heading: 'Contact', paragraphs: ['support@vasey.audio'] },
  ] },
};
const chrome: SiteContent = { ...defaultSiteContent, schema_version: 5, legal_links: [
  { title: 'Synthetic privacy policy', path: '/legal/privacy' }, { title: 'Synthetic terms', path: '/legal/terms' },
] };

describe('published legal pages', () => {
  it('renders version, effective date and sections as text with labelled section regions', () => {
    render(<Editorial siteContent={chrome} editorial={legal} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Synthetic privacy policy' })).toBeInTheDocument();
    expect(screen.getByText(/Version 1/)).toBeInTheDocument();
    expect(document.querySelector('time')).toHaveAttribute('dateTime', '2026-11-01');
    const first = screen.getByRole('region', { name: 'Who we are' });
    expect(within(first).getByText('<img src=x onerror=alert(1)>')).toBeInTheDocument();
    expect(document.querySelector('img[src="x"]')).toBeNull();
    expect(screen.getByRole('region', { name: 'Contact' })).toBeInTheDocument();
  });

  it('lists published legal pages in the footer without duplicating navigation links', () => {
    render(<Editorial siteContent={{ ...chrome, navigation: [...chrome.navigation, { label: 'Privacy', href: '/legal/privacy' }] }} editorial={legal} metadata={metadata} />);
    const footer = screen.getByRole('navigation', { name: 'Footer navigation' });
    expect(within(footer).getAllByRole('link').filter(link => link.getAttribute('href') === '/legal/privacy')).toHaveLength(1);
    expect(within(footer).getByRole('link', { name: 'Synthetic terms' })).toHaveAttribute('href', '/legal/terms');
    expect(within(screen.getByRole('navigation', { name: 'Main navigation' })).getByRole('link', { name: 'Privacy' })).toHaveAttribute('aria-current', 'page');
  });

  it('keeps private preview links inside the saved release', () => {
    render(<Editorial siteContent={chrome} editorial={legal} sitePreview sitePreviewBase="/admin/site-releases/7/preview" metadata={metadata} />);
    const footer = screen.getByRole('navigation', { name: 'Footer navigation' });
    expect(within(footer).getByRole('link', { name: 'Synthetic terms' })).toHaveAttribute('href', '/admin/site-releases/7/preview/legal/terms');
  });
});
