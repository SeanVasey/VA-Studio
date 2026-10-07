import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { ServiceProjectJourney, type ServiceProjectIndex } from '../../../resources/js/components/services/ServiceProjectJourney';

it('access denial clears entered private text before another successful account read', async () => {
  const index: ServiceProjectIndex = { schema: 1, testOnly: true, projects: [], services: [{ versionId: 7, version: 1,
    hash: 'a'.repeat(64), title: 'Synthetic service', description: 'Synthetic definition', questions: ['Private question'] }] };
  const fetcher = vi.fn();
  vi.stubGlobal('fetch', fetcher);
  render(<ServiceProjectJourney initial={index} />);
  fireEvent.change(screen.getByLabelText('Service definition'), { target: { value: '7' } });
  fireEvent.change(screen.getByLabelText('Your project brief'), { target: { value: 'First account private unsaved brief' } });
  fireEvent.change(screen.getByLabelText('Private question'), { target: { value: 'First account private unsaved answer' } });
  fetcher.mockResolvedValueOnce(new Response('{}', { status: 403 }));
  fireEvent.click(screen.getByRole('button', { name: 'Refresh projects' }));
  await waitFor(() => expect(screen.queryByLabelText('Submit a private service brief')).not.toBeInTheDocument());
  fetcher.mockResolvedValueOnce(new Response(JSON.stringify(index), { status: 200 }));
  fireEvent.click(screen.getByRole('button', { name: 'Refresh projects' }));
  await screen.findByLabelText('Submit a private service brief');
  // A new current account response must not revive the withdrawn account's input.
  expect(screen.queryByDisplayValue('First account private unsaved brief')).not.toBeInTheDocument();
  expect(screen.queryByDisplayValue('First account private unsaved answer')).not.toBeInTheDocument();
});
