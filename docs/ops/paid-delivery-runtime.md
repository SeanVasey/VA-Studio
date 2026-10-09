# Paid delivery runtime requirements (condition C11)

Status: binding deployment requirements for the paid-license family (`routes/paid-grants.php`) before it is mounted. The
production host is still undecided (U-02 in `docs/architecture/decision-register.md`), so this file states the
requirements and reference settings. It does not configure any host. Nothing here authorizes mounting, live payments or
a production deployment.

Sources: Paid252 independent review addenda 9 and 10 (A9-I6, C11 (a)–(e)) and Codex rounds 13, 14 and 16
(`docs/verification/paid252-composition-20261007/`), plus Codex round 23 (C11 (k)).

## Why these settings matter

- A paid download streams an exact private snapshot through PHP. If a slow client stops reading, PHP's write blocks.
  PHP cannot interrupt that write, so its spool slot (one of three) is released only when the worker exits. The `flock`
  is dropped by the kernel.
- Preparing an order renders each original and re-hashes up to 10 lines of up to three 1 GiB assets. Each line has its
  own 300 s bound, so one document request can legitimately run for close to an hour in the worst case.
- If a proxy or worker timeout is shorter than the legitimate path, the server can still commit a redemption while the
  customer gets a 504. That attempt is consumed without delivering the file. If the timeout is longer than necessary, a
  stuck worker keeps a spool slot for that long.

## Time bounds the settings must cover

Defaults come from `config/paid-grants.php`. Maxima are the values `PaidGrantPolicy` accepts.

| Route | Path before the first byte | Whole request | Defaults | Validated maxima |
| --- | --- | --- | --- | --- |
| `POST /paid-grants/authorizations/{id}/redeem` | locate + frame 1 ≤ 60 s, snapshot ≤ `snapshot_seconds`, frame 2 ≤ 60 s | + `transfer_max_seconds` | first byte ≤ 420 s; whole ≤ 7,620 s | first byte ≤ 1,920 s; whole ≤ 16,320 s |
| `POST /paid-grants/origins/{batch}/document` | the whole request (JSON answer at the end) | claim loop ≤ 300 s + last claimed line ≤ 300 s + completion (10 × 300 s + 60 s) | ≤ 3,660 s | same (fixed by `LEASE_SECONDS`) |
| All other paid routes | — | ≤ 60 s budget + 5 s session-lock wait | ≤ 65 s | same |

## Requirements

