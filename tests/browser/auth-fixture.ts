import { execFileSync } from 'node:child_process';

/** Browser projects share one server/IP; independent tests must not inherit login attempt counts. */
export function resetBrowserLoginRateLimit() {
  execFileSync('php', ['tests/browser/reset-login-rate-limit.php'], {
    cwd: process.cwd(), env: process.env, stdio: 'pipe', timeout: 30_000,
  });
}
