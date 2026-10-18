import { Head } from '@inertiajs/react';
import { PaidTaxJourney } from '../components/PaidTaxJourney';
import '../../css/customer-account.css';
import '../../css/paid-grants.css';

/** Shell only: no Inertia props. Every private value is read client-side from the default-off JSON endpoints. */
export default function PaidTaxLicenses() {
  return <><Head title="Tax licenses"><meta name="robots" content="noindex, nofollow" /></Head>
    <a className="skip-link" href="#main">Skip to content</a>
    <main id="main" className="customer-account section-pad"><div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / YOUR LICENSES</p><h1>Tax licenses</h1></div>
      <PaidTaxJourney /><a className="customer-account-back text-link" href="/customer">Back to your account</a>
    </main></>;
}
