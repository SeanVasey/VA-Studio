import { useState, type MouseEvent, type ReactNode } from 'react';
import type { SiteContent } from '../lib/site-content';
import { Icon } from './Icon';

interface ChromeProps {
  content: SiteContent;
  homeHref: string;
  href: (path: string) => string;
  onNavigate: (event: MouseEvent<HTMLAnchorElement>) => void;
  currentPath?: string;
}

export function SiteHeader({ content, homeHref, href, onNavigate, currentPath, children, customerAccountEnabled = false }: ChromeProps & { children?: ReactNode; customerAccountEnabled?: boolean }) {
  const [menuOpen, setMenuOpen] = useState(false);
  function follow(event: MouseEvent<HTMLAnchorElement>) {
    setMenuOpen(false);
    onNavigate(event);
  }
  return <header className="site-header">
    <a className="brand-link" href={homeHref} onClick={follow} aria-label="VASEY.AUDIO home"><img src="/brand/vasey-audio-logo.png" alt="VASEY.AUDIO" width="420" height="100" /></a>
    <nav id="site-navigation" className={`site-navigation ${menuOpen ? 'is-open' : ''}`} aria-label="Main navigation" onKeyDown={event => { if (event.key === 'Escape') { setMenuOpen(false); document.getElementById('site-menu-toggle')?.focus(); } }}>
      {content.navigation.map(item => <a key={item.href} href={href(item.href)} onClick={follow} aria-current={item.href === currentPath ? 'page' : undefined}>{item.label}</a>)}
      {customerAccountEnabled && <a href="/account" onClick={() => setMenuOpen(false)} aria-current={currentPath === '/account' ? 'page' : undefined}>Your library</a>}
    </nav>
    <div className="header-actions"><a className="admin-link" href="/admin">Artist admin <Icon name="northeast" size={13} /></a>{children}<button id="site-menu-toggle" className="mobile-nav-toggle" aria-controls="site-navigation" aria-expanded={menuOpen} onClick={() => setMenuOpen(!menuOpen)}>{menuOpen ? 'Close menu' : 'Menu'}</button></div>
  </header>;
}

export function SiteFooter({ content, homeHref, href, onNavigate, children }: ChromeProps & { children?: ReactNode }) {
  return <footer className="site-footer"><div className="footer-top">
    <a href={homeHref} onClick={onNavigate} aria-label="VASEY.AUDIO home"><img className="footer-logo" src="/brand/vasey-audio-logo.png" alt="VASEY.AUDIO" width="420" height="100" /></a>
    <p style={{ whiteSpace: 'pre-line' }}>{content.footer.description}</p>
    <nav aria-label="Footer navigation">{content.navigation.map(item => <a key={item.href} href={href(item.href)} onClick={onNavigate}>{item.label}</a>)}<a href="/admin">Artist admin <Icon name="northeast" size={14} /></a></nav>
  </div>{children}<div className="footer-bottom"><span>© {new Date().getFullYear()} VASEY.AUDIO</span><span>COMPOSED WITH INTENT.</span></div></footer>;
}
