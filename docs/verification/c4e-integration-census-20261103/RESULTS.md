# C4e focused verification and integrated census

Product-test source: `50aabd2a9a693418bd82ed5bb35f80e4bdde6084`.
Policy/discovery source: `6d96042f1b91a365af1abdee9c413e145fb8e073`.
This note records completed local development checks; nothing is live.

| Scope | Tests | Assertions |
| --- | ---: | ---: |
| SQLite: two census/parser controls, two billing fault/ownership controls, real active-PDO commit failure | 5 | 93 |
| Private Oracle MySQL 8.4.11: the two original billing fault/ownership controls | 2 | 53 |

Both selections passed with zero failures, errors, skips or warnings. The billing
fault occurs at the qualified billing-clock sample, outside protected decrypt
tamper handling. The original refusal, privacy, rollback and ownership-ordering
assertions remain. An earlier five-case attempt had two failures from the wrong
fault-injection boundary; its failed evidence remains separate and was not
accepted. The repair changes test support, not production outcome policy.

The SQLite commit control uses an actual deferred foreign-key failure originating
inside the original PDO commit, with the still-active transaction rolled back by
the product before test recovery. It does not close N4's idle-PDO commit-throws
condition. The native billing cases are not native commit-failure or concurrency
acceptance. The idle-PDO condition remains open.

Independent source review found no validated P0–P2 in the repaired test delta;
its five-case execution condition is fulfilled. ROOT independently validated
the raw JUnit, exact selected inventories, source origins, scoped probe reset,
captured exit/EOF/reap and quiet cleanup. Each native case also returned its
qualified fixture lease with all six cleanup checks passing.

The policy child preserves the other thirteen executable/source objects,
including App and tests. It adds the legitimate SQLite-only MySQL skip, selects
the shared commit-witness file, and repairs existing MIG migration-pattern and
DEFOPS relay include omissions. Independent metadata review found no P0–P2.
The SQLite census remains byte-identical, including its pinned first150 entries.
No generator guard or test was weakened.

The unchanged generator completed one full and24 shard listings at exact6d96042f:

- **10221 source cases in764 files**; **2761 selected cases in272 files**.
- All25 distinct PHP processes supplied their original argument, configuration,
  output/source, private-state and shutdown witnesses; zero Prepared test events.
- Original120-second per-listing and3600-second operator limits,512MiB memory
  and24-shard partition proof were retained.
- Generator exit0, captured streams/EOF/reap and source/vendor/quiet cleanup
  were validated. All50 generated artifacts were preserved byte-exact before
  removing those generated files from the source checkout.

Individual PHP exit/EOF/reap is source-derived from the unchanged generator's
successful subprocess completion plus all25 witnesses and no survivors.
Individual PHP raw streams were not retained; their stderr emptiness is not
claimed. Generator notices were retained. Enumeration executes no product test
bodies and supplies no new payment, concurrency, headroom or host acceptance.

The two workflow count comments now use these measured counts and retain their
historical run attribution. Workflow behavior and Foundation's manual exact-SHA
policy are unchanged. Later integration must preserve this evidence attribution;
it must not relabel these runs as tests executed on a later metadata head.
Membership mounting and production activation remain off. The required F9
security-assessment hold and ordered publication gates remain in force.
