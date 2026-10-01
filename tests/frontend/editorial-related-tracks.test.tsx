import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { describe, expect, it, vi } from 'vitest';
import Editorial from '../../resources/js/Pages/Editorial';
import { defaultSiteContent, type EditorialDescriptor } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';

vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));
const metadata: PageMetadata = { title: 'Synthetic article', description: 'Synthetic description', canonicalUrl: 'https://audio.example.test/blog/note', imageUrl: 'https://audio.example.test/images/storefront-hero.jpg', imageAlt: 'Studio artwork', type: 'website', robots: 'index, follow' };
const entry: EditorialDescriptor = { section: 'blog', kind: 'entry', path: '/blog/note', title: 'A retained article', description: 'Article introduction', paragraphs: ['A retained paragraph.'], entries: [], email: null, contactHref: null, video: null, relatedTracks: [
  { title: 'Second selected track', artist: 'Second artist', href: '/tracks/second' },
  { title: 'First selected track', artist: 'First artist', href: '/tracks/first' },
] };

describe('manual editorial related tracks', () => {
  it('preserves saved order, renders only summaries and starts no media/provider requests', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    render(<Editorial siteContent={{ ...defaultSiteContent, schema_version: 4 }} editorial={entry} metadata={metadata} />);
    const related = screen.getByRole('region', { name: 'Related tracks' });
    expect(within(related).getAllByRole('listitem').map(row => row.textContent)).toEqual(['01Second selected trackSecond artist', '02First selected trackFirst artist']);
    expect(within(related).getByRole('link', { name: 'Second selected track' })).toHaveAttribute('href', '/tracks/second');
    expect(document.querySelector('audio, video, iframe')).toBeNull();
    expect(fetcher).not.toHaveBeenCalled();
    expect(within(related).queryByRole('button')).not.toBeInTheDocument();
  });

  it('supports keyboard navigation to server destinations and leaves modified clicks intact', async () => {
    const user = userEvent.setup();
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    render(<Editorial siteContent={defaultSiteContent} editorial={entry} metadata={metadata} />);
    const link = screen.getByRole('link', { name: 'Second selected track' });
    fireEvent.click(link, { ctrlKey: true });
    expect(visit).not.toHaveBeenCalled();
    link.focus();
    await user.keyboard('{Enter}');
    expect(visit).toHaveBeenCalledWith(expect.stringContaining('/tracks/second'), expect.objectContaining({ preserveScroll: false }));
    const focusTarget = document.createElement('h1');
    focusTarget.id = 'detail-title'; focusTarget.tabIndex = -1;
    document.getElementById('editorial-title')?.remove();
    document.body.appendChild(focusTarget);
    const options = visit.mock.calls[0][1] as { onSuccess: () => void };
    options.onSuccess();
    expect(focusTarget).toHaveFocus();
    focusTarget.remove();
  });

  it('renders current eligible private labels as plain text even if a malformed prop supplies destinations', () => {
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    render(<Editorial siteContent={defaultSiteContent} editorial={entry} sitePreview sitePreviewBase="/admin/site-releases/42/preview" metadata={metadata} />);
    const related = screen.getByRole('region', { name: 'Related tracks' });
    expect(within(related).getByRole('heading', { name: 'Second selected track' })).toBeInTheDocument();
    expect(within(related).queryByRole('link')).not.toBeInTheDocument();
    expect(within(related).getByText(/Track links are disabled in private preview/)).toBeInTheDocument();
    expect(document.querySelector('a[href^="/tracks/"]')).toBeNull();
    expect(visit).not.toHaveBeenCalled();
  });

  it('retains a maximum-length unbroken current title as plain heading text', () => {
    const title = 'X'.repeat(255);
    render(<Editorial siteContent={defaultSiteContent} editorial={{ ...entry, relatedTracks: [{ title, artist: 'Test artist', href: '/tracks/reserved' }] }} metadata={metadata} />);
    expect(screen.getByRole('heading', { level: 3, name: title })).toHaveTextContent(title);
    expect(screen.getByRole('link', { name: title })).toHaveAttribute('href', '/tracks/reserved');
  });

  it('omits the section for empty eligibility and collection pages', () => {
    const view = render(<Editorial siteContent={defaultSiteContent} editorial={{ ...entry, relatedTracks: [] }} metadata={metadata} />);
    expect(screen.queryByRole('region', { name: 'Related tracks' })).not.toBeInTheDocument();
    view.rerender(<Editorial siteContent={defaultSiteContent} editorial={{ ...entry, kind: 'collection' }} metadata={metadata} />);
    expect(screen.queryByRole('region', { name: 'Related tracks' })).not.toBeInTheDocument();
  });
});
