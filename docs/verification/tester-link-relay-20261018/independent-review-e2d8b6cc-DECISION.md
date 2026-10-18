DECISION: APPROVE WITH CONDITIONS at `e2d8b6cc8e2d87d83711b2ec28c34ded8d08eaef` (branch `harness/tester-link-relay-20261018`, base `c488277c`; tested source `72dbb2eb`, and `e2d8b6cc` changes docs only)

I found no open P0 or P1. Under the freeze-and-batch rule the PR can merge at this SHA. The conditions are:
- fix P2-1 in this PR, or move it to the K3 follow-up batch;
- record P3-3 for Sean's confirmation.

## Findings

**P2-1: The tester-controlled recipient goes through Symfony's output formatter.** The recipient is printed in the table at `app/Console/Commands/TestIdentityLinks.php:124-126` and in the print warning at `:142-143`.
- RFC quoted local parts pass both `CustomerIdentityPolicy::email()` and `syntheticAddress()` (`TestIdentityLinkRelay.php:125-143`, `:350-359`). Examples: `"<fg=black;bg=black>hide</>"@example.test` and `"<href=https://e.vil>x</>"@example.test`.
- I verified this in a scratch copy with the real request service and artisan. `list` showed the recipients as `"hide"@example.test` and `"x"@example.test`.
- The decorated formatter turns these tags into invisible text and an OSC-8 hyperlink.
- Effect: the field the operator acts on can be misrepresented, different addresses can look identical, and the public staging enrol form can put a hyperlink into a staff terminal. No link or proof leaks.
- **Remedy:** apply `OutputFormatter::escape()` to every printed recipient, or limit synthetic recipients to unquoted local parts (reject `"`). Add a test.

**P3-1: A piped password is accepted.** `TestIdentityLinks.php:71` refuses only `-n`. Symfony keeps piped stdin interactive and reads the hidden answer from the pipe. I verified that `printf '<pw>\n' | artisan vasey:test-identity-links list` succeeds and `</dev/null` stops.
- So "interactive trusted console", and the attestation's `trusted_interactive_console`, are not enforced against non-TTY stdin.
- `vasey:rights-scope` and `vasey:staging-synthetic-catalog` share the same pattern.
- **Remedy:** a follow-up across all three commands, either requiring `stream_isatty(STDIN)` or correcting the wording.

**P3-2: Test gaps (these mutations survived).**
- The policy-version check (`TestIdentityLinkRelay.php:329`).
- The re-read inside the transaction (`:208-212`).
- The `lstat` mode/size check (`:379`) alone, and the inode comparison (`:388`) alone. Removing both together was caught.
- **Remedy:** add a case where the challenge completes between `message()` and `relay()`, and a case with a non-current policy version. Otherwise record these as defence in depth.

**P3-3: The policy needs the owner's confirmation, and existing accounts are stranded.**
- The reserved-domain rule is sound. It fails safe: every input I accepted ends on a reserved name.
- The packet (`README.md:68-69`) asks the *reviewer* to confirm it, but it is an operator and tester policy, so Sean should confirm it.
- Existing staging test accounts on non-reserved addresses can no longer be recovered through the relay. Runbook line 326 forbids opening captures by hand, while `docs/customer-test-self-service.md:15` still says the tester reads the capture directly.
- **Remedy:**
  - record the rule for Sean in the PR body or the Project Notes adaptations;
  - add one runbook line for these accounts: re-enrol at a reserved address, and note that claimed purchases do not move;
  - align the self-service doc with the runbook.

**P3-4: A reserved address proves nothing about who owns it.** Runbook lines 349-350 say to send the link to "the tester who asked". **Remedy:** say instead that a `reset password` link goes only to the person known to own that test account, and never in reply to an unsolicited request.

**P3-5 (no action needed):**
- If a `.env.staging` file exists on the host, `--env=staging` loads that file in full and admission passes (verified). `SyntheticRehearsalPolicy` documents the same limit: these are configuration checks, not proof of host identity. The backstop is that production cannot hold test challenges or captures.
- After `directory()` (`:282-294`), the directory could be swapped for a symlink and later opens redirected. Nothing unverified is ever printed, so this exposes nothing.
- Each capture costs two queries, and captures are never pruned. That retention predates this change.

