import { createRoot } from 'react-dom/client';
import Storefront from '../Pages/Storefront';
import { fixtureTracks, fixtureTiers } from './fixtures';
import '../../css/app.css';

const fixturesEnabled = new URLSearchParams(window.location.search).has('fixtures');
createRoot(document.getElementById('app')!).render(<Storefront tracks={fixturesEnabled ? fixtureTracks : []} licenseTiers={fixturesEnabled ? fixtureTiers : []} designPreview />);
