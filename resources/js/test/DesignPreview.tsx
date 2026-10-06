import Storefront from '../Pages/Storefront';
import { fixtureTracks, fixtureTiers } from './fixtures';

/** Isolated composition entry: these controls and fixtures never enter the production app. */
export default function DesignPreview({ search = window.location.search }: { search?: string }) {
  const parameters = new URLSearchParams(search);
  const fixturesEnabled = parameters.has('fixtures');
  const detailSlug = parameters.get('detail');
  const selected = fixturesEnabled ? fixtureTracks.find(track => track.slug === detailSlug) : undefined;
  const missingDetail = fixturesEnabled && detailSlug !== null && !selected;
  const scenario = selected ? 'detail' : fixturesEnabled ? 'catalog' : 'empty';

  return <>
    <aside className="design-preview-controls" aria-labelledby="design-preview-title">
      <div className="design-preview-intro">
        <h2 id="design-preview-title">Explore the storefront</h2>
        <p>Choose a view of the real storefront. Sample tracks, prices and terms are illustrative. No purchases or licenses are issued.</p>
      </div>
      <nav className="design-preview-scenarios" aria-label="Preview views">
        <a href="?" aria-current={scenario === 'empty' ? 'page' : undefined}>Empty catalog</a>
        <a href="?fixtures" aria-current={scenario === 'catalog' ? 'page' : undefined}>Sample catalog</a>
        <a href={`?fixtures&detail=${encodeURIComponent(fixtureTracks[0].slug)}`} aria-current={scenario === 'detail' ? 'page' : undefined}>Track detail</a>
      </nav>
      <p className="design-preview-guidance">{fixturesEnabled
        ? 'Try search, genre filters, sorting, license selection and the cart. Audio and full license text are unavailable in these samples.'
        : 'This is how the site looks before tracks are published. Choose Sample catalog to try browsing and license selection.'}</p>
      <p className="design-preview-guidance">Use the preview links above to change pages. Artist admin requires the full local app.</p>
      {missingDetail && <p className="design-preview-recovery" role="status">That sample track is not available. Showing the sample catalog.</p>}
    </aside>
    <Storefront tracks={fixturesEnabled ? fixtureTracks : []} licenseTiers={fixturesEnabled ? fixtureTiers : []} selectedTrack={selected ?? null} selectedTrackSlug={selected?.slug} designPreview />
  </>;
}
