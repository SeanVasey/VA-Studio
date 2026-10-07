import { Head } from '@inertiajs/react';
import { PaidGrantJourney } from '../components/PaidGrantJourney';
import '../../css/customer-account.css';
import '../../css/paid-grants.css';

export default function PaidGrants() {
  return <><Head title="Paid licenses"><meta name="robots" content="noindex, nofollow" /></Head>
    <a className="skip-link" href="#main">Skip to content</a>
    <main id="main" className="customer-account section-pad"><div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / YOUR LICENSES</p><h1>Paid licenses</h1></div>
      <PaidGrantJourney /><a className="customer-account-back text-link" href="/customer">Back to your account</a>
    </main></>;
}