1. **Dedicated PHP-FPM pool** (or the host's equivalent) for the two long routes above. All other routes stay on the
   normal pool with its normal short timeout. A pool-wide two-hour limit must never apply to the whole application.
   - `request_terminate_timeout` at least the "Whole request" bound plus a margin. That is 7,800 s at the defaults; raise
     it with `transfer_max_seconds` or `snapshot_seconds`.
   - `php_admin_value[max_execution_time] = 0` in that pool, or a value above the same bound. Hashing is CPU time, so a
     default 30 s limit would cut off the snapshot and completion on large files.
   - `pm.max_children` sized for the three spool slots plus concurrent document requests. Paid preparation runs at most
     one heavy step per buyer account at a time (condition C12).
2. **Proxy read timeout per route.**
   - `redeem`: `fastcgi_read_timeout` (or equivalent) at least the first-byte bound plus a margin: 480 s at the
     defaults. After the first byte, the per-write timeout applies.
   - `document`: at least the whole-request bound plus a margin: 3,900 s.
   - nginx's 60 s default is too short for both.
   - The `redeem` bound starts when PHP begins the controller, but the proxy's clock starts earlier. Before
     `redeem()` runs there is the session-lock wait (up to 5 s) and the customer identity proof. The proof has no
     deadline of its own. On MySQL it issues 4 × (9 + k) `SELECT … FOR UPDATE` reads, where k is the account's
     verification count (1 to 128): 40 to 548 locking reads. Each also runs schema checks whose metadata-lock waits
     are bounded by `lock_wait_timeout` (MySQL default one year), not `innodb_lock_wait_timeout` (review A15-L1).
     Redemption is judged at the request's start, so a slow proof no longer refuses a token that was live on arrival
     (Codex round 23). But if the proof plus the first-byte path outlasts the proxy timeout, the server can record the
     attempt while the customer gets a 504. Lowering `innodb_lock_wait_timeout` alone cannot close this: at its 1 s
     minimum the worst case is still 548 s. Before mount, add an explicit budget measured from the captured request
     start and checked before anything is recorded (before `locate()` and again before the commit frame inserts the
     attempt). If database timeouts are also used, set both `lock_wait_timeout` and `innodb_lock_wait_timeout` on the
     paid connection, sized for 4 × (9 + 128) statements (condition C11 (k), open for Sean).
3. **Proxy response buffering on** for `redeem`, with a temp-file cap at least the largest deliverable. With nginx that
   means `fastcgi_buffering on` and `fastcgi_max_temp_file_size 1024m` (1 GiB, `DeliveryAssetFiles::MAX_BYTES`). PHP
   then finishes writing at disk speed and releases its spool slot, while the proxy serves slow clients.
4. **Private proxy temp storage.**
   - `fastcgi_temp_path` holds a second copy of private masters while they are buffered. It must be outside every public
     web root, writable only by the proxy user (mode 0700), and on encrypted storage if the private disk is encrypted.
   - It must be sized for concurrent transfers: at least 3 × 1 GiB plus margin.
   - It must never be backed up, synced or exposed.
5. **Private storage throughput floor.**
   - First preparation of a line renders its original (about 65–76 s measured natively) and hashes all of the line's
     assets within one 300 s claim. That is up to three files of 1 GiB each.
   - The private storage holding masters and stems must therefore sustain sequential reads well above 14 MiB/s per
     request. Below that, a maximum-size line can never finish, and each try spends one of its five attempts.
   - Target at least 100 MiB/s. That also keeps a 10-line completion check (up to 30 GiB) within the document-route
     bound.
   - Measure it on the chosen host before mount. This replaces a size-derived lease, which would lengthen every
     crash-recovery wait and the per-buyer heavy-work lock (Codex round 18).
6. **Shared, lock-capable cache store.** The per-buyer heavy-work lock (condition C12) is an atomic cache lock. It only
   works if every web host uses the same lock-capable store. `CACHE_STORE=database` (the default, using the migrated
   `cache_locks` table) or a shared Redis qualifies. `file`, `array` or `null`, or a per-host store, silently disables
   the bound (review A11-I2). Correctness does not depend on it: claims, leases and unique keys still decide every write.
7. **Bounded queueing before PHP starts.** Time a redeem spends waiting in the FPM `listen` backlog, or in a proxy queue,
   comes out of the authorization lifetime. The server compares expiry with the moment PHP begins the request, so it
   cannot see that wait (review A11-L2/(h)).
   - Bound it: set `listen.backlog` on the paid pool, and give the proxy short connect and queue timeouts for these
     routes, so an overloaded pool refuses quickly instead of queueing past the lifetime.
   - Size `pm.max_children` so the expected concurrent redeems and document requests do not queue.
8. **Host-local spool with coherent locking.** The private delivery spool (`paid-spool/`) must be on a filesystem local to
   each web host, where `flock` is coherent. Slot leases, the one-slot-per-buyer holder check, the admission lock and
   crash recovery of a slot all depend on it. A network filesystem with host-local or emulated locks would let two hosts
   use the same slot (review addendum 12, C11 (i)).
9. **Per-request start time.** Redeem admission reads the server's request-start time (`REQUEST_TIME_FLOAT`), clamped to
   the last 60 s. Under PHP-FPM it is set per request. If a persistent-worker runtime (for example Octane) is ever used,
   verify that the value is still per request before mounting (review addendum 12, C11 (j)).
10. **Client timeouts match.** The page already allows 320 s for a document request and 80 s for other paid requests.
   Long preparations continue in the background of the same request: the page polls saved status and continues,
   condition C13. So a client timeout never needs to reach these server bounds.

## Reference configuration (nginx + PHP-FPM, illustrative)

Adjust paths, sockets and users to the chosen host. These blocks are examples only; nothing in the repository applies
them.

```nginx
# Long paid routes go to a dedicated FPM pool; everything else keeps the default pool and timeouts.
location ~ "^/paid-grants/authorizations/[0-9a-f-]{36}/redeem$" {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
    fastcgi_pass unix:/run/php/paid-delivery.sock;
    fastcgi_read_timeout 480s;           # first byte: 60 + snapshot_seconds + 60, plus margin
    fastcgi_buffering on;
    fastcgi_max_temp_file_size 1024m;    # >= largest deliverable (1 GiB)
    fastcgi_temp_path /var/lib/nginx/paid-delivery-temp 1 2;   # private, 0700, never public or backed up
}
location ~ "^/paid-grants/origins/[0-9a-f-]{36}/document$" {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
    fastcgi_pass unix:/run/php/paid-delivery.sock;
    fastcgi_read_timeout 3900s;          # whole request: 300 + 300 + 10 x 300 + 60, plus margin
}
```

```ini
; /etc/php/8.4/fpm/pool.d/paid-delivery.conf
[paid-delivery]
user = app
group = app
listen = /run/php/paid-delivery.sock
pm = static
pm.max_children = 8
request_terminate_timeout = 7800s
php_admin_value[max_execution_time] = 0
```

## Verification before mount

Record the results in the activation packet (`docs/ops/production-activation-packet.md`):

- A synthetic 1 GiB redemption through the real proxy completes. A client that stops reading for longer than the proxy
  send timeout is disconnected, and its spool slot is free again within the worker timeout.
- Sequential read throughput of the private storage is measured and meets requirement 5.
- The cache store is shared and lock-capable across every web host (requirement 6).
- The spool directory is on a host-local filesystem with coherent `flock` on each web host (requirement 8).
- Under a burst of concurrent redeems, no request waits in the FPM backlog or a proxy queue longer than its bound
  (requirement 7).
- A synthetic multi-line preparation longer than 320 s completes. The page shows progress and ends with the order
  fulfilled, without a manual retry.
- A real worst-case completion on the chosen storage is timed. The page follows a request for 320 s plus its wait
  window (`paidContinuation.wait` in `PaidGrantJourney.tsx`), within its cap of 20 document requests per click. When
  the long request is the page's own, the cap can end the wait earlier, about 620 s after the click. A completion longer
  than that needs a later click, so confirm it fits (review A11-I4, A12-I3).
- The proxy temp directory is not reachable over HTTP and holds nothing after the transfers finish.
- An explicit identity-proof budget, measured from the request start and checked before anything is recorded, keeps
  the proof plus the first-byte path inside the `redeem` proxy timeout. Database timeouts alone do not: record
  `lock_wait_timeout`, `innodb_lock_wait_timeout` and the 4 × (9 + k) locking-read count if they are also used (C11 (k),
  review A15-L1).
