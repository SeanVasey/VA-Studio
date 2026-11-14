import { execFileSync } from 'node:child_process';
import { expect, type Locator } from '@playwright/test';

/** The public contact subject is a server-listed select (BL-50); order inquiries keep a free-text subject. */
export async function fillInquiryField(field: Locator, value: string) {
  if (await field.evaluate(element => element.tagName === 'SELECT')) await field.selectOption(value); else await field.fill(value);
}

/** Text fields lock with readonly; the subject select locks with aria-readonly. */
export async function expectInquiryFieldLocked(field: Locator, locked: boolean) {
  const attribute = await field.evaluate(element => element.tagName === 'SELECT') ? 'aria-readonly' : 'readonly';
  if (locked) await expect(field).toHaveAttribute(attribute, attribute === 'readonly' ? '' : 'true');
  else await expect(field).not.toHaveAttribute(attribute, /.*/);
}

export type InquiryFixture = { previewPath: string; privacyNotice: string; values: { name: string; email: string; subject: string; message: string; website: string } };
export type OrderInquiryFixture = InquiryFixture & { orderSupport: { capability: string; items: { trackId: number; offerId: number; licenseVersionId: number; offerRevisionId: number }[]; slug: string } };
type Mode = 'prepare' | 'verify' | 'restore' | 'transport-prepare' | 'transport-rotate' | 'transport-restore' | 'conversation-prepare' | 'conversation-restore' | 'conversation-order-retain' | 'conversation-order-verify';

/** Request bodies stay on stdin; this guarded helper never retains the raw notice token. */
export function fixtureOperation(mode: Mode, project: string, receipt?: string, state?: string, body?: string) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-contact-inquiry.php', mode, project, ...(receipt ? [receipt, state!] : [])], {
    cwd: process.cwd(), env: process.env, encoding: 'utf8', timeout: 30_000, stdio: 'pipe', input: body,
  }));
}
