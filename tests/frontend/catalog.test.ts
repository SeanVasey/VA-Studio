import { describe, expect, it } from 'vitest';
import { availableOffer, cartSubtotals, filterTracks, formatMoney, formatTime, safeMediaUrl } from '../../resources/js/lib/catalog';
import { fixtureTracks } from '../../resources/js/test/fixtures';

describe('published catalog presentation', () => {
  it('matches all search words across metadata and applies genre without mutating the source', () => {
    expect(filterTracks(fixtureTracks, 'quiet piano', 'All sounds', 'featured').map(track => track.id)).toEqual(['fixture-track-2']);
    expect(filterTracks(fixtureTracks, '', 'Hip-hop', 'tempo').map(track => track.bpm)).toEqual([92, 96]);
    expect(filterTracks(fixtureTracks, '', 'All sounds', 'tempo').map(track => track.bpm)).toEqual([78, 92, 96, 140]);
    expect(fixtureTracks[0].bpm).toBe(92);
  });
  it('formats integer minor units and keeps mixed currency subtotals separate', () => {
    expect(formatMoney(2995, 'USD')).toBe('$29.95');
    const usd = fixtureTracks[0].offers[0];
    expect(cartSubtotals([{ track: fixtureTracks[0], offer: usd }, { track: fixtureTracks[1], offer: { ...usd, priceMinor: 1000, currency: 'EUR' } }])).toEqual([{ currency: 'USD', amount: 2995 }, { currency: 'EUR', amount: 1000 }]);
    expect(availableOffer({ ...usd, priceMinor: -1 })).toBe(false);
    expect(availableOffer({ ...usd, priceMinor: 29.95 })).toBe(false);
  });
  it('rejects external, protocol-relative, executable and browser-normalized external media URLs', () => {
    expect(safeMediaUrl('/media/asset-id')).toBe(`${window.location.origin}/media/asset-id`);
    expect(safeMediaUrl('https://example.invalid/master.wav')).toBeUndefined();
    expect(safeMediaUrl('//example.invalid/preview.mp3')).toBeUndefined();
    expect(safeMediaUrl('/\\example.invalid/preview.mp3')).toBeUndefined();
    expect(safeMediaUrl('javascript:alert(1)')).toBeUndefined();
    expect(safeMediaUrl('data:audio/wav;base64,AAAA')).toBeUndefined();
  });
  it('handles unknown duration safely', () => {
    expect(formatTime(Number.NaN)).toBe('0:00');
    expect(formatTime(-100)).toBe('0:00');
    expect(formatTime(187.7)).toBe('3:07');
  });
});
