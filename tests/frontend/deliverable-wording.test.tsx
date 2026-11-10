import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import { fixtureTracks, fixtureTiers } from '../../resources/js/test/fixtures';

// DD-30: the card's file line and the generated "Deliverables:" line under it name the same files in the same words.
// The Deliverables lines below are the server's wording for each role set (TypedLicenseTerms::statements); the roles
// arrive in stored order, which need not be the generated order.
const generated: Record<string, [string[], string]> = {
  'fixture-standard-v1': [['download_mp3'], 'Deliverables: MP3.'],
  'fixture-premium-v1': [['master_wav', 'download_mp3'], 'Deliverables: MP3, WAV master.'],
  'fixture-stems-v1': [['stems_zip', 'master_wav', 'download_mp3'], 'Deliverables: MP3, WAV master, Stems ZIP.'],
};
const tiers = fixtureTiers.map(tier => ({ ...tier, requiredAssetRoles: generated[tier.id][0], features: ['Audio releases: up to 2.', generated[tier.id][1]] }));
const track = { ...fixtureTracks[0], offers: fixtureTracks[0].offers.map(offer => ({ ...offer, deliverableRoles: generated[offer.licenseVersionId][0] })) };

function agree(cards: Element[]): void {
  expect(cards).toHaveLength(tiers.length);
  cards.forEach(card => {
    const fileLine = card.querySelector('.license-files')!.textContent!;
    const deliverables = [...card.querySelectorAll('li')].map(item => item.textContent!).filter(text => text.startsWith('Deliverables: '));
    expect(deliverables).toHaveLength(1);
    expect(`Deliverables: ${fileLine.split(' + ').join(', ')}.`).toBe(deliverables[0]);
    expect(card.textContent).not.toMatch(/untagged|track stems|master wav/i);
  });
}

it('prints the storefront license card file line in the words of its generated Deliverables line', () => {
  render(<Storefront tracks={[track]} licenseTiers={tiers} />);
  agree([...document.querySelectorAll('.license-card')]);
  expect(screen.getAllByText('MP3 + WAV master + Stems ZIP').length).toBeGreaterThan(0);
});

it('prints the track page offer file line in the words of its generated Deliverables line', () => {
  render(<Storefront tracks={[]} licenseTiers={tiers} selectedTrack={track} selectedTrackSlug={track.slug} />);
  agree([...document.querySelectorAll('.detail-offer')]);
});
