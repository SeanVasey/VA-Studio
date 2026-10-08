# Paid252 Codex round 4: the status window cannot hide a live authorization

Development evidence from the Claude Code harness for PR #56, code commit `df9fd823`. Not Foundation or final acceptance.
Client-only change to `resources/js/components/PaidGrantJourney.tsx`; no server, schema, guard, receipt or lifetime
change, and no file pinned in `resources/contracts/*/profile-assets.json` was touched.

## Finding (Codex P2, `PaidGrantJourney.tsx:144`)

Round 3 (`../codex-3/`) enabled "Set aside" when a post-request status read showed no `unused` entry of the pending
kind. The status read lists only the newest 20 authorizations of a line, while the policy allows lifetimes up to 600 s
(`PaidGrantPolicy`, 30–600). At 3 issuances per 60 s, other tabs can push a still-live pending authorization out of
the window after about six minutes; round 3 would then allow a duplicate instead of the exact replay. Round 3's note
that this "cannot happen" assumed a 60 s lifetime and was wrong.

## Fix

The gate additionally requires the window to prove the lost authorization's state:

- **Window not full** (`history.length < historyLimit`): it holds every authorization on the line, so the absence of an
  `unused` entry of that kind is definitive, as before.
- **Window full:** set-aside is allowed only if some entry in it is `expired`. Every authorization on a line gets
  `created_at + delivery_policy.authorization_seconds` (`PaidGrantDownloads::authorize`). That policy is frozen in the
  batch payload and equality-checked on every command (`PaidGrantCommands::run`). Issuance runs under the owned-graph
  lock (`FOR UPDATE` on MySQL), so ids follow `created_at` on a line. An authorization pushed out of the window is older
  than every entry in it and expires no later than any of them, so one expired entry proves it expired.
- Otherwise only the exact retry is offered.

No server change was needed: the proof uses fields the status read already returns.

## Results (`evidence/`)

| Run | Source | Result |
| --- | --- | --- |
| Vitest, red (new test, round-3 component) | `1cd837eb` + tests | 1 failed, 14 passed, rc 1: set-aside enabled with a full window of newer unused entries (`frontend-red.txt`) |
| Vitest, green | `df9fd823` | 15 passed, rc 0 (`frontend-green.txt`) |
| `npx tsc --noEmit` | `df9fd823` | rc 0 (`tsc.txt`) |

The new case fills the window with 20 newer authorizations of another kind and checks that set-aside stays disabled
when they are `unused` or all `attempted`, and is enabled once the oldest one in the window is `expired`.

## Not tested

- No browser run (jsdom with mocked responses).
- The ordering argument relies on the server's owned-graph lock serializing issuance per batch. On SQLite, writes are
  serialized by the engine. On MySQL, the lock is the existing `FOR UPDATE` graph read; this round adds no native test of it.
