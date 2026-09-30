# D-24 — Scheduled whole-release publication

Status: **Implemented WP-09 contract within Sean's continuous-development authorization, 2026-09-29; acceptance is recorded against the final tested commit in the integrating PR and [issue #9](https://github.com/VASEYDEV/VASEYAUDIO/issues/9).** Sean set the order after [PR #74](https://github.com/VASEYDEV/VASEYAUDIO/pull/74) merged: scheduled publication first, then editable asset references. He approved the defaults below on the same day. This does not configure a production scheduler (the host is undecided, U-02), publish production content or complete WP-09.

## Context and choice

[D-21](D-21-site-content-releases.md) and [D-23](D-23-editorial-content.md) activate a saved release only when staff confirm it. A launch or campaign then needs an operator present at the moment it goes live. Scheduling lets staff choose one saved release and a future time. At that time a server-side runner activates it through the same guarded path as **Publish release**.

| Option | Assessment |
| --- | --- |
| Staff publish by hand at the time | No new surface, but the change depends on someone being available at that minute. |
| One delayed queue job per schedule | Durability and visibility depend on worker configuration. A lost or duplicated job is hard to audit, and cancellation needs job bookkeeping. |
| Retained schedule row, run every minute through the existing activation | Chosen: the database row is the source of truth and it can be cancelled. Every outcome is retained. The D-21 singleton lock and expected revision serialize it against staff actions. |
| Multiple queued or chained schedules, per-section or per-entry timing | Deferred: mixed-version states and chained revision expectations need their own contract. |

This schedules a complete site release. Scheduling individual **track** releases (feature-parity FP-032) is a separate catalog feature and is not part of this decision.

## Approved defaults

1. **Manual publication supersedes the schedule.** Publishing or restoring any release while a schedule is pending cancels that schedule in the same transaction and records it as superseded. The confirmation warns first. Otherwise a schedule approved against an older live site could overwrite a newer fix.
2. **One pending schedule site-wide.** For a campaign start and end, schedule the end after the start has gone live.
3. **A 60-minute grace window.** If no runner reaches a schedule within 60 minutes of its time, it expires unpublished. After an outage, late publication fails safe rather than surprising anyone.
4. **Whole UTC minutes, at least one minute and at most 365 days ahead.**
5. **UTC, as elsewhere in the admin.** Offer availability windows already use UTC fields. The schedule form shows the current UTC time beside the field. An admin-wide display timezone could be a separate follow-up.

## Persistence contract

Migration `2026_09_29_000026_site_publication_schedules.php` adds one table. It changes no existing table, trigger or row.

| Column | Contract |
| --- | --- |
| `release_id`, `release_content_hash` | The exact saved release and the SHA-256 hash it had when scheduled. |
| `publish_at` | UTC time on a whole minute, later than `created_at`. |
| `expected_revision` | The publication revision the operator reviewed. Scheduling does not move the revision. |
| `created_by`, `created_at` | The scheduling administrator and time. |
| `state`, `outcome` | `pending`, then exactly one terminal state with its reason, as below. |
| `pending_slot` | `1` while pending, otherwise NULL. A UNIQUE index allows at most one pending schedule on both engines. |
| `resolved_at`, `resolved_by` | When it was resolved, and the staff member for cancellation or supersession. It is NULL when the runner decided. |
| `publication_revision` | The history revision that published or superseded it. |

| Terminal state | Outcome codes | Evidence the database requires |
| --- | --- | --- |
| `published` | `published` | A `publish` history row at `expected_revision + 1` for this release and hash, attributed to `created_by`. The pointer must be at that revision and release. Resolution must fall within the grace window. |
| `cancelled` | `cancelled` | The staff resolver; no publication revision. |
| `superseded` | `manual_publish`, `manual_rollback` | The staff resolver, and a later history row by that resolver whose operation matches the outcome. The pointer must be at that revision. |
| `failed` | `actor_unauthorized`, `stale_revision`, `integrity` | No resolver or revision, resolved at or after `publish_at`. |
| `expired` | `grace_expired` | No resolver or revision, resolved more than 60 minutes after `publish_at`. |

SQLite and MySQL triggers forbid deletes. An insert must be `pending`, carry no resolution fields, and match the release's stored hash and the current pointer revision; the release must not be active. An update may only move a pending row to one of the terminal shapes above: identity columns are unchanged, `pending_slot` is cleared and `resolved_at` is not earlier than `created_at`. The model also refuses updates to resolved rows and all deletes. `down()` refuses to drop a populated table. These guards back up the application boundary; they do not replace it or stop a privileged database administrator.

A scheduled activation appends an ordinary `publish` history row attributed to the scheduling administrator. Its `site.release.publish` audit carries `schedule_id` and `trigger: schedule`. On the first-ever publication, the original-site baseline is captured exactly as D-21 describes, with the same audit context.

## Commands and serialization

`App\Domain\SiteBuilder\SiteContent` remains the only command boundary. Each staff command reloads the actor and requires `administer-catalog`.

| Command | Behavior |
| --- | --- |
| `schedule(release, publishAt, expectedRevision, actor)` | Locks the singleton, verifies the pointer, the expected revision and the release's schema/hash, and rejects the active release, out-of-range times and a second pending schedule. Inserts the row and audits `site.schedule.created`. |
| `cancelSchedule(schedule, actor)` | Locks the singleton, then the schedule, which must still be pending. Audits `site.schedule.cancelled`. |
| `publish` / `rollback(..., expectedScheduleId)` | The confirmation captures the pending schedule it displayed. If the pending schedule differs at commit, the command is rejected. Otherwise activation supersedes the pending schedule in the same transaction and audits `site.schedule.superseded`. |
| `runDueSchedule()` | Takes no release or time input and reads the application clock. It refuses to run inside an outer transaction or for a signed-in user. After an unlocked probe it locks the singleton, then the schedule, reads the clock again and decides. |