## Assessment

1. **Admission.**
   - Production is refused before any database access. I verified this against an unreachable MySQL with: `APP_ENV=production`; `--env=production`; configured production with `--env=staging`; `APP_ENV` unset (defaults to production); and cached production config with either `--env=staging` or `APP_ENV=staging`.
   - A configured/running mismatch is refused.
   - The three flags are off by default. Only the test-commerce profile sets them, so the capture writer is on only when an operator applies that profile on purpose.
   - Nothing reads captures or challenges until admission, the password and the attestation are complete. Only the operator's own row is read before the prompt, as in the precedent commands.
2. **Operator authentication.**
   - `Hash::check` runs against the current `users.password`, the same provider the panel uses.
   - `administer-catalog` and `AdminMultiFactor` are checked before the prompt, and checked again under a lock in the audit transaction (`:270-279`).
   - A wrong password, a missing `--attest` or a non-staff account refuses before any read or audit row.
   - There is no `--password` flag, but see P3-1.
3. **Data exposure.**
   - I probed 26 inputs: case, a trailing dot, punycode, `example.com.attacker.tld`, subdomains, quoted local parts, `+` tags and Cyrillic look-alikes. Non-ASCII is rejected upstream.
   - The recipient is compared with the challenge's decrypted email (`:334-342`), not trusted from the file.
   - `list` prints only id, recipient, kind, times and status.
   - Used, expired and non-matching messages are refused.
   - The staff withhold checks every `is_admin` row, case-insensitively, verified or not.
4. **Filesystem.** These are all withheld or refused (the suite plus my probe): symlink, hardlink alias, duplicate-key JSON, non-UTF-8, oversized, traversal, and a symlinked directory.
5. **Read-only claim.**
   - Audit rows are the only writes, and they contain no link, proof or address.
   - The relay row commits before the link prints.
   - With the audit table renamed away, `print` printed a generic "Stopped:" message and no link.

## Commands and counts

- Exact-SHA worktree, SQLite in memory, `public/build` absent, run with the given env/PHPUnit command.
  - `TestIdentityLinkRelayCommandTest`: 15 tests / 1,330 assertions, OK.
  - `CustomerIdentityTest`, `CustomerIdentityHttpTest` and `CustomerIdentityKeyRotationTest`: 29 / 213, OK (17/90, 10/109, 2/14).
  - Both match the packet.
- `vendor/bin/pint --test` on the five new files passed. `git diff --check c488277c..HEAD` is clean.
- Mutations: in a scratch `git archive` copy whose autoloader resolves `App\` from the copy. The unmutated baseline passed. Each mutation ran the whole file.
  - **Caught:**
    - reserved-domain filter (2 tests failed);
    - staff withhold (1);
    - decrypted-recipient match (1);
    - proof-hash match (1);
    - expiry match (1);
    - configured/running comparison (1);
    - interactive check (1);
    - attestation requirement (1);
    - `Hash::check` (2);
    - directory-link check (1);
    - `lstat` plus inode removed together (1).
  - **Survived:** see P3-2.
- Also in scratch: artisan admission probes, the piped-password probe, the formatter probe, and an adversarial probe test (1 test / 25 assertions).

## Not verified

- Hosted staging: Forge file ownership, `sudo -u forge`, and that a real terminal's hidden prompt does not echo.
- MySQL: `LOWER(email)` and `where('email')` collation, and `lockForUpdate` under contention.
- Decorated output through the real command. Artisan here rendered undecorated even with `--ansi`, so I showed the formatter's behaviour directly.
- Integration with the current `origin/main` `9652b6de`. Only `CHANGELOG.md` overlaps.

The worktree is unchanged at `e2d8b6cc` and `git status` is clean. The regression run appended an expected line to the gitignored `storage/logs/laravel.log`. I deleted the scratch copy.
