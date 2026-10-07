import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Storefront from './Pages/Storefront';
import CheckoutReturn from './Pages/CheckoutReturn';
import Editorial from './Pages/Editorial';
import CustomerSignIn from './Pages/CustomerSignIn';
import CustomerLibrary from './Pages/CustomerLibrary';
import CustomerAccessRequest from './Pages/CustomerAccessRequest';
import CustomerAccessFinish from './Pages/CustomerAccessFinish';
import ProductionCustomerIdentity from './Pages/ProductionCustomerIdentity';
import ServiceProjects from './Pages/ServiceProjects';
import PrivateSupportAttachments from './Pages/PrivateSupportAttachments';
import { captureProductionIdentityProof } from './lib/production-customer-identity';
import { captureCustomerIdentityProof } from './lib/customer-identity';
import { registerStorefrontOfflineRecovery } from './lib/storefront-offline';
import '../css/app.css';

captureCustomerIdentityProof();
captureProductionIdentityProof();
createInertiaApp({
  title: title => title || 'VASEY.AUDIO — Sound with intent',
  resolve: name => {
    if (name === 'Storefront') return Storefront;
    if (name === 'CheckoutReturn') return CheckoutReturn;
    if (name === 'Editorial') return Editorial;
    if (name === 'CustomerSignIn') return CustomerSignIn;
    if (name === 'CustomerLibrary') return CustomerLibrary;
    if (name === 'CustomerAccessRequest') return CustomerAccessRequest;
    if (name === 'CustomerAccessFinish') return CustomerAccessFinish;
    if (name === 'ProductionCustomerIdentity') return ProductionCustomerIdentity;
    if (name === 'ServiceProjects') return ServiceProjects;
    if (name === 'PrivateSupportAttachments') return PrivateSupportAttachments;
    throw new Error(`Unknown page: ${name}`);
  },
  setup({ el, App, props }) { createRoot(el).render(<App {...props} />); },
});

void registerStorefrontOfflineRecovery();
router.on('navigate', () => { void registerStorefrontOfflineRecovery(); });
