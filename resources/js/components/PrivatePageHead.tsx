import { Head } from '@inertiajs/react';

/** Fixed private status metadata; keyed tags replace public page metadata on Inertia visits. */
export function PrivatePageHead() {
  return <Head title="Checkout status — VASEY.AUDIO">
    <meta head-key="description" name="description" content="View the saved test order status for this session. A browser return does not verify payment." />
    <meta head-key="robots" name="robots" content="noindex, nofollow" />
    <meta head-key="referrer" name="referrer" content="no-referrer" />
  </Head>;
}
