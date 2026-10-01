import { execFileSync } from 'node:child_process';

export type InquiryFixture = { previewPath: string; privacyNotice: string; values: { name: string; email: string; subject: string; message: string; website: string } };
type Mode = 'prepare' | 'verify' | 'restore' | 'transport-prepare' | 'transport-rotate' | 'transport-restore';

/** Request bodies stay on stdin; this guarded helper never retains the raw notice token. */
export function fixtureOperation(mode: Mode, project: string, receipt?: string, state?: string, body?: string) {
  return JSON.parse(execFileSync('php', ['tests/browser/prepare-contact-inquiry.php', mode, project, ...(receipt ? [receipt, state!] : [])], {
    cwd: process.cwd(), env: process.env, encoding: 'utf8', timeout: 30_000, stdio: 'pipe', input: body,
  }));
}
