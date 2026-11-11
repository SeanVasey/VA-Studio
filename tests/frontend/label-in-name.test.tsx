import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import { fixtureTracks, fixtureTiers } from '../../resources/js/test/fixtures';

// WCAG 2.5.3 Label in Name, as axe's label-content-name-mismatch rule applies it: the control's visible words must
// appear, in order and contiguously, in its accessible name (case, punctuation and spacing ignored).
const words = (text: string) => text.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(' ').filter(Boolean);
function expectLabelInName(control: HTMLElement) {
  const visible = words(control.textContent ?? '');
  const name = words(control.getAttribute('aria-label') ?? '');
  expect(visible.length).toBeGreaterThan(0);
  const found = name.some((_, start) => visible.every((word, offset) => name[start + offset] === word));
  expect(found, `visible "${visible.join(' ')}" must be inside the name "${name.join(' ')}"`).toBe(true);
}

it('keeps the cart toggle visible text inside its accessible name, with and without items', () => {
  const { unmount } = render(<Storefront tracks={fixtureTracks} licenseTiers={fixtureTiers} />);
  const toggle = screen.getByRole('button', { name: 'Open cart, 0 items' });
  expect(toggle.querySelector('.cart-count')).toHaveAttribute('data-pad', '0');
  expectLabelInName(toggle);
  unmount();
});

it('keeps the track detail play button visible text inside its accessible name', () => {
  const track = { ...fixtureTracks[0], id: '1', offers: fixtureTracks[0].offers.map((offer, index) => ({ ...offer, licenseUrl: `/tracks/test/offers/${index}` })) };
  render(<Storefront tracks={[]} licenseTiers={fixtureTiers} selectedTrack={track} selectedTrackSlug={track.slug} />);
  expectLabelInName(screen.getByRole('button', { name: `Play preview of ${track.title}` }));
});
