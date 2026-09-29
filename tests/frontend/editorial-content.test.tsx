import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { describe, expect, it, vi } from 'vitest';
import Editorial from '../../resources/js/Pages/Editorial';
import Storefront from '../../resources/js/Pages/Storefront';
import { defaultSiteContent, type EditorialDescriptor, type SiteContent } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';

// Metadata replacement has a separate real Inertia integration suite.
vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));

const chrome: SiteContent = { ...defaultSiteContent, schema_version: 2, navigation: [
  { label: 'Catalog', href: '/#catalog' }, { label: 'About', href: '/about' }, { label: 'Contact', href: '/contact' },
  { label: 'Blog', href: '/blog' }, { label: 'Videos', href: '/videos' },
] };
const metadata: PageMetadata = { title: 'Synthetic title', description: 'Synthetic description', canonicalUrl: 'https://audio.example.test/about', imageUrl: 'https://audio.example.test/images/storefront-hero.jpg', imageAlt: 'Studio artwork', type: 'website', robots: 'noindex, nofollow' };
const about: EditorialDescriptor = { section: 'about', kind: 'page', path: '/about', title: 'Synthetic about', description: 'Synthetic introduction.', paragraphs: ['A retained paragraph.'], entries: [], email: null, contactHref: null, video: null };
const blog: EditorialDescriptor = { ...about, section: 'blog', kind: 'collection', path: '/blog', title: 'Synthetic blog', paragraphs: [], entries: [{ slug: 'first-article', title: 'First article', description: 'Article summary.', path: '/blog/first-article' }] };
const video: EditorialDescriptor = { ...about, section: 'videos', kind: 'entry', path: '/videos/first-video', paragraphs: [], video: { provider: 'youtube', videoId: 'abcdefghijk', watchUrl: 'https://www.youtube.com/watch?v=abcdefghijk' } };
const previewBase = '/admin/site-releases/42/preview';

describe('editorial content and shared navigation', () => {
  it('renders supplied plain text and fixed identity without injecting markup', () => {
    const text = '<img src=x onerror="window.editorialLeak=true">';
    render(<Editorial siteContent={chrome} editorial={{ ...about, title: text, paragraphs: [text] }} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(text);
    expect(screen.getByText(text, { selector: 'p' })).toBeInTheDocument();
    expect(document.querySelector('[onerror]')).toBeNull();
    expect(screen.getAllByRole('img', { name: 'VASEY.AUDIO' })[0]).toHaveAttribute('src', '/brand/vasey-audio-logo.png');
    expect(screen.queryByRole('button', { name: /Open cart/ })).not.toBeInTheDocument();
  });

  it('maps every preview content destination to the same saved release', () => {
    render(<Editorial siteContent={chrome} editorial={blog} sitePreview sitePreviewBase={previewBase} metadata={metadata} />);
    for (const nav of screen.getAllByRole('navigation')) {
      for (const link of within(nav).getAllByRole('link')) {
        if (link.getAttribute('href') !== '/admin') expect(link.getAttribute('href')).toMatch(new RegExp(`^${previewBase}`));
      }
    }
    expect(screen.getByRole('link', { name: 'Read First article' })).toHaveAttribute('href', `${previewBase}/blog/first-article`);
    for (const logo of screen.getAllByRole('link', { name: 'VASEY.AUDIO home' })) expect(logo).toHaveAttribute('href', previewBase);
    expect(screen.getByRole('link', { name: /Explore the catalog/ })).toHaveAttribute('href', `${previewBase}#catalog`);
  });

  it('retains preview navigation from homepage into editorial pages', () => {
    render(<Storefront tracks={[]} licenseTiers={[]} siteContent={chrome} sitePreview sitePreviewBase={previewBase} />);
    const nav = screen.getByRole('navigation', { name: 'Main navigation' });
    expect(within(nav).getByRole('link', { name: 'About' })).toHaveAttribute('href', `${previewBase}/about`);
    expect(within(nav).getByRole('link', { name: 'Catalog' })).toHaveAttribute('href', `${previewBase}#catalog`);
  });

  it('offers the generated public contact action without sending anything', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    render(<Editorial siteContent={chrome} editorial={{ ...about, section: 'contact', email: 'synthetic@example.test', contactHref: 'mailto:synthetic%40example.test' }} metadata={metadata} />);
    expect(screen.getByRole('link', { name: /Open email/ })).toHaveAttribute('href', 'mailto:synthetic%40example.test');
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('loads no embedded provider and disables external actions in private previews', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    const read = vi.spyOn(Storage.prototype, 'getItem');
    const write = vi.spyOn(Storage.prototype, 'setItem');
    const rendered = render(<Editorial siteContent={chrome} editorial={video} sitePreview sitePreviewBase={previewBase} metadata={metadata} />);
    expect(screen.queryByRole('link', { name: /Watch on/ })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Back to videos/ })).toHaveAttribute('href', `${previewBase}/videos`);
    expect(document.querySelector('iframe, video, script')).toBeNull();
    rendered.rerender(<Editorial siteContent={chrome} editorial={{ ...about, section: 'contact', email: 'synthetic@example.test', contactHref: 'mailto:synthetic%40example.test' }} sitePreview sitePreviewBase={previewBase} metadata={metadata} />);
    expect(screen.queryByRole('link', { name: /Open email/ })).not.toBeInTheDocument();
    expect(fetcher).not.toHaveBeenCalled(); expect(read).not.toHaveBeenCalled(); expect(write).not.toHaveBeenCalled();
  });

  it('uses the canonical provider watch link with no automatic remote requests', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    render(<Editorial siteContent={chrome} editorial={video} metadata={metadata} />);
    const link = screen.getByRole('link', { name: /Watch on YouTube/ });
    expect(link).toHaveAttribute('href', video.video!.watchUrl);
    expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    expect(document.querySelector('iframe, video')).toBeNull();
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('supports mobile menu state and Inertia navigation while preserving modified clicks', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    render(<Editorial siteContent={chrome} editorial={blog} metadata={metadata} />);
    const menu = screen.getByRole('button', { name: 'Menu' });
    expect(menu).toHaveAttribute('aria-expanded', 'false');
    await user.click(menu);
    expect(menu).toHaveAttribute('aria-expanded', 'true');
    const aboutLink = within(screen.getByRole('navigation', { name: 'Main navigation' })).getByRole('link', { name: 'About' });
    fireEvent.click(aboutLink, { ctrlKey: true });
    expect(visit).not.toHaveBeenCalled();
    await user.click(menu);
    await user.click(aboutLink);
    expect(visit).toHaveBeenCalledWith(expect.stringContaining('/about'), expect.objectContaining({ preserveScroll: false }));
    expect(menu).toHaveAttribute('aria-expanded', 'false');
    await user.click(menu);
    fireEvent.keyDown(aboutLink, { key: 'Escape' });
    expect(menu).toHaveFocus(); expect(menu).toHaveAttribute('aria-expanded', 'false');
  });
});
