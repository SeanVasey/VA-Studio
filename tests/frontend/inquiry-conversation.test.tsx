import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ContactInquiryForm } from '../../resources/js/components/ContactInquiryForm';
import { InquiryConversation } from '../../resources/js/components/InquiryConversation';
import Editorial from '../../resources/js/Pages/Editorial';
import { defaultSiteContent, type EditorialDescriptor } from '../../resources/js/lib/site-content';
import type { PageMetadata } from '../../resources/js/lib/catalog';

vi.mock('../../resources/js/components/MetadataHead', () => ({ MetadataHead: () => null }));

const receipt = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
const key = '11111111-2222-4333-8444-555555555555';
const at = '2026-10-06T01:00:00.000000Z';
const snapshot = (changes = {}) => ({ receipt, state: 'read', subject: 'Synthetic subject', original: { message: 'Original private question', createdAt: at },
  messages: [{ id: 1, sender: 'staff', message: 'Synthetic staff reply', createdAt: at }], canReply: true, ...changes });
const response = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status });
const mount = () => render(<InquiryConversation receipt={receipt} onClose={vi.fn()} />);
const followUp = () => { fireEvent.change(screen.getByLabelText('Follow-up message'), { target: { value: 'Synthetic follow-up' } }); fireEvent.click(screen.getByRole('button', { name: 'Send follow-up' })); };
beforeEach(() => { document.head.innerHTML = '<meta name="csrf-token" content="synthetic-csrf">'; vi.spyOn(crypto, 'randomUUID').mockReturnValue(key); });
afterEach(() => { document.head.innerHTML = ''; document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/'; vi.restoreAllMocks(); });

describe('private inquiry conversation', () => {
  it('offers receipt-only reading on published contact when collection is disabled, without enabling other routes or previews', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(snapshot({ canReply: false })));
    const editorial: EditorialDescriptor = { section: 'contact', kind: 'page', path: '/contact', title: 'Contact', description: 'Synthetic contact.', paragraphs: [], entries: [], email: null, contactHref: null, video: null };
    const metadata: PageMetadata = { title: 'Contact', description: 'Synthetic contact.', canonicalUrl: 'https://audio.example.test/contact', imageUrl: 'https://audio.example.test/image.jpg', imageAlt: 'Synthetic artwork', type: 'website', robots: 'noindex, nofollow' };
    const view = render(<Editorial siteContent={defaultSiteContent} editorial={editorial} metadata={metadata} />);
    expect(screen.queryByRole('button', { name: 'Send inquiry' })).not.toBeInTheDocument(); expect(fetcher).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Inquiry receipt'), { target: { value: receipt } });
    await userEvent.click(screen.getByRole('button', { name: 'Open conversation' })); await screen.findByText('Synthetic staff reply');
    expect(screen.queryByLabelText('Follow-up message')).not.toBeInTheDocument();
    view.rerender(<Editorial siteContent={defaultSiteContent} editorial={editorial} metadata={metadata} sitePreview />);
    expect(screen.queryByLabelText('Inquiry receipt')).not.toBeInTheDocument(); expect(screen.queryByText('Synthetic staff reply')).not.toBeInTheDocument();
    view.rerender(<Editorial siteContent={defaultSiteContent} editorial={{ ...editorial, section: 'about', path: '/about' }} metadata={metadata} />);
    expect(screen.queryByLabelText('Inquiry receipt')).not.toBeInTheDocument(); expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('opens a newly saved receipt only after the visitor asks to read replies', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ state: 'saved', receipt }, 201)).mockResolvedValueOnce(response(snapshot()));
    render(<ContactInquiryForm privacyNotice="Synthetic privacy notice" noticeToken={'a'.repeat(64)} />);
    for (const [name, value] of Object.entries({ Name: 'Synthetic visitor', Email: 'visitor@example.test', Subject: 'Synthetic subject', Message: 'Original private question' })) {
      fireEvent.change(screen.getByLabelText(new RegExp(`^${name}`)), { target: { value } });
    }
    await userEvent.click(screen.getByRole('button', { name: 'Send inquiry' })); await screen.findByText('Inquiry saved');
    expect(fetcher).toHaveBeenCalledTimes(1);
    await userEvent.click(screen.getByRole('button', { name: 'Read replies and follow up' })); await screen.findByText('Synthetic staff reply');
    expect(fetcher).toHaveBeenCalledTimes(2);
    await userEvent.click(screen.getByRole('button', { name: 'Back to contact' }));
    expect(screen.getByText('Inquiry saved')).toBeVisible(); expect(screen.queryByText('Synthetic staff reply')).not.toBeInTheDocument();
  });

  it('opens a retained receipt only by explicit intent without storing it or private draft content', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(snapshot())); const write = vi.spyOn(Storage.prototype, 'setItem');
    render(<ContactInquiryForm privacyNotice="Synthetic privacy notice" noticeToken={'a'.repeat(64)} />);
    expect(fetcher).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Inquiry receipt'), { target: { value: receipt } });
    fireEvent.click(screen.getByRole('button', { name: 'Open conversation' }));
    await screen.findByText('Synthetic staff reply');
    expect(fetcher).toHaveBeenCalledWith('/contact/inquiries/' + receipt + '/conversation', expect.objectContaining({ cache: 'no-store', redirect: 'error', credentials: 'same-origin' }));
    expect(fetcher.mock.calls[0][1]?.body).toBeUndefined(); expect(write).not.toHaveBeenCalled();
    expect(screen.getByRole('status')).toHaveFocus();
  });

  it('renders staff and visitor text escaped with clear sender and session boundaries', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(snapshot({ messages: [{ id: 1, sender: 'staff', message: '<img src="https://example.test/tracker">', createdAt: at }] })));
    mount(); await screen.findByText('<img src="https://example.test/tracker">');
    expect(document.querySelector('img,iframe')).toBeNull(); expect(screen.getByText('VASEY.AUDIO reply')).toBeVisible();
    expect(screen.getByText(/Messages are not emailed/)).toBeVisible(); expect(screen.getByText('Original private question')).toBeVisible();
  });

  it('retries exactly the uncertain body and key even after a later validation rejection or archived refresh', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(snapshot())).mockRejectedValueOnce(new Error('private/path'))
      .mockResolvedValueOnce(response({ code: 'INQUIRY_VALIDATION_FAILED' }, 422)).mockResolvedValueOnce(response(snapshot({ state: 'archived', canReply: false })))
      .mockResolvedValueOnce(response({ state: 'saved', messageId: 2 }, 200)).mockResolvedValueOnce(response(snapshot({ state: 'archived', canReply: false,
        messages: [{ id: 1, sender: 'staff', message: 'Synthetic staff reply', createdAt: at }, { id: 2, sender: 'you', message: 'Synthetic follow-up', createdAt: at }] })));
    mount(); await screen.findByText('Synthetic staff reply'); followUp();
    await screen.findByRole('button', { name: 'Retry same follow-up' });
    expect(screen.getByLabelText('Follow-up message')).toHaveAttribute('readonly'); expect(screen.getByRole('alert')).not.toHaveTextContent('private/path');
    const body = fetcher.mock.calls[1][1]?.body;
    await userEvent.click(screen.getByRole('button', { name: 'Retry same follow-up' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same follow-up' })).toBeEnabled());
    await userEvent.click(screen.getByRole('button', { name: 'Refresh conversation' }));
    await screen.findByText('Inquiry archived'); expect(screen.getByLabelText('Follow-up message')).toHaveValue('Synthetic follow-up');
    await userEvent.click(screen.getByRole('button', { name: 'Retry same follow-up' }));
    await screen.findByText('Your follow-up');
    expect(fetcher.mock.calls.filter(([, init]) => init?.method === 'POST').map(([, init]) => init?.body)).toEqual([body, body, body]);
    expect(JSON.parse(body as string)).toEqual({ message: 'Synthetic follow-up', requestKey: key });
    expect(screen.queryByLabelText('Follow-up message')).not.toBeInTheDocument();
  });

  it('keeps definitive first validation rejection editable and starts a fresh request only on explicit retry', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(snapshot())).mockResolvedValueOnce(response({ code: 'INQUIRY_VALIDATION_FAILED' }, 422));
    mount(); await screen.findByText('Synthetic staff reply'); followUp();
    await screen.findByText(/Your follow-up was not saved/);
    expect(screen.getByLabelText('Follow-up message')).not.toHaveAttribute('readonly'); expect(screen.getByLabelText('Follow-up message')).toHaveValue('Synthetic follow-up');
    expect(screen.getByRole('alert')).toHaveFocus();
  });

  it('does not turn an acknowledged saved message into an uncertain POST after readback failure', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(snapshot())).mockResolvedValueOnce(response({ state: 'saved', messageId: 2 }, 201)).mockRejectedValueOnce(new Error('private failure'))
      .mockResolvedValueOnce(response(snapshot({ messages: [{ id: 2, sender: 'you', message: 'Synthetic follow-up', createdAt: at }] })));
    mount(); await screen.findByText('Synthetic staff reply'); followUp();
    await screen.findByText(/Your follow-up was saved, but/); expect(screen.queryByRole('button', { name: 'Retry same follow-up' })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Refresh conversation' })); await screen.findByText('Your follow-up');
    expect(fetcher.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(1);
    expect(screen.getByLabelText('Follow-up message')).toHaveValue('');
  });

  it.each([{ receipt: key }, { messages: [{ id: 1, sender: 'staff', message: 'one', createdAt: at }, { id: 1, sender: 'you', message: 'two', createdAt: at }] }, { state: 'archived', canReply: true }])('refuses an inconsistent private projection: %j', async changes => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(snapshot(changes)));
    mount(); await screen.findByRole('alert'); expect(screen.queryByText('Original private question')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Follow-up message')).not.toBeInTheDocument();
  });

  it('clears previously visible private content when refresh finds a foreign or lost session', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(snapshot())).mockResolvedValueOnce(response({ code: 'INQUIRY_UNAVAILABLE' }, 404));
    mount(); await screen.findByText('Synthetic staff reply'); await userEvent.click(screen.getByRole('button', { name: 'Refresh conversation' }));
    await screen.findByText(/A receipt alone does not grant access/); expect(screen.queryByText('Synthetic staff reply')).not.toBeInTheDocument();
  });

  it('uses refreshed CSRF cookie and preserves an expired-session draft without browser storage', async () => {
    document.cookie = 'XSRF-TOKEN=renewed%2Btoken; Path=/';
    const write = vi.spyOn(Storage.prototype, 'setItem');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(snapshot())).mockResolvedValueOnce(response({}, 419));
    mount(); await screen.findByText('Synthetic staff reply'); followUp();
    await screen.findByRole('link', { name: 'Open contact in a new tab' });
    expect(fetcher.mock.calls[1][1]?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'renewed+token' });
    expect(screen.getByLabelText('Follow-up message')).toHaveValue('Synthetic follow-up'); expect(write).not.toHaveBeenCalled();
  });
});
