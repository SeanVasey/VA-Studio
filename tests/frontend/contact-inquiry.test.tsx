import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ContactInquiryForm } from '../../resources/js/components/ContactInquiryForm';

const privacy = 'Synthetic approved privacy notice. Messages are held privately for inquiry handling.';
const receipt = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
const firstKey = '11111111-2222-4333-8444-555555555555';
const nextKey = '66666666-7777-4888-8999-aaaaaaaaaaaa';
const draft = { name: 'Synthetic visitor', email: 'visitor@example.test', subject: 'Synthetic collaboration', message: 'Synthetic private inquiry.', website: '' };
const response = (status: number, data: unknown) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
const saved = (status = 201) => response(status, { state: 'saved', receipt });
function form() { return render(<ContactInquiryForm privacyNotice={privacy} />); }
function fill(values: Partial<typeof draft> = {}) {
  for (const [name, value] of Object.entries({ ...draft, ...values })) {
    if (name === 'website') continue;
    fireEvent.change(screen.getByLabelText(new RegExp(`^${name}`, 'i')), { target: { value } });
  }
}
function send() { fireEvent.click(screen.getByRole('button', { name: /^(Send inquiry|Retry same inquiry)$/ })); }

beforeEach(() => {
  document.head.innerHTML = '<meta name="csrf-token" content="synthetic-csrf">';
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/';
  vi.spyOn(crypto, 'randomUUID').mockReturnValueOnce(firstKey).mockReturnValue(nextKey);
});
afterEach(() => { document.head.innerHTML = ''; document.cookie = 'XSRF-TOKEN=; Max-Age=0; Path=/'; vi.useRealTimers(); });

