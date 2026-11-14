# Member originals: development boundary and recovery

This is the C4b development integration contract, not permission to activate membership commerce. The [verification packet](../verification/member-originals-20261013/README.md) records the tested source and remaining conditions. Both `member-grants.enabled` and the separate `member-grants.authoring_enabled` default to `false`; this batch does not mount a route or register production capabilities.

## Authoring and preparation

Staff authoring requires current persisted staff authority and MFA. It appends complete immutable profile and definition records bound to an existing plan version. The authoring flag supplies no customer preparation or grant authority. Original license terms, storage policy and retention policy must be supplied explicitly; synthetic fixtures are not approved commercial terms.

Customer preparation returns a candidate description and writes no origin, artifact, activation or credit event. A candidate, its hashes and a private file's existence are not permission to consume a credit or deliver a license. C4c must obtain fresh source/facts proofs and admit the prepared artifact inside the same owned transaction that consumes and activates. Do not turn the standalone candidate into a reusable readiness token.

## Renderer and storage

The renderer owns its child process and verifies the member profile before launch. The optional observation hook receives no process or command and cannot provide rendered output. The child does not bootstrap Laravel. The new member profile is separate from the five historical frozen profiles; their manifests and members must not be changed or re-pinned.

Preparation requires all resolved write, read and direct database handles to be outside native transactions during rendering and file operations. The local disk must be private, unserved, without a prefix, and outside public roots and configured public links. Private root/candidate directories require mode `0700`; a completed original requires `0400`, a regular native file and one hard link. Paths are derived by the service:

```
contracts/production-member-v1/{provenance}/{redemption_id}/{render_source_hash}/original.pdf
```

The authenticated redemption UUID also supplies the candidate's origin UUID. A caller does not choose a storage path. Existing candidate bytes are accepted only after a fresh trusted render produces exactly the same bytes; a checksum supplied by the caller is insufficient. Publication uses a non-replacing hard link followed by removal of the invocation's own temporary name.

## Refusal and recovery

An authoring response may be refused after immutable rows committed, for example when authority changes in a committed callback. Preserve those rows and inspect retained identity before retrying. Do not describe a refused response as proof that nothing committed.

A preparation refusal can leave a private candidate or workspace. A later invocation must repeat source admission and rendering before adopting a completed candidate. If the process stopped after the final hard link but before temporary-name removal, the remaining two links are deliberately refused. There is no automatic orphan deletion or repair operation in this batch. Any later operator repair must establish the exact owned inode and both names; never overwrite a historical original or remove unrelated paths.

Source withdrawal during file work is detected by fresh final admission; the file work itself does not continuously observe the database. There is no filesystem/database atomicity or crash-durability claim. Preserve existing originals for exact-byte restoration if storage is lost; new rendering is not evidence that a historical original was recovered.

The original workflow budget is at most 300 seconds. Child rendering is capped by the remaining budget and 60 seconds. Database and physical-observation budgets can shorten admission further and cannot be renewed by retries within that invocation. Deadline checks do not promise to interrupt a blocked filesystem or SQL call. Representative host timing, storage durability, orphan retention, commercial approval and the carried membership activation conditions remain separate acceptance work.
