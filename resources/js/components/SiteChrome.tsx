import { useState, type MouseEvent, type ReactNode } from 'react';
import type { SiteContent } from '../lib/site-content';
import { Icon } from './Icon';

/** Server-projected links of default-off product families whose own policy is on; rendered as given. */
export interface FamilyLink { label: string; href: string }

interface ChromeProps {
  content: SiteContent;
  familyLinks?: FamilyLink[];
  homeHref: string;
  href: (path: string) => string;
  onNavigate: (event: MouseEvent<HTMLAnchorElement>) => void;
  currentPath?: string;
}

export function SiteHeader({ content, homeHref, href, onNavigate, currentPath, children, customerAccountEnabled = false, familyLinks = [] }: ChromeProps & { children?: ReactNode; customerAccountEnabled?: boolean }) {
  const [menuOpen, setMenuOpen] = useState(false);
  function follow(event: MouseEvent<HTMLAnchorElement>) {
    setMenuOpen(false);
    onNavigate(event);
  }
  return <header className="site-header">
    <a className="brand-link" href={homeHref} onClick={follow} aria-label="VASEY.AUDIO home"><img src="/brand/vasey-audio-logo.png" alt="VASEY.AUDIO" width="420" height="100" /></a>
    <nav id="site-navigation" className={`site-navigation ${menuOpen ? 'is-open' : ''}`} aria-label="Main navigation" onKeyDown={event => { if (event.key === 'Escape') { setMenuOpen(false); document.getElementById('site-menu-toggle')?.focus(); } }}>
      {content.navigation.map(item => <a key={item.href} href={href(item.href)} onClick={follow} aria-current={item.href === currentPath ? 'page' : undefined}>{item.label}</a>)}
      {familyLinks.map(link => <a key={link.href} href={link.href} onClick={() => setMenuOpen(false)} aria-current={currentPath === link.href ? 'page' : undefined}>{link.label}</a>)}
      {customerAccountEnabled && <a href="/account" onClick={() => setMenuOpen(false)} aria-current={currentPath === '/account' ? 'page' : undefined}>Your library</a>}
    </nav>
    <div className="header-actions"><a className="admin-link" href="/admin">Artist admin <Icon name="northeast" size={13} /></a>{children}<button id="site-menu-toggle" className="mobile-nav-toggle" aria-controls="site-navigation" aria-expanded={menuOpen} onClick={() => setMenuOpen(!menuOpen)}>{menuOpen ? 'Close menu' : 'Menu'}</button></div>
  </header>;
}

/**
 * The footer lists one link per page that resolves: Home, then the published navigation (which a site release limits to
 * pages it includes), published legal pages, enabled product-family entry points, Account only when customer accounts are
 * enabled, and Artist admin. Nothing is hard-coded to a route that may 404.
 */
export function footerLinks({ content, homeHref, href, familyLinks = [], customerAccountEnabled = false }: Pick<ChromeProps, 'content' | 'homeHref' | 'href' | 'familyLinks'> & { customerAccountEnabled?: boolean }): { key: string; label: string; href: string; internal: boolean }[] {
  const links: { key: string; label: string; href: string; internal: boolean }[] = [];
  if (!content.navigation.some(item => item.href === '/')) links.push({ key: 'home', label: 'Home', href: homeHref, internal: true });
  for (const item of content.navigation) links.push({ key: item.href, label: item.label, href: href(item.href), internal: true });
  for (const link of content.legal_links ?? []) {
    if (!content.navigation.some(item => item.href === link.path)) links.push({ key: link.path, label: link.title, href: href(link.path), internal: true });
  }
  for (const link of familyLinks) links.push({ key: link.href, label: link.label, href: link.href, internal: false });
  if (customerAccountEnabled) links.push({ key: '/account', label: 'Account', href: '/account', internal: false });
  return links;
}

export function SiteFooter({ content, homeHref, href, onNavigate, children, familyLinks = [], customerAccountEnabled = false }: ChromeProps & { children?: ReactNode; customerAccountEnabled?: boolean }) {
  const links = footerLinks({ content, homeHref, href, familyLinks, customerAccountEnabled });
  return <footer className="site-footer"><div className="footer-top">
    <a href={homeHref} onClick={onNavigate} aria-label="VASEY.AUDIO home"><img className="footer-logo" src="/brand/vasey-audio-logo.png" alt="VASEY.AUDIO" width="420" height="100" /></a>
    <p style={{ whiteSpace: 'pre-line' }}>{content.footer.description}</p>
    <nav aria-label="Footer navigation">{links.map(link => <a key={link.key} href={link.href} onClick={link.internal ? onNavigate : undefined}>{link.label}</a>)}<a href="/admin">Artist admin <Icon name="northeast" size={14} /></a></nav>
  </div>{children}<div className="footer-bottom"><span>© {new Date().getFullYear()} VASEY.AUDIO</span><span>COMPOSED WITH INTENT.</span></div></footer>;
}
