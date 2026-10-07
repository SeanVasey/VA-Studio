import { Head } from '@inertiajs/react';
import { FreeGrantJourney } from '../components/FreeGrantJourney';
import '../../css/customer-account.css';
import '../../css/free-grants.css';

export default function FreeGrants({ renderScope }: { renderScope?: string }) {
  return <><Head title="Free grants"><meta name="robots" content="noindex, nofollow" /></Head><a className="skip-link" href="#main">Skip to content</a>
    <main id="main" className="customer-account section-pad"><div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / TEST ACCOUNT</p><h1>Free grants</h1></div><FreeGrantJourney key={renderScope} /><a className="customer-account-back text-link" href="/account">Back to your account</a></main></>;
}
