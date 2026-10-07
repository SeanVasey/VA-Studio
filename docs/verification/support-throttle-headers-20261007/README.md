# Private attachment retry-header correction

The actual automated PR37 review found that a throttled private attachment page or action lost its retry headers. Both the private middleware catch and the global exception response projection now preserve only four bounded numeric throttle headers. Generic bodies and the private sandbox remain intact; arbitrary redirect, cookie and exception headers are discarded.

The exact original two registered HTTP regressions failed with missing headers against published6a1c727. Their original source/raw/JUnit remain unchanged here. The repaired registered family passes12/405, including malformed or ambiguous numeric-header refusal. The byte-identical archived original regressions also pass2/136 against the repaired runtime. Pint passes. `source-binding.json` identifies the executable commit and each changed source hash. Independent review and updated cheap preflight follow before merge.

The runtime change performs no SQL, provider or file I/O and changes no authority/migration/scanner semantics. Earlier support native evidence remains attributed to its source. Complete final native/browser acceptance remains pending.
