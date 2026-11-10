import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import Editorial from '../../resources/js/Pages/Editorial';
import CustomerLibrary from '../../resources/js/Pages/CustomerLibrary';
import { fixtureTiers, fixtureTracks } from '../../resources/js/test/fixtures';
import { defaultSiteContent, type EditorialDescriptor, type SiteContent } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';
vi.mock('@inertiajs/react', async importOriginal => ({ ...await importOriginal<typeof import('@inertiajs/react')>(), Head: () => null }));
vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));

// BL-50: path prefixes of the families built default-off behind their own server policy (FamilyEntryPoints::FAMILIES).
// The page renders what the server projects; it decides nothing about which family is enabled.
const familyPrefixes = ['/services', '/memberships', '/membership', '/production-memberships', '/account/membership-credits'];
const intoFamily = (href: string) => {
  const path = new URL(href, 'https://audio.example.test').pathname.toLowerCase();
  return familyPrefixes.some(prefix => path === prefix || path.startsWith(prefix + '/'));
};
const hrefs = () => [...document.querySelectorAll('a[href]')].map(anchor => anchor.getAttribute('href') ?? '');
const serviceLink = [{ label: 'Service projects', href: '/services/projects' }];
const chrome: SiteContent = { ...defaultSiteContent, schema_version: 2, navigation: [
  { label: 'Music', href: '/#catalog' }, { label: 'About', href: '/about' }, { label: 'Contact', href: '/contact' }, { label: 'Journal', href: '/blog' },
] };
const metadata: PageMetadata = { title: 'Synthetic', description: 'Synthetic.', canonicalUrl: 'https://audio.example.test/about', imageUrl: 'https://audio.example.test/images/storefront-hero.jpg', imageAlt: 'Artwork', type: 'website', robots: 'noindex, nofollow' };
const about: EditorialDescriptor = { section: 'about', kind: 'page', path: '/about', title: 'About', description: 'About.', paragraphs: ['Body.'], entries: [], email: null, contactHref: null, video: null };

describe('storefront entry points of default-off families', () => {
  it('walks the published home page header, sections and footer and finds no family link while every policy is off', () => {
    render(<Storefront tracks={fixtureTracks} licenseTiers={fixtureTiers} siteContent={chrome} customerAccountEnabled testCheckoutEnabled testOrderPreparationEnabled />);
    expect(hrefs().length).toBeGreaterThan(10);
    expect(hrefs().filter(intoFamily)).toEqual([]);
  });

  it('walks every published editorial page and finds no family link while every policy is off', () => {
    for (const section of ['about', 'contact', 'blog'] as const) {
      const view = render(<Editorial siteContent={chrome} editorial={{ ...about, section, path: `/${section}` }} metadata={metadata} />);
      expect(hrefs().filter(intoFamily)).toEqual([]);
      view.unmount();
    }
  });

  it('shows the server-projected service link in the header and footer once its policy is on', () => {
    render(<Storefront tracks={[]} licenseTiers={[]} siteContent={chrome} familyLinks={serviceLink} />);
    for (const name of ['Main navigation', 'Footer navigation']) {
      expect(within(screen.getByRole('navigation', { name })).getByRole('link', { name: 'Service projects' })).toHaveAttribute('href', '/services/projects');
    }
    const view = render(<Editorial siteContent={chrome} editorial={about} metadata={metadata} familyLinks={serviceLink} />);
    expect(within(view.container).getAllByRole('link', { name: 'Service projects' })).toHaveLength(2);
  });

  it('keeps family links out of staff previews even when supplied', () => {
    vi.stubGlobal('fetch', vi.fn());
    render(<Storefront tracks={[]} licenseTiers={[]} siteContent={chrome} sitePreview familyLinks={serviceLink} />);
    expect(hrefs().filter(intoFamily)).toEqual([]);
    vi.unstubAllGlobals();
  });

  it('offers the customer library service and membership entry points only from their server flags', () => {
    const view = render(<CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Synthetic Customer' }} />);
    expect(hrefs().filter(intoFamily)).toEqual([]);
    view.unmount();
    render(<CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Synthetic Customer' }} serviceProjectsEnabled />);
    expect(screen.getByRole('link', { name: 'Your service projects' })).toHaveAttribute('href', '/services/projects');
  });
});
