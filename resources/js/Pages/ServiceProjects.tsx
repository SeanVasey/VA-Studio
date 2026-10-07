import { Head } from '@inertiajs/react';
import { ServiceProjectJourney, type ServiceProjectIndex } from '../components/services/ServiceProjectJourney';
import '../../css/customer-account.css';
import '../../css/service-projects.css';

export default function ServiceProjects({ initial }: { initial: ServiceProjectIndex }) {
  return <>
    <Head title="Your service projects"><meta name="robots" content="noindex, nofollow" /></Head>
    <a className="skip-link" href="#main">Skip to content</a>
    <main id="main" className="customer-account section-pad">
      <div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / TEST ACCOUNT</p><h1>Your service projects</h1></div>
      <ServiceProjectJourney initial={initial} />
    </main>
  </>;
}
