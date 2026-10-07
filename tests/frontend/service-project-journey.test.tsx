import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ServiceProjectJourney, type ServiceProject, type ServiceProjectIndex } from '../../resources/js/components/services/ServiceProjectJourney';

const id = '11111111-1111-4111-8111-111111111111';
const quoteId = '22222222-2222-4222-8222-222222222222';
const quoteHash = 'b'.repeat(64);
const definition = { versionId: 7, version: 2, hash: 'a'.repeat(64), title: 'Synthetic mix service', description: 'A private definition', questions: ['What is your project?'] };
const quoted: ServiceProject = { id, title: definition.title, submittedAt: '2026-10-07 00:00:00', version: 1, status: 'quoted', testOnly: true, summary: 'Private buyer brief', answers: [{ question: 'Question', answer: 'Private answer' }], quoteId, quoteHash,
  quotes: [{ id: quoteId, hash: quoteHash, title: 'Explicit authored scope', scope: '<script> authored as text', currency: 'USD', totalMinor: 12000, depositMinor: 1000, revisionAllowance: 1, cancellation: 'Explicit test cancellation text', milestones: [{ id: 'review', label: 'Scope review', scope: 'Review the exact scope' }] }], milestones: {}, revisionsUsed: 0, scopeFrozen: false, paymentState: 'not_collected', deliveryAuthorized: false, history: [] };
const initial: ServiceProjectIndex = { schema: 1, testOnly: true, services: [definition], projects: [{ id, title: quoted.title, version: 1, status: 'quoted', submittedAt: quoted.submittedAt }] };
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
let fetcher: ReturnType<typeof vi.fn>;
beforeEach(() => { fetcher = vi.fn(); vi.stubGlobal('fetch', fetcher); });

async function open(project: ServiceProject = quoted) {
  fetcher.mockResolvedValueOnce(response({ project }));
  render(<ServiceProjectJourney initial={initial} />);
  fireEvent.click(screen.getByRole('button', { name: /Synthetic mix service · quoted/ }));
  await screen.findByRole('article', { name: 'Exact authored quote' });
}

describe('mounted service buyer journey', () => {
  it('accepts the exact displayed immutable quote and never describes acceptance as paid or delivered', async () => {
    await open();
    fetcher.mockResolvedValueOnce(response({ project: { ...quoted, version: 2, status: 'accepted', scopeFrozen: true, milestones: { review: 'pending' } } }));
    fireEvent.click(screen.getByRole('button', { name: 'Accept this exact scope' }));
    await screen.findByText('Accepted scope is frozen. Payment remains uncollected and delivery is unavailable.');
    const options = fetcher.mock.calls[1][1];
    expect(JSON.parse(options.body)).toMatchObject({ action: 'accept_quote', expectedVersion: 1, quoteId, quoteHash });
    expect(options).toMatchObject({ method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error' });
    expect(document.querySelector('script')).toBeNull();
  });

  it('keeps an uncertain brief retry on the same key and exact retained text while new edits are disabled', async () => {
    fetcher.mockRejectedValueOnce(new Error('Synthetic lost response'));
    render(<ServiceProjectJourney initial={{ ...initial, projects: [] }} />);
    fireEvent.change(screen.getByLabelText('Service definition'), { target: { value: '7' } });
    fireEvent.change(screen.getByLabelText('Your project brief'), { target: { value: 'Synthetic private new brief' } });
    fireEvent.change(screen.getByLabelText('What is your project?'), { target: { value: 'Synthetic answer' } });
    fireEvent.click(screen.getByRole('button', { name: 'Submit private brief' }));
    await screen.findByRole('button', { name: 'Retry exact saved contents' });
    expect(screen.getByLabelText('Your project brief')).toBeDisabled();
    const original = fetcher.mock.calls[0][1].body;
    fetcher.mockResolvedValueOnce(response({ project: { ...quoted, version: 0, status: 'submitted', quoteId: null, quoteHash: null, quotes: [] } }));
    fireEvent.click(screen.getByRole('button', { name: 'Retry exact saved contents' }));
    await screen.findByText('Saved to your scope journey. No payment or delivery was authorized.');
    expect(fetcher.mock.calls[1][1].body).toBe(original);
    expect(JSON.parse(original)).toMatchObject({ serviceVersionId: 7, serviceHash: definition.hash, summary: 'Synthetic private new brief', answers: ['Synthetic answer'] });
    expect(JSON.parse(original)).not.toHaveProperty('owner');
  });

  it('respects the frozen revision allowance and submits a reasoned milestone scope review', async () => {
    await open({ ...quoted, version: 5, status: 'awaiting_customer_review', scopeFrozen: true, milestones: { review: 'ready_for_review' }, revisionsUsed: 1 });
    fireEvent.change(screen.getByLabelText('Review, revision or withdrawal note'), { target: { value: 'Reviewed exact scope' } });
    expect(screen.getByRole('button', { name: 'Request included revision' })).toBeDisabled();
    fetcher.mockResolvedValueOnce(response({ project: { ...quoted, version: 6, status: 'scope_reviewed', scopeFrozen: true, milestones: { review: 'approved' }, revisionsUsed: 1 } }));
    fireEvent.click(screen.getByRole('button', { name: 'Approve milestone scope review' }));
    await screen.findByText('Scope review recorded. This project has no paid completion or deliverable-access authority.');
    expect(JSON.parse(fetcher.mock.calls[1][1].body)).toMatchObject({ action: 'approve_milestone', expectedVersion: 5, milestoneId: 'review', reason: 'Reviewed exact scope' });
  });

  it('scrubs the private mounted journey after access denial or page departure', async () => {
    await open();
    fetcher.mockResolvedValueOnce(response({}, 403));
    fireEvent.click(screen.getByRole('button', { name: 'Refresh projects' }));
    await waitFor(() => expect(screen.queryByText('Private buyer brief')).not.toBeInTheDocument());
    expect(screen.queryByLabelText('Submit a private service brief')).not.toBeInTheDocument();
    fireEvent(window, new Event('pagehide'));
    expect(screen.queryByText('Private answer')).not.toBeInTheDocument();
  });
});
