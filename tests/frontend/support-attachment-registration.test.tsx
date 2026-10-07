import type { ReactNode } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import PrivateSupportAttachments from '../../resources/js/Pages/PrivateSupportAttachments';
import ServiceProjects from '../../resources/js/Pages/ServiceProjects';
import Editorial from '../../resources/js/Pages/Editorial';
import { defaultSiteContent, type EditorialDescriptor } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';
import type { ServiceProject, ServiceProjectIndex } from '../../resources/js/components/services/ServiceProjectJourney';

vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a> }));
vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));
afterEach(() => vi.restoreAllMocks());
const id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const reply = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
const empty = { sourceVersion: 0, canIntake: true, attachments: [], policy: { maxBytes: 5242880, maxFiles: 10, retentionSeconds: 86400, provenance: 'synthetic_technical_policy' } };

it('mounts the actual private page with its server audience and closes an old pending request on fresh scope', async () => {
  let finish!: (value: Response) => void;
  const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
  const view = render(<PrivateSupportAttachments sourceId={id} sourceKind="project" audience="operator" renderScope={'a'.repeat(32)} />);
  expect(fetcher).not.toHaveBeenCalled();
  expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute('href', '/admin');
  fireEvent.click(screen.getByRole('button', { name: 'Open private attachments' }));
  expect(fetcher.mock.calls[0][0]).toBe(`/private-support/operator/projects/${id}/attachments`);
  const signal = fetcher.mock.calls[0][1]?.signal as AbortSignal;
  view.rerender(<PrivateSupportAttachments sourceId={id} sourceKind="project" audience="operator" renderScope={'b'.repeat(32)} />);
  expect(signal.aborted).toBe(true);
  await act(async () => finish(reply(empty)));
  expect(screen.queryByLabelText('Choose private file')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Open private attachments' })).toBeVisible();
  expect(fetcher).toHaveBeenCalledTimes(1);
});

it.each([false, true])('offers service attachments only after an authorized project load and explicit server flag %s', async enabled => {
  const project: ServiceProject = { id, title: 'Synthetic registered service', submittedAt: '2026-10-07 00:00:00', version: 0, status: 'submitted', testOnly: true, summary: 'Private registered brief', answers: [], quoteId: null, quoteHash: null, quotes: [], milestones: {}, revisionsUsed: 0, scopeFrozen: false, paymentState: 'not_collected', deliveryAuthorized: false, history: [] };
  const initial: ServiceProjectIndex = { schema: 1, testOnly: true, services: [], projects: [project] };
  const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(reply({ project })).mockResolvedValueOnce(reply({}, 403));
  render(<ServiceProjects initial={initial} attachmentsEnabled={enabled} />);
  expect(screen.queryByRole('link', { name: 'Open private attachment files' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: /Synthetic registered service · submitted/ }));
  await screen.findByText('Private registered brief');
  if (enabled) expect(screen.getByRole('link', { name: 'Open private attachment files' })).toHaveAttribute('href', `/private-support/projects/${id}/attachments/view`);
  else expect(screen.queryByRole('link', { name: 'Open private attachment files' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Refresh projects' }));
  await waitFor(() => expect(screen.queryByText('Private registered brief')).not.toBeInTheDocument());
  expect(screen.queryByRole('link', { name: 'Open private attachment files' })).not.toBeInTheDocument();
  expect(fetcher).toHaveBeenCalledTimes(2);
});

it.each([false, true])('passes the contact attachment flag through receipt-only conversation reading %s', async enabled => {
  const editorial: EditorialDescriptor = { section: 'contact', kind: 'page', path: '/contact', title: 'Contact', description: 'Synthetic contact.', paragraphs: [], entries: [], email: null, contactHref: null, video: null };
  const metadata: PageMetadata = { title: 'Contact', description: 'Synthetic contact.', canonicalUrl: 'https://audio.example.test/contact', imageUrl: 'https://audio.example.test/image.jpg', imageAlt: 'Synthetic artwork', type: 'website', robots: 'noindex, nofollow' };
  const at = '2026-10-07T00:00:00.000000Z';
  vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(reply({ receipt: id, state: 'read', subject: 'Synthetic subject', original: { message: 'Original private question', createdAt: at }, messages: [{ id: 1, sender: 'staff', message: 'Synthetic registered reply', createdAt: at }], canReply: false }));
  render(<Editorial siteContent={defaultSiteContent} editorial={editorial} metadata={metadata} supportAttachmentsEnabled={enabled} />);
  expect(screen.queryByRole('link', { name: 'Open private attachment files' })).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Inquiry receipt'), { target: { value: id } });
  fireEvent.click(screen.getByRole('button', { name: 'Open conversation' }));
  await screen.findByText('Synthetic registered reply');
  if (enabled) expect(screen.getByRole('link', { name: 'Open private attachment files' })).toHaveAttribute('href', `/private-support/inquiries/${id}/attachments/view`);
  else expect(screen.queryByRole('link', { name: 'Open private attachment files' })).not.toBeInTheDocument();
});
