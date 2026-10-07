import { Head } from '@inertiajs/react';
import { SupportAttachments } from '../components/SupportAttachments';
import '../../css/customer-account.css';

export default function PrivateSupportAttachments({ sourceId, sourceKind, audience, renderScope }: {
  sourceId: string; sourceKind: 'inquiry' | 'project'; audience: 'visitor' | 'customer' | 'operator'; renderScope: string;
}) {
  return <>
    <Head title="Private attachments"><meta name="robots" content="noindex, nofollow" /><meta name="referrer" content="no-referrer" /></Head>
    <a className="skip-link" href="#main">Skip to content</a>
    <main id="main" className="customer-account section-pad"><h1>Private attachments</h1>
      <SupportAttachments key={`${renderScope}:${audience}:${sourceKind}:${sourceId}`} sourceId={sourceId} sourceKind={sourceKind} audience={audience} renderScope={renderScope} />
      <a className="text-link" href={audience === 'operator' ? '/admin' : sourceKind === 'inquiry' ? '/contact' : '/services/projects'}>Back</a>
    </main>
  </>;
}
