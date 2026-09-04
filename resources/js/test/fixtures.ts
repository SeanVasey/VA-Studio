import type { StorefrontProps, Track } from '../lib/catalog';

// Deliberately synthetic fixtures. Never imported by the production application.
export const fixtureTiers: StorefrontProps['licenseTiers'] = [
  { id: 'fixture-standard-v1', name: 'Standard license', version: 'DEMO-1', type: 'non_exclusive', features: ['Illustrative terms for development only', 'No usage rights are granted'], requiredAssetRoles: ['download_mp3'] },
  { id: 'fixture-premium-v1', name: 'Premium license', version: 'DEMO-1', type: 'non_exclusive', features: ['Illustrative terms for development only', 'No usage rights are granted'], requiredAssetRoles: ['download_mp3', 'master_wav'] },
  { id: 'fixture-stems-v1', name: 'Trackout license', version: 'DEMO-1', type: 'non_exclusive', features: ['Illustrative terms for development only', 'No usage rights are granted'], requiredAssetRoles: ['download_mp3', 'master_wav', 'stems_zip'] },
];

const sampleNames = ['Midnight architecture', 'Low frequency theory', 'A different kind of quiet', 'Concrete & strings'];
export const fixtureTracks: Track[] = sampleNames.map((title, i) => ({
  id: `fixture-track-${i}`, slug: `fixture-track-${i}`, title: `${title} [DEMO]`, artist: 'Development sample',
  bpm: [92, 140, 78, 96][i], musicalKey: ['F minor', 'C minor', 'D minor', 'G minor'][i], genre: ['Hip-hop', 'Trap', 'Cinematic', 'Hip-hop'][i],
  mood: ['Atmospheric', 'Heavy', 'Reflective', 'Orchestral'][i], durationSeconds: [187, 164, 212, 198][i],
  artworkUrl: null, previewUrl: null, waveform: [], tags: [i === 2 ? 'Piano' : 'Texture'],
  shareUrl: `/tracks/fixture-track-${i}`,
  offers: fixtureTiers.map((tier, index) => ({ id: `${tier.id}-track-${i}`, licenseVersionId: tier.id, licenseName: tier.name, priceMinor: [2995, 4995, 9995][index], currency: 'USD', deliverableRoles: tier.requiredAssetRoles })),
}));
