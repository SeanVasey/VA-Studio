import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import { defaultSiteContent, type SiteContent } from '../../resources/js/lib/site-content';
import { fixtureTracks, fixtureTiers } from '../../resources/js/test/fixtures';

function content(): SiteContent {
  return {
    ...defaultSiteContent,
    hero: { eyebrow: 'SYNTHETIC RELEASE', title: 'A DIFFERENT', line_two: 'HOME TITLE.', description: 'Line one.\nLine two.' },
    studio: { ...defaultSiteContent.studio, title: 'STUDIO DRAFT', paragraphs: ['A retained first paragraph.', 'A retained second paragraph.'] },
    navigation: [{ label: 'Published music', href: '/#catalog' }, { label: 'License details', href: '/#licenses' }],
    footer: { description: 'Synthetic footer & studio.' },
  };
}

describe('versioned storefront content', () => {
  it('renders one supplied content snapshot while retaining fixed identity and artwork', () => {
    render(<Storefront tracks={[]} licenseTiers={[]} siteContent={content()} />);
    expect(document.getElementById('hero-title')).toHaveTextContent(/A DIFFERENT\s*HOME TITLE\./);
    expect(screen.getByText('A retained first paragraph.')).toBeInTheDocument();
    expect(screen.getByText('A retained second paragraph.')).toBeInTheDocument();
    expect(screen.getByText('Synthetic footer & studio.')).toBeInTheDocument();
    const nav = screen.getByRole('navigation', { name: 'Main navigation' });
    expect(within(nav).getByRole('link', { name: 'Published music' })).toHaveAttribute('href', '#catalog');
    expect(within(nav).queryByRole('link', { name: 'The studio' })).not.toBeInTheDocument();
    expect(screen.getAllByRole('img', { name: 'VASEY.AUDIO' })[0]).toHaveAttribute('src', '/brand/vasey-audio-logo.png');
    expect(screen.getByRole('button', { name: 'Open cart, 0 items' })).toBeInTheDocument();
  });

  it('renders text as text and does not inject executable markup', () => {
    const supplied = content(); supplied.studio.paragraphs = ['<script>globalThis.CMS_LEAK=true</script>'];
    render(<Storefront tracks={[]} licenseTiers={[]} siteContent={supplied} />);
    expect(screen.getByText(supplied.studio.paragraphs[0])).toBeInTheDocument();
    expect(document.querySelector('script')).toBeNull();
  });

  it('isolates private preview from cart persistence, selection POSTs and purchase controls', () => {
    sessionStorage.setItem('vaseyaudio-cart-v1', 'retained-browser-value');
    const read = vi.spyOn(Storage.prototype, 'getItem');
    const write = vi.spyOn(Storage.prototype, 'setItem');
    const fetcher = vi.spyOn(globalThis, 'fetch');
    render(<Storefront tracks={fixtureTracks} licenseTiers={fixtureTiers} siteContent={content()} sitePreview testOrderPreparationEnabled testCheckoutEnabled />);
    expect(screen.queryByRole('button', { name: /Open cart/ })).not.toBeInTheDocument();
    for (const button of screen.getAllByRole('button', { name: /^Choose license for/ })) expect(button).toBeDisabled();
    expect(read).not.toHaveBeenCalled(); expect(write).not.toHaveBeenCalled(); expect(fetcher).not.toHaveBeenCalled();
    expect(document.getElementById('hero-title')).toHaveTextContent(/A DIFFERENT\s*HOME TITLE\./);
  });

  it('retains the default site copy for the isolated design composition', () => {
    render(<Storefront tracks={[]} licenseTiers={[]} designPreview />);
    expect(document.getElementById('hero-title')).toHaveTextContent(/SOUND\s*WITH INTENT\./);
    expect(screen.getByText(/Sample catalog for design review/)).toBeInTheDocument();
  });
});