The runner's decision, in order:

1. Not pending or not yet due: no-op.
2. More than 60 minutes late: `expired`, `grace_expired`.
3. The scheduling administrator no longer passes `administer-catalog`, or the admin panel requires MFA and the account has none enrolled: `failed`, `actor_unauthorized`. Every interactive administration request applies the same MFA rule.
4. The pointer or release fails verification, or the release hash changed: `failed`, `integrity`.
5. The revision moved, reached its ceiling, or the release is already active: `failed`, `stale_revision`.
6. Otherwise activate through the D-21 path and mark the schedule `published` with its revision. Audit `site.schedule.published`.

Failure outcomes commit their evidence without activation. An unexpected exception rolls the transaction back, so the schedule stays pending and the next minute's run retries it within the grace window.

Every command locks the singleton first and the schedule row second. Under MySQL REPEATABLE READ, the singleton locking read is each transaction's first statement, so later reads see committed state after any lock wait. Independent-process races must serialize to one of these outcomes:

| Contenders | First to lock | Other contender |
| --- | --- | --- |
| Runner and staff publish or restore | Runner publishes | The staff confirmation is rejected: the published site changed. |
| | Staff activation supersedes the schedule | The runner finds it already resolved and does nothing. |
| Two runners | One publishes | The other finds it already resolved. |
| Runner and cancellation | Runner publishes | Cancellation is rejected: already resolved. |
| | Cancellation | The runner finds it already resolved. |

## Runner and operations

`php artisan vasey:publish-scheduled-site-release` prints one line and never prints exception messages:

| Output | Exit | Meaning |
| --- | --- | --- |
| `NOTHING_DUE` | 0 | Nothing is due, or another runner already resolved it. |
| `PUBLISHED schedule=<id> revision=<n>` | 0 | The release is live. |
| `EXPIRED schedule=<id>` | 1 | The grace window passed; the site did not change. |
| `FAILED <outcome> schedule=<id>` | 1 | A recorded fail-closed outcome; the site did not change. |
| `UNAVAILABLE <exception class>` | 1 | Rolled back and reported to the private application log; still pending and retried next minute within grace. |

`routes/console.php` registers the command every minute with `withoutOverlapping(5)`. Laravel's default overlap lock lasts 24 hours, so a crashed run could otherwise block publication for a day. The database locks guarantee correctness; the overlap lock only avoids redundant runs. Production needs the standard cron entry `* * * * * php artisan schedule:run` on the chosen host. `php artisan schedule:list` shows the registration. Laravel skips scheduled commands in maintenance mode: a schedule that falls due during maintenance publishes afterwards if still within grace, and expires otherwise. `vasey:doctor` warns (`scheduled_publication`) when a pending schedule is more than two minutes overdue.

## Admin interface

The **Site content** page adds:

- a **Schedule publication** row action with a whole-minute UTC field;
- a subheading naming the pending schedule, with an overdue warning;
- a **Schedule** badge on the scheduled release;
- a **Cancel scheduled publication** confirmation naming the release and time;
- a read-only **Schedule history** of the last 20 schedules, with state, reason, actors, times and publication revision.

The publish and restore confirmations warn when they will supersede a pending schedule. Confirmations retain the revision or schedule they displayed on the locked Livewire component, and the cancel confirmation describes that captured schedule. Open schedule and cancel confirmations stay callable, so a schedule, cancellation or publication made meanwhile is reported rather than dropped silently. The existing per-request role and MFA recheck applies to every action.

## Verification, recovery and remaining scope

Required evidence:

- domain bounds, fresh authority including the runner's MFA recheck, stale and integrity rejection, supersession, cancellation and immutability;
- forged-row and transition rejection on both engines, with each publication-evidence check isolated;
- every runner outcome, the command's output and exit codes, and the scheduler registration;
- Livewire actions, including stale confirmations and the role/MFA rechecks;
- independent-process MySQL races in both lock orders;
- Chromium and WebKit scheduling, runner publication and cancellation in the real admin.

The browser run executes the production command once, through the guarded `tests/browser/run-due-site-schedule.php`. That script sets its own process clock to the pending schedule's time inside the isolated fixture only. The command and domain accept no time override. Test definitions do not establish these results; the integrating PR records the executed commands and CI.

Content recovery uses ordinary audited restoration. A wrong scheduled publication is reversed like any other publication, and the schedule's evidence is retained. Cancel any pending schedule before reverting the application to code without a runner. Code without a runner leaves the schedule pending, and a later redeployment would expire or fail it closed. Schedules change no catalog, purchase, contract, grant, entitlement or provider state.

Editable asset references are next, with their own plan. Local-time display, failure notifications to staff, chained schedules and track-release scheduling remain later work. Inbound contact delivery, consent-aware embeds, migration and production readiness remain open.

Framework references: [Laravel 13 task scheduling](https://laravel.com/docs/13.x/scheduling) (the cron entry, `withoutOverlapping` and maintenance mode) and [Laravel 13 pessimistic locking](https://laravel.com/docs/13.x/queries#pessimistic-locking). These references describe framework capabilities, not proof that this candidate passed its checks.