describe('private contact inquiry', () => {
  it('does not offer collection without a nonempty approved privacy notice', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch'); render(<ContactInquiryForm privacyNotice="   " />);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument(); expect(fetcher).not.toHaveBeenCalled();
  });

  it('renders escaped approved privacy copy and labelled fields without network or browser storage', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    const read = vi.spyOn(Storage.prototype, 'getItem'); const write = vi.spyOn(Storage.prototype, 'setItem');
    render(<ContactInquiryForm privacyNotice={'<img src="https://example.test/tracker"> Approved synthetic notice.'} />);
    expect(screen.getByText(/<img src=/)).toBeVisible();
    expect(document.querySelector('img, iframe, script')).toBeNull();
    for (const label of ['Name', 'Email', 'Subject', 'Message']) expect(screen.getByLabelText(new RegExp(`^${label}`))).toBeRequired();
    expect(screen.queryByRole('textbox', { name: 'Website' })).not.toBeInTheDocument();
    expect(document.querySelector('[name="website"]')).toHaveAttribute('tabindex', '-1');
    expect(fetcher).not.toHaveBeenCalled(); expect(read).not.toHaveBeenCalled(); expect(write).not.toHaveBeenCalled();
  });

  it('focuses an accessible validation summary and links to invalid fields without sending', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch'); form(); send();
    expect(screen.getByRole('alert')).toHaveFocus();
    expect(screen.getByLabelText(/^Name/)).toHaveAttribute('aria-invalid', 'true');
    await userEvent.click(screen.getByRole('link', { name: 'Enter your email address.' }));
    expect(screen.getByLabelText(/^Email/)).toHaveFocus(); expect(fetcher).not.toHaveBeenCalled();
  });

  it.each([200, 201])('accepts only an explicit private saved receipt from status %s and clears the draft', async status => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(saved(status)); const write = vi.spyOn(Storage.prototype, 'setItem');
    form(); fill(); send();
    await waitFor(() => expect(screen.getByRole('heading', { name: 'Inquiry saved' })).toBeVisible());
    await waitFor(() => expect(screen.getByRole('status')).toHaveFocus()); expect(screen.getByText(receipt)).toBeVisible();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument(); expect(document.body.textContent).not.toContain(draft.email);
    expect(fetcher).toHaveBeenCalledWith('/contact/inquiries', expect.objectContaining({ method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'synthetic-csrf' }), body: JSON.stringify({ ...draft, requestKey: firstKey }) }));
    expect(write).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole('button', { name: 'Write another inquiry' }));
    await waitFor(() => expect(screen.getByLabelText(/^Name/)).toHaveFocus());
    expect(screen.getByLabelText(/^Message/)).toHaveValue('');
  });

  it('suppresses duplicate sends and keeps the submitted fields read-only while waiting', async () => {
    let resolve!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(value => { resolve = value; }));
    form(); fill(); send();
    fireEvent.submit(screen.getByRole('button', { name: 'Saving inquiry…' }).closest('form')!);
    expect(fetcher).toHaveBeenCalledTimes(1); expect(screen.getByRole('button', { name: 'Saving inquiry…' })).toBeDisabled();
    expect(screen.getByLabelText(/^Message/)).toHaveAttribute('readonly');
    fireEvent.change(screen.getByLabelText(/^Message/), { target: { value: 'Attempted changed message' } });
    expect(screen.getByLabelText(/^Message/)).toHaveValue(draft.message);
    await act(async () => resolve(saved()));
  });

  it('keeps the exact key and serialized payload through lost and malformed responses', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new TypeError('network'))
      .mockResolvedValueOnce(response(201, { state: 'saved', receipt: 'not-a-receipt', message: 'DO NOT DISPLAY SERVER DETAIL' })).mockResolvedValueOnce(saved(200));
    form(); fill({ message: '  Retain these exact characters.\nSecond line.  ' }); send();
    await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus());
    expect(screen.getByLabelText(/^Message/)).toHaveAttribute('readonly');
    send(); await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same inquiry' })).toBeEnabled());
    expect(screen.queryByText(/DO NOT DISPLAY/)).not.toBeInTheDocument(); send();
    await waitFor(() => expect(screen.getByText(receipt)).toBeVisible());
    expect(fetcher).toHaveBeenCalledTimes(3);
    const bodies = fetcher.mock.calls.map(call => call[1]?.body); expect(new Set(bodies).size).toBe(1);
    expect(JSON.parse(bodies[0] as string).message).toBe('  Retain these exact characters.\nSecond line.  ');
    expect(crypto.randomUUID).toHaveBeenCalledTimes(1);
  });

  it('rejects a receipt with trailing bytes and retains the original attempt', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(201, { state: 'saved', receipt: `${receipt}\n` })).mockResolvedValueOnce(saved(200));
    form(); fill(); send(); await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus());
    expect(screen.queryByRole('heading', { name: 'Inquiry saved' })).not.toBeInTheDocument();
    expect(screen.getByLabelText(/^Message/)).toHaveAttribute('readonly'); send();
    await waitFor(() => expect(screen.getByText(receipt)).toBeVisible());
    expect(fetcher.mock.calls[0][1]?.body).toBe(fetcher.mock.calls[1][1]?.body);
  });

  it('does not transmit a generated request key with trailing bytes', () => {
    vi.mocked(crypto.randomUUID).mockReset().mockReturnValue(`${firstKey}\n`);
    const fetcher = vi.spyOn(globalThis, 'fetch'); form(); fill(); send();
    expect(screen.getByRole('alert')).toHaveFocus(); expect(fetcher).not.toHaveBeenCalled();
  });

  it('allows a definitive validation rejection to be edited under a new key, without rendering server details', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(422, { code: 'INQUIRY_VALIDATION_FAILED', errors: { subject: ['PRIVATE SERVER DETAIL'] } })).mockResolvedValueOnce(saved());
    form(); fill(); send(); await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus());
    expect(screen.getByLabelText(/^Subject/)).not.toHaveAttribute('readonly'); expect(screen.getByLabelText(/^Subject/)).toHaveAttribute('aria-invalid', 'true');
    expect(screen.queryByText(/PRIVATE SERVER DETAIL/)).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/^Subject/), { target: { value: 'Corrected subject' } }); send();
    await waitFor(() => expect(screen.getByText(receipt)).toBeVisible());
    expect(JSON.parse(fetcher.mock.calls[1][1]?.body as string)).toEqual({ ...draft, subject: 'Corrected subject', requestKey: nextKey });
  });

  it('does not replace an uncertain original after a later validation rejection', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new TypeError('lost response'))
      .mockResolvedValueOnce(response(422, { code: 'INQUIRY_VALIDATION_FAILED', errors: { email: ['invalid'] } })).mockResolvedValueOnce(saved(200));
    form(); fill(); send(); await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same inquiry' })).toBeEnabled());
    send(); await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same inquiry' })).toBeEnabled());
    expect(screen.getByLabelText(/^Email/)).toHaveAttribute('readonly'); send();
    await waitFor(() => expect(screen.getByText(receipt)).toBeVisible());
    expect(new Set(fetcher.mock.calls.map(call => call[1]?.body)).size).toBe(1);
  });

  it.each([404, 409, 429, 503])('keeps a safe explicit retry after HTTP %s without automatic resubmission', async status => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(status, { message: 'UNTRUSTED DETAIL' })).mockResolvedValueOnce(saved(200));
    form(); fill(); send(); await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus());
    expect(fetcher).toHaveBeenCalledTimes(1); expect(screen.queryByText('UNTRUSTED DETAIL')).not.toBeInTheDocument();
    expect(screen.getByLabelText(/^Name/)).toHaveAttribute('readonly'); send();
    await waitFor(() => expect(screen.getByText(receipt)).toBeVisible());
    expect(fetcher.mock.calls[0][1]?.body).toBe(fetcher.mock.calls[1][1]?.body);
  });

  it('renews a session through a separate contact tab while preserving the exact pending inquiry', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(419, {})).mockResolvedValueOnce(saved(200));
    form(); fill(); send(); await waitFor(() => expect(screen.getByRole('link', { name: 'Open contact in a new tab' })).toBeVisible());
    expect(screen.getByRole('link', { name: 'Open contact in a new tab' })).toHaveAttribute('href', '/contact');
    expect(screen.getByRole('link', { name: 'Open contact in a new tab' })).toHaveAttribute('rel', 'noopener noreferrer');
    document.cookie = 'XSRF-TOKEN=renewed%3Dtoken; Path=/'; send();
    await waitFor(() => expect(screen.getByText(receipt)).toBeVisible());
    expect(fetcher.mock.calls[1][1]?.headers).toEqual(expect.objectContaining({ 'X-XSRF-TOKEN': 'renewed=token' }));
    expect(fetcher.mock.calls[1][1]?.body).toBe(fetcher.mock.calls[0][1]?.body);
  });

  it('never confirms or replaces the request when session renewal cannot recover its owner', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new TypeError('response lost'))
      .mockResolvedValueOnce(response(419, {})).mockResolvedValueOnce(response(409, { code: 'INQUIRY_REQUEST_CONFLICT' }));
    form(); fill(); send(); await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same inquiry' })).toBeEnabled());
    send(); await waitFor(() => expect(screen.getByRole('link', { name: 'Open contact in a new tab' })).toBeVisible());
    document.cookie = 'XSRF-TOKEN=renewed-session; Path=/'; send();
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('It has not been confirmed.'));
    expect(screen.queryByRole('heading', { name: 'Inquiry saved' })).not.toBeInTheDocument();
    expect(screen.getByLabelText(/^Message/)).toHaveAttribute('readonly');
    expect(screen.getByLabelText(/^Message/)).toHaveValue(draft.message);
    expect(new Set(fetcher.mock.calls.map(call => call[1]?.body)).size).toBe(1);
    expect(crypto.randomUUID).toHaveBeenCalledTimes(1);
  });

  it('rejects over-limit character counts and encoded body bytes before transmission', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch'); form(); fill({ name: 'N'.repeat(121) }); send();
    expect(screen.getByLabelText(/^Name/)).toHaveAttribute('aria-invalid', 'true'); expect(fetcher).not.toHaveBeenCalled();
    fill({ name: '🟦'.repeat(120), message: '🟦'.repeat(6000) }); send();
    expect(screen.getByLabelText(/^Message/)).toHaveAttribute('aria-invalid', 'true'); expect(fetcher).not.toHaveBeenCalled();
  });

  it('permits shortening a definite first body-size rejection without losing the draft', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(413, {})).mockResolvedValueOnce(saved());
    form(); fill(); send(); await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus());
    expect(screen.getByLabelText(/^Message/)).toHaveValue(draft.message); expect(screen.getByLabelText(/^Message/)).not.toHaveAttribute('readonly');
    fireEvent.change(screen.getByLabelText(/^Message/), { target: { value: 'Shorter inquiry' } }); send();
    await waitFor(() => expect(screen.getByText(receipt)).toBeVisible());
    expect(JSON.parse(fetcher.mock.calls[1][1]?.body as string).requestKey).toBe(nextKey);
  });

  it('treats a timed-out request as uncertain and aborts transport without claiming failure to save', async () => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation((_url, init) => new Promise((_resolve, reject) => init?.signal?.addEventListener('abort', () => reject(new DOMException('timeout', 'AbortError')))));
    form(); fill(); send(); await act(async () => vi.advanceTimersByTimeAsync(20_000));
    expect(screen.getByRole('alert')).toHaveTextContent('could not confirm whether your inquiry was saved');
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true); expect(screen.getByLabelText(/^Message/)).toHaveAttribute('readonly');
  });

  it('aborts transport on unmount and never writes personal data to browser storage', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}));
    const write = vi.spyOn(Storage.prototype, 'setItem'); const rendered = form(); fill(); send(); rendered.unmount();
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true); expect(write).not.toHaveBeenCalled();
  });
});
