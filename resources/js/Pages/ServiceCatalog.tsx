import type { MouseEvent } from 'react';
import { router } from '@inertiajs/react';
import { Icon } from '../components/Icon';
import { MetadataHead } from '../components/MetadataHead';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import { siteContentHref, type SiteContent } from '../lib/site-content';
import type { PageMetadata } from '../lib/catalog';
import '../../css/service-catalog.css';

/** Server projection of one staff-approved service. Every value is rendered as given; nothing is computed here. */
export interface PublicService {
  slug: string;
  title: string;
  description: string;
  sections: { label: string; text: string }[];
  price: string;
  path: string;
  requestHref: string;
}

interface ServiceCatalogProps { siteContent: SiteContent; services: PublicService[]; service: PublicService | null; intro: string; metadata: PageMetadata }

export default function ServiceCatalog({ siteContent, services, service, intro, metadata }: ServiceCatalogProps) {
  const href = (path: string) => siteContentHref(path, null);
  function navigate(event: MouseEvent<HTMLAnchorElement>) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    router.visit(event.currentTarget.href, { preserveScroll: false, onSuccess: () => document.getElementById('service-title')?.focus({ preventScroll: true }) });
  }
  const chrome = { content: siteContent, homeHref: href('/'), href, onNavigate: navigate };
  return <>
    <MetadataHead metadata={metadata} publicInstallation />
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} currentPath="/services" />
    <main id="main" className="editorial-page section-pad service-catalog">
      {service === null ? <>
        <div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / services</p><h1 id="service-title" tabIndex={-1}>Services</h1><p className="editorial-description">{intro}</p></div>
        {services.length === 0 ? <p className="service-empty">No services are listed right now. You can still send a general question from the contact page.</p>
          : <ul className="service-list" aria-label="Services">{services.map(item => <li className="service-card" key={item.slug}>
            <h2><a href={item.path} onClick={navigate}>{item.title}</a></h2>
            {item.description !== '' && <p>{item.description}</p>}
            <p className="service-price"><span className="service-price-label">Price</span> {item.price}</p>
            <div className="service-actions">
              <a className="text-link" href={item.path} onClick={navigate} aria-label={`View ${item.title}`}>View service <Icon name="arrow" size={16} /></a>
              <a className="button button-small" href={item.requestHref} onClick={navigate} aria-label={`Request ${item.title}`}>Request this service</a>
            </div>
          </li>)}</ul>}
      </> : <article aria-labelledby="service-title">
        <div className="editorial-heading">
          <p className="eyebrow">VASEY.AUDIO / services</p>
          <a className="text-link editorial-back" href={href('/services')} onClick={navigate}>Back to services <Icon name="arrow" size={16} /></a>
          <h1 id="service-title" tabIndex={-1}>{service.title}</h1>
          {service.description !== '' && <p className="editorial-description">{service.description}</p>}
        </div>
        <p className="service-price"><span className="service-price-label">Price</span> {service.price}</p>
        {service.sections.length > 0 && <section aria-labelledby="service-terms-title" className="service-terms">
          <h2 id="service-terms-title">What to expect</h2>
          <p className="fine-print">Indicative only. The written quote you accept sets the binding scope, price and terms.</p>
          <dl>{service.sections.map(section => <div key={section.label}><dt>{section.label}</dt><dd>{section.text}</dd></div>)}</dl>
        </section>}
        <p className="fine-print">Sending a request starts a conversation. It books nothing and takes no payment.</p>
        <div className="service-actions"><a className="button" href={service.requestHref} onClick={navigate}>Request this service</a></div>
      </article>}
    </main>
    <SiteFooter {...chrome} />
  </>;
}
