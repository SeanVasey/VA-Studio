import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CustomerCommunicationPreferences, validCommunicationPreferences } from '../../resources/js/components/CustomerCommunicationPreferences';
const value = (status: string) => ({ schema: 1, purposes: [{ purpose: 'email_marketing', version: 1, status: 'withdrawn', notice: null, canGrant: false, suppression: { status } }] });
describe('private suppression evidence', () => {
  it.each([
    ['pending', 'Suppression is queued. Provider confirmation is pending.'],
    ['unknown', 'Provider suppression is not confirmed. A new request will not be sent automatically.'],
    ['confirmed', 'Suppression is confirmed for the configured provider scope.'],
  ])('shows %s independently from the saved withdrawal without a transport control', async (status, text) => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ preferences: value(status) }), { headers: { 'Content-Type': 'application/json' } }));
    render(<CustomerCommunicationPreferences scope="synthetic-suppression-A" />);
    fireEvent.click(screen.getByRole('button', { name: 'Open communication preferences' }));
    expect(await screen.findByText(text)).toBeInTheDocument(); expect(screen.getByText('Your saved choice is withdrawn.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /send|retry|reconcile/i })).not.toBeInTheDocument(); expect(fetcher).toHaveBeenCalledTimes(1);
  });
  it.each([
    { status: 'success' }, { status: 'confirmed', email: 'private@example.test' }, { status: 'pending', provider: 'private' }, null,
  ])('rejects malformed or extended provider evidence', suppression => {
    const preferences = value('unknown'); preferences.purposes[0].suppression = suppression as never;
    expect(validCommunicationPreferences(preferences)).toBe(false);
  });
});
