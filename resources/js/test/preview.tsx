import { createRoot } from 'react-dom/client';
import Storefront from '../Pages/Storefront';
import { fixtureTracks, fixtureTiers } from './fixtures';
import '../../css/app.css';

const selected = fixtureTracks.find(track => track.slug === new URLSearchParams(window.location.search).get('detail'));
const fixturesEnabled = new URLSearchParams(window.location.search).has('fixtures');
createRoot(document.getElementById('app')!).render(<Storefront tracks={fixturesEnabled ? fixtureTracks : []} licenseTiers={fixturesEnabled ? fixtureTiers : []} selectedTrack={fixturesEnabled ? selected : null} selectedTrackSlug={selected?.slug} designPreview />);
