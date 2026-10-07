import { fireEvent, render, screen, act } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import ProductionCustomerIdentity from '../../resources/js/Pages/ProductionCustomerIdentity';
import { captureProductionIdentityProof } from '../../resources/js/lib/production-customer-identity';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
afterEach(() => { vi.restoreAllMocks(); window.history.replaceState(null, '', '/'); captureProductionIdentityProof(); });

it('erases completion credentials on page departure and ignores the pending acknowledgement', async () => {
  window.history.replaceState(null, '', '/customer/access#enroll.aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.' + 'a'.repeat(64));
  captureProductionIdentityProof();
  let finish!: (response: Response) => void;
  vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(resolve => { finish = resolve; }));
  render(<ProductionCustomerIdentity mode="complete" rehearsal />);
  fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Synthetic declared name' } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'SyntheticPassword123' } });
  fireEvent.submit(screen.getByRole('button', { name: 'Complete account access' }).closest('form')!);
  fireEvent(window, new Event('pagehide'));
  expect(screen.getByLabelText('Password')).toHaveValue('');
  expect(screen.getByLabelText('Your name')).toHaveValue('');
  await act(async () => { finish(new Response('{"completed":true,"next":"/customer/sign-in"}', { status: 200 })); });
  expect(screen.queryByText('Account access completed. Sign in with your current password.')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Retry same request' })).not.toBeInTheDocument();
});
