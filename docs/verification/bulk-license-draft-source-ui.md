# Reviewed bulk license source editor

This is a focused development increment for WP-04 and FP-033. It invokes the
bounded `BulkReplaceLicenseDraftSource` command without an enclosing Filament
transaction. Production license decisions, complete bulk licensing parity and
final native verification remain separate.

Select 1 to 25 editable license drafts on the current filtered page and choose
**Replace draft source**. Enter the actual source and choose **Review source
replacement**. The comparison identifies each template, version and draft ID,
shows escaped current and proposed text, and retains its structured terms and
UTC availability dates. A proposed source must validate against every retained
terms schema. Saving does not submit review, approve, publish or rewrite
previously purchased terms.

**Save reviewed source** applies only the exact captured comparison. The server
binds it to the current actor, mounted action and arguments, explicit selection,
search/filter/sort and page context. Client state cannot replace the locked
review. All-page selection, off-page IDs, duplicates, malformed IDs, extra form
fields, altered comparison text and direct processing callbacks are rejected.
The review is consumed before framework validation or visibility can return.
An exhausted comparison disables its save button while retaining the copy field.
**Back to source**, Cancel, selection changes and table changes also discard it;
only another explicit comparison can produce a fresh review.

The comparison includes a readonly, selectable **Replacement source to keep**
field. After a stale, invalid or uncertain result, keep a copy of the entered
text, then close and reopen to inspect the saved drafts before trying again.
An uncertain response may follow a committed save. The editor never reports
that nothing saved from that uncertainty, never reuses the old capture and logs
only the exception class. Successful notifications count changed and unchanged
drafts; an exact no-op writes no audit.

The existing single-draft editor and its nested economic-policy add/delete/
reorder lifecycle retain their original baseline and commands. The new focused
Livewire tests exercise both the real bulk action lifecycle and those earlier
editor regressions.

## Focused component evidence

The final executable UI checkpoint is
`06ff9b271e95ec2bb1250145273586999b5da1d5`, tree
`13d23cd7bf8b2f69e0c67e066c2b17aab1ce4254`. Both focused runs used those exact
unchanged bytes, PHP 8.4.26, and this selection:

```sh
php vendor/bin/phpunit --log-junit <receipt.xml> \
  tests/Feature/BulkLicenseDraftSourceAuthoringActionTest.php \
  tests/Feature/LicenseDraftAuthoringActionTest.php \
  tests/Feature/LicensingAdminTest.php
```

SQLite used a fresh in-memory database. The native run used a fresh disposable
MySQL 8.4.11 server over private loopback TCP, with
`innodb_flush_log_at_trx_commit=1`, `sync_binlog=1`, doublewrite enabled and binary
logging enabled; its retained server log records a complete shutdown.

| Actual selected class | Cases | Assertions, each engine |
| --- | ---: | ---: |
| BulkLicenseDraftSourceAuthoringActionTest | 39 | 679 |
| LicenseDraftAuthoringActionTest | 16 | 303 |
| LicensingAdminTest | 6 | 97 |
| Total | 61 | 1,079 |

Both engines passed the identical complete 61-case inventory with zero failures,
errors or skips. The bulk cases cover actual Filament mount, review and submit;
bounded current-page selection; actor, MFA, table and action-context changes;
raw form and direct-callback tampering; consumed validation; stale review; an
actual committed save followed by uncertain acknowledgement; no-op recovery;
copyable text, escaped comparison and Back requiring a fresh review. They also
assert the actual rendered submit child is disabled after consumption while the
readonly copy field remains enabled. The existing two regression test files,
original record actions and fields, and 13 untouched editor methods retain their
baseline bytes; their 22 cases pass on both engines.

Earlier failures remain in the component evidence. Initial test assumptions
about Filament partial rendering, nested action lookup and page selection were
corrected. A stronger recovery DOM assertion then found a real footer issue:
the backend rejected the exhausted review, but the rendered submit child could
remain enabled. The final checkpoint clears the private processing flag before
rendering recovery and gives that child a live consumed-review predicate. The
four focused recovery cases and both final 61-case runs passed after that repair.
No case was omitted and the exact source/context/replay assertions remain.

The source binding and raw JUnit, complete logs, native durability/shutdown log,
protected-blob checks and retained failed runs are recorded in the component
`tested-source-final.json` packet. The subsequent domain receipt and this UI
receipt are documentation changes; executable source is unchanged from the
tested checkpoint. The domain command's separate adversarial/concurrency
evidence is recorded in `bulk-license-draft-source.md`.

These are focused Livewire and component receipts. Browser definitions and PHP
tests do not establish native browser transport, responsive visual rendering,
scanner execution, the complete database/browser matrices or final release
acceptance. No production license operation, provider action or hosted CI was
performed in this UI lane.
