# Tooling batch commands and outcomes

Worktree `/workspace/VA-Studio-dependency-tooling`, Node 24.19.0 / npm 11.9.0. Tested dependency source
`361607b2ad2e4ca4aa3e944a857d72e1acb498a8`; subsequent merge/ledger/evidence changes do not change its graph.
All output was redirected under `/workspace/runtime/dependency-evidence/tooling/`, then archived beside this file.
Network/package commands used the normal package registry through authorized sandbox escalation and a private
cache at `/workspace/runtime/npm-cache`. Frontend tests/builds ran in the default sandbox.

| Exact command | Exit / retained output |
| --- | --- |
| `npm outdated --json --cache /workspace/runtime/npm-cache` | 1, `outdated-before.json` / `.stderr`; run before node_modules installation, so no current-version identity claim |
| `npm audit --json --audit-level=high --cache /workspace/runtime/npm-cache` | 0, `audit-before.json` / `.stderr`, zero advisories |
| `npm install --package-lock-only --ignore-scripts --save-dev vite@8.3.3 @vitejs/plugin-react@6.1.2 vitest@5.0.3 --cache /workspace/runtime/npm-cache` | 0, `update-lock.txt`; produces the selected graph |
| `npm pkg set devDependencies.vitest=5.0.3` | 0; retains the pre-existing exact root-pin policy |
| `npm install --package-lock-only --ignore-scripts --cache /workspace/runtime/npm-cache` | 0, `retain-vitest-pin.txt`; synchronizes that root constraint |
| `npm ci --include=dev --cache /workspace/runtime/npm-cache` | 0, `npm-ci.txt`; clean installation in this separate worktree |
| `npm test -- --reporter=default --reporter=junit --outputFile=/workspace/runtime/dependency-evidence/tooling/frontend.xml` | 0, `frontend.txt` / `.xml`; 65 files, 1,130 tests, zero errors/failures/skips |
| `npm run build` | 0, `build.txt`; TypeScript and production build; >500 kB chunk warning retained |
| `python3 scripts/ci/scan-client-bundle.py` | 0, `scan-production.txt`; six files, no hits |
| `npm run build:preview` | 0, `build-preview.txt`; TypeScript and preview build |
| `python3 scripts/ci/scan-client-bundle.py dist/design-preview` | 0, `scan-preview.txt`; four files, no hits |
| `npm outdated --json --include=dev --cache /workspace/runtime/npm-cache` | 1, `outdated-after.json` / `.stderr`; remaining available updates are recorded, not silently applied |
| `npm audit --json --audit-level=high --include=dev --cache /workspace/runtime/npm-cache` | 0, `audit-after.json` / `.stderr`; zero advisories |

The frontend/build/scan commands ran in one `&&` chain, whose final status was 0. No command in that chain was
skipped. JUnit was parsed independently of the terminal summary: 1,130 cases, no failure/error/skipped elements.
The installed versions of Vite, Vitest, the React plugin, Node types and Inertia React were read from their actual
node_modules package manifests and matched the lock. Every existing production npm record is byte-equivalent as
parsed JSON; `git diff --exit-code c2be3d31 -- composer.json composer.lock resources/contracts` passed.

No application test file, expected assertion, audit threshold or repository protection was changed. No PHP,
browser, native MySQL, provider, host, Foundation or GitLab result is claimed for this tooling-only graph change.
