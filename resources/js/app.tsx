import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Storefront from './Pages/Storefront';
import CheckoutReturn from './Pages/CheckoutReturn';
import '../css/app.css';

createInertiaApp({
  title: title => title || 'VASEY.AUDIO — Sound with intent',
  resolve: name => {
    if (name === 'Storefront') return Storefront;
    if (name === 'CheckoutReturn') return CheckoutReturn;
    throw new Error(`Unknown page: ${name}`);
  },
  setup({ el, App, props }) { createRoot(el).render(<App {...props} />); },
});
