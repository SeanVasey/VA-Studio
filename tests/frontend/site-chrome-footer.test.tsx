import { readFileSync } from 'node:fs';
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SiteFooter, footerLinks } from '../../resources/js/components/SiteChrome';
import { defaultSiteContent, type SiteContent } from '../../resources/js/lib/site-content';

const href = (path: string) => path;
const base = { homeHref: '/', href, onNavigate: () => {} };
function labels(content: SiteContent, customerAccountEnabled = false) {
  render(<SiteFooter {...base} content={content} customerAccountEnabled={customerAccountEnabled} />);
  return within(screen.getByRole('navigation', { name: 'Footer navigation' })).getAllByRole('link').map(link => link.textContent?.trim());
}

describe('footer link set', () => {
  it('lists only Home, the default navigation and Artist admin when nothing else is published', () => {
    expect(labels(defaultSiteContent)).toEqual(['Home', 'The catalog', 'Licensing', 'The studio', 'Artist admin']);
  });

  it('adds editorial pages and legal pages only when the release publishes them', () => {
    const published: SiteContent = {
      ...defaultSiteContent, schema_version: 5,
      navigation: [...defaultSiteContent.navigation, { label: 'About', href: '/about' }, { label: 'Contact', href: '/contact' }, { label: 'Blog', href: '/blog' }, { label: 'Videos', href: '/videos' }],
      legal_links: [{ title: 'Privacy', path: '/legal/privacy' }, { title: 'Terms', path: '/legal/terms' }, { title: 'Licenses', path: '/legal/licenses' }],
    };
    expect(labels(published)).toEqual(['Home', 'The catalog', 'Licensing', 'The studio', 'About', 'Contact', 'Blog', 'Videos', 'Privacy', 'Terms', 'Licenses', 'Artist admin']);
  });

  it('never invents a link to an unpublished page', () => {
    const rendered = labels({ ...defaultSiteContent, legal_links: [] });
    for (const absent of ['About', 'Contact', 'Blog', 'Videos', 'Privacy', 'Terms', 'Licenses', 'Account']) expect(rendered).not.toContain(absent);
  });

  it('shows Account only when customer accounts are enabled', () => {
    expect(labels(defaultSiteContent, true)).toEqual(['Home', 'The catalog', 'Licensing', 'The studio', 'Account', 'Artist admin']);
  });

  it('does not repeat Home when the release navigation already includes it', () => {
    const content: SiteContent = { ...defaultSiteContent, navigation: [{ label: 'Start', href: '/' }, ...defaultSiteContent.navigation] };
    expect(footerLinks({ ...base, content }).filter(link => link.href === '/')).toHaveLength(1);
  });
});

describe('storefront signage stylesheet', () => {
  const css = readFileSync('resources/css/app.css', 'utf8');
  const rule = (selector: string) => css.split('\n').filter(line => line.startsWith(selector + ' {')).join('\n');

  it('keeps the hero headline on the impact face and sets no other family on it', () => {
    expect(rule('h1, h2')).toContain('var(--va-font-impact)');
    expect(rule('.hero h1')).not.toMatch(/font-family/);
  });

  it('sets header links, cart label, footer links and section titles in Bebas Neue uppercase', () => {
    expect(rule('.site-header nav a, .admin-link, .cart-text, .footer-top nav a')).toMatch(/var\(--va-font-section\).*uppercase.*letter-spacing: \.08em/);
    expect(rule('.section-heading h2, .studio-copy h2')).toContain('var(--va-font-section)');
    expect(rule('.license-card h3')).toContain('uppercase');
    expect(rule('.detail-offer h3')).toContain('var(--va-font-section)');
  });

  it('gives every license card equal padding and a turquoise edge only on the even card', () => {
    expect(css).not.toMatch(/\.license-card:(first|last)-child/);
    expect(rule('.license-card:nth-child(even), .detail-offer:nth-child(even)')).toContain('inset 0 3px 0 var(--va-action)');
  });

  it('adds no hex colours outside the brand theme', () => {
    expect(css).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
  });
});
