import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import Storefront from '../../resources/js/Pages/Storefront';
import { fixtureTracks, fixtureTiers } from '../../resources/js/test/fixtures';
import type { CatalogPage } from '../../resources/js/lib/catalog';

const catalogPage: CatalogPage = {
  filters: { q: 'quiet', genre: 'Cinematic', sort: 'title' }, previousUrl: null, nextUrl: null,
  restartUrl: '/?q=quiet&genre=Cinematic&sort=title',
  currentUrl: '/?q=quiet&genre=Cinematic&sort=title&cursor=opaque', hasCursor: true,
};

it.each(['Main navigation', 'Footer navigation'])('%s retains the player and catalog query when licensing returns from a track to the homepage', navigation => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
  const track = fixtureTracks[0];
  render(<Storefront tracks={[]} licenseTiers={fixtureTiers} selectedTrack={track} selectedTrackSlug={track.slug} catalogPage={catalogPage} />);
  const links = within(screen.getByRole('navigation', { name: navigation }));
  const licensing = links.getByRole('link', { name: 'Licensing' });
  expect(licensing).toHaveAttribute('href', catalogPage.currentUrl + '#licenses');
  // Cancel document navigation; the shared audio owner survives the Inertia page change.
  expect(fireEvent.click(licensing)).toBe(false);
  expect(visit).toHaveBeenCalledExactlyOnceWith(window.location.origin + catalogPage.currentUrl + '#licenses', expect.objectContaining({ preserveState: true }));
  visit.mockClear();
  const catalog = links.getByRole('link', { name: 'The catalog' });
  expect(catalog).toHaveAttribute('href', '#catalog');
  expect(fireEvent.click(catalog)).toBe(true);
  expect(visit).not.toHaveBeenCalled();
});

it.each(['Main navigation', 'Footer navigation'])('%s keeps homepage licensing anchors native', navigation => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
  render(<Storefront tracks={[]} licenseTiers={[]} catalogPage={catalogPage} />);
  const licensing = within(screen.getByRole('navigation', { name: navigation })).getByRole('link', { name: 'Licensing' });
  expect(licensing).toHaveAttribute('href', '#licenses');
  expect(fireEvent.click(licensing)).toBe(true);
  expect(visit).not.toHaveBeenCalled();
});
