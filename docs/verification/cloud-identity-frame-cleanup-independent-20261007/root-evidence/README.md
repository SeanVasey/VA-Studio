# Original identity frame cleanup preserves foreign read-only state

Actual automated review4206672462 arrived after PR38 merged and identified an unconditional SQLite query_only restoration on a replacement transaction. Root's genuine unchanged probe fails1/3 on the old runtime: close leaves the foreign PDO transaction open but changes query_only1to0. Its exact source/raw/JUnit remain here.

Executable5053af5 restores captured SQLite session state only when the captured PDO is idle. Cleanup remains pending while a foreign replacement is active; after its caller ends that transaction, a later close restores the captured idle default. Original marker ownership still gates rollback. No authority, frame, identity, deadline or financial provider API is renewed. The replacement setting/savepoint remain intact and a real SQLite write remains refused.

Current registered family14recorded/13executed/78assertions passes with the one existing named native-only SQLite skip; exact archived original probe passes1/5. Scoped Pint passes. Independent narrow review and a selected native counterpart follow; complete native8.4/full final acceptance remains deferred. Existing historical/SMTP/source producer receipts retain their old exact source attribution; this derivative correction is separately bound and must be preserved during future composition.
