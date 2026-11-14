# Upstream release notes consulted

The repository vendors no changelog for Laravel, so the GitHub release pages were fetched with WebFetch. Each
page was read and summarised by the fetch tool's small model; the summaries below were then condensed by the
agent. They are a convenience read of the release titles, not an audit of the upstream diffs. No finding here
was verified against upstream source.

## laravel/framework v13.36.0 (https://github.com/laravel/framework/releases/tag/v13.36.0)

49 entries. Areas, tagged from titles only:

- Queue: worker timed-out exit code reachable (#61623); background connection in the default queue config
  (#61917); workers can flush sticky connections between jobs (#61915); `DeleteWhenMissingModels` and
  `FailOnTimeout` on queued mailables (#61899); `queue:work --json` output gains connection/queue (#61896,
  #61858); Worker no longer calls the killback twice (#61940); batched Redis queue notification pushes
  (#61965); **unique queued listener locks are released when the transaction is rolled back (#61974)**;
  UniqueFor on broadcast events (#61981).
- Database: query fixes (#61838); morph-map aliases resolved in `constrain()`, `morphWith()` and
  `loadMorph()` (#61898); PgBouncer SSL EOF recognised as a lost connection (#61918); `IsVitess()` and `olap()`
  on `MySqlConnection` (#61932); `touch()` on HasOneOrManyThrough (#61942); reference cycle from MorphTo
  soft-delete constraints (#61944); BelongsToMany return types (#61954); `Schema::getColumn()` case
  insensitive (#61967); JSON path updates lost after reading an Attribute accessor (#61962); **no recursive
  reconnect while a connection is reconnecting (#61995)**; extra attributes skipping casts on increment or
  decrement (#61991).
- Console: console kernel keeps its own commands on the kernel instance (#61931); scheduled callbacks no
  longer rethrow a previous exception on later runs (#61973); memory leak in test suites caused by Prompts
  (#61872); FrankenPHP Mercure hub in the CLI (#61977).
- Validation: rule builders for the remaining rules (#61833).
- HTTP: `eventStream()` messages containing line breaks (#61923); trusted-proxy setter cleanup (#61963);
  `TestResponse::$streamedContent` docblock (#61914).
- Other: `Notification::locale()` ignored after send (#61904) and applied to later notifications (#61959);
  JSON translation numeric keys (#61925); failover/roundrobin mailers ignoring `retry_after` (#61927); phpredis
  rebuild fixes (#61911, #61919); Blade cache file keyed by `$echoFormat` (#61822); "Prevent escaping from
  bypassing protection" (#61755); `Context::pop()` exception (#61936); `Arr::first()` short-circuit (#61957);
  contextual-binding lookup skipped when none registered (#61993); sub-second process timeouts (#61979);
  S3 credential providers (#61757).
- Sessions: no entries.

The lock diff confirms two manifest-level facts independently of the notes: 13.36.0 raises its `laravel/prompts`
requirement from `^0.3.11` to `^0.3.25` (the locked 0.3.25 already satisfies it), and its dev-only constraints
for `orchestra/testbench-core` and `phpstan/phpstan` move (not installed here).

## vite v8.3.4 (https://github.com/vitejs/vite/releases/tag/v8.3.4)

19 entries: one feature (bundled-dev `import.meta.hot.acceptExports`), fifteen fixes (HMR defaults, build
preload helper and base handling, CSS preprocessors under lightningcss, dev restart, watcher, optimizer
deep imports, static alias boundaries, Rolldown dependency updates), one performance item and one refactor.
Nothing is labelled breaking. Items 3, 4, 13 and 15 (build preloads, in-flight stylesheets, CSS caching of
chunks in a cycle, input option unescaping) can affect built output, so the production and preview builds and
the bundle scans were re-run.

## @inertiajs/react and @inertiajs/core 3.9.1 (https://github.com/inertiajs/inertia/releases/tag/v3.9.1)

Two changes: the XSRF token header is restricted to same-origin URLs (#3313), and each encrypted history entry
gets a unique IV (#3315). Neither is labelled breaking. A grep of `resources/js` and `app/Http/Middleware`
found no use of `encryptHistory` or `clearHistory`, and the app builds its own `X-XSRF-TOKEN` header in custom
`fetch` helpers, so neither change is exercised by app code. This is a text search, not a runtime proof.
The 3.9.1 core also pulls `laravel-precognition` 2.1.0 to 2.1.1.

## jsdom 30.1.0 to 30.1.2 (https://github.com/jsdom/jsdom/releases)

No 30.0.2 release exists. 30.1.0 is large (about 60 entries: performance, `getComputedStyle()`, selectors,
focus, forms, encoding). 30.1.1 and 30.1.2 are fixes (focus/blur events, `!important` handling, encoding
detection, a 30.1.0 DOM-construction slowdown, stale computed styles, memory leaks). None is labelled breaking
and none changes the minimum Node version (raised in 30.0.0). The dependency bumps that jsdom pulls in
(`@asamuzakjp/css-color` 7, `@asamuzakjp/dom-selector` 9, `data-urls` 8, `html-encoding-sniffer` 7, `tr46` 7,
`w3c-xmlserializer` 6, `whatwg-url` 17) are dev-only and are recorded in `metadata/npm-graph-delta.json`.

## @types/node 24.13.3 to 24.19.2

Type-only DefinitelyTyped patch/minor within the 24.x line that matches the Node 24 runtime. No notes were
fetched; `npm run typecheck` (TypeScript 7.0.2) is the evidence.
