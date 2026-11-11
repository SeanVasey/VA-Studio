import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import { fixtureTracks, fixtureTiers } from '../../resources/js/test/fixtures';

// The track page may only say "tagged" when the server says the served preview is tagged.
const renderPage = (previewTagged?: boolean) => render(
  <Storefront tracks={[]} licenseTiers={fixtureTiers} selectedTrack={{ ...fixtureTracks[0], previewTagged }} selectedTrackSlug={fixtureTracks[0].slug} />,
);

it('says tagged preview only when the server reports a tagged preview', () => {
  renderPage(true);
  expect(screen.getByText(/Listen to the tagged preview\./)).toBeTruthy();
});

it('uses neutral wording when the server reports an untagged preview', () => {
  renderPage(false);
  expect(screen.getByText(/Listen to the preview\./)).toBeTruthy();
  expect(screen.queryByText(/tagged preview/)).toBeNull();
});

it('uses neutral wording when the source does not say', () => {
  renderPage(undefined);
  expect(screen.getByText(/Listen to the preview\./)).toBeTruthy();
});
