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
editor regressions. Exact tested source, commands and outcomes will be recorded
with the frozen component evidence. Browser definitions and PHP tests do not
establish native transport, responsive rendering or final full acceptance.
