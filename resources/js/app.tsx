import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Storefront from './Pages/Storefront';
import '../css/app.css';

createInertiaApp({
  title: title => title ? `${title} — VASEY.AUDIO` : 'VASEY.AUDIO — Sound with intent',
  resolve: name => {
    if (name === 'Storefront') return Storefront;
    throw new Error(`Unknown page: ${name}`);
  },
  setup({ el, App, props }) { createRoot(el).render(<App {...props} />); },
});
