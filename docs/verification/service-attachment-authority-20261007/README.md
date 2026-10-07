# Focused service attachment authority evidence

Corrected runtime/test source: b209b850166998967a76edb873a5dfcdd18bfbce.
Predecessor runtime/test source: e765e9b2dad565097d8124ec86e4c3470ead8818.
Parent source:38105a6d1cd8c5c8f2654fd81e28bff3d6ca2acb,reviewed service correction.

The isolated worktree borrows cached dependency packages through private vendor
symlinks and owns its Composer generated autoload files; original dependencies
and manifests are unchanged. Its baseline does not contain peer SupportAttachments
interfaces. Isolated commands used an external-only auto_prepend_file autoload
shim to the peer's owned classes. The adjacent source-map records their SHA256
bytes,verified equal to frozen peer contract baseline
1a178fd711e008fb8feda9ccf4c7bfa325d89f06; no support classes or dependency shim are committed into product source.
Final composed review must include the peer's frozen contracts and ordinary app
boot; these isolated receipts cannot certify a missing/incompatible registry.

Focused command:php -d auto_prepend_file=<external-local-shim> vendor/bin/phpunit
 tests/Feature/ServiceProjectAttachmentAuthorityTest.php. Native command additionally
sources the private author-only task env and loopback network additional permission.
No credentials are printed or committed. Scoped Pint checks changed PHP files.

Cases cover current buyer and operator source bindings,private DTO refusal,
cross-customer/visitor/foreign/stale inputs,withdrawal/cancellation policy,
retained origin replay,current account/role withdrawal at terminal framework
query callbacks,connection replacement,temp shadows,framework commit/rollback,
direct PDO commit/reopen,default-off flags,and same-proof exact mutation admission.
No new native concurrency claim is made; the attachment consumer owns its actual
source/upload/scan worker contention proof and independently allocated database.
No global install,hosted CI,push,merge,production configuration,external send,
payment or historical paid graph mutation occurred in this child.


Predecessor e765e9b selection:SQLite **11cases/54assertions**,native MySQL
`8.0.46-0ubuntu0.24.04.4` **11cases/54assertions**,all passed. All primary source
fixtures in the native selection use MySQL; the connection-replacement canary
constructs an unrelated in-memory SQLite PDO and rejects it before any source
read. It is an identity refusal,not SQLite certification of native source locks.
Scoped Pint passed. The initial SQLite attempt was **7of8 passed/37assertions**:
its synthetic PDO replacement reset Laravel's transaction counter and left the
old physical descriptor open,causing disposable cleanup VACUUM refusal. The
fixture now explicitly rolls back only its displaced synthetic descriptor; the
raw failing receipt is retained. A preliminary native9/47 selection passed before
the new marker/same-proof regression cases and is not final-source acceptance.

No independent composed approval or actual attachment upload/scan/download UI
acceptance is claimed by these source-adapter receipts. The peer owns those
consumer tests,and root owns provider/registry integration and independent review.

Independent review then found a terminal admission defect on composed
5c99b8145ad965201504a4ab578e522946b7c495: the fourth staff framework user query
withdrew the service flag after its prior check, but a source proof still escaped.
The original independent SQLite canary failed 1/1 with no errors; root retained
the raw red evidence. Earlier passing selections do not certify that boundary.

b209b85 removes the duplicate callback-capable MFA provider invocation from the
raw authority phase and checks current service/customer preparation flags after
all policy callbacks and terminal raw source reads. Three new regressions cover
the original fourth-query flag withdrawal, provider flag withdrawal, and provider
staff-role withdrawal. The author's successor SQLite selection passes
**14 cases/72 assertions**. The unchanged independent original canary run against
this successor passes **1 case/8 assertions**. These author runs are affected
verification, not independent approval of root's eventual composition. Adjacent
receipts retain exact source and commands; the original red is unchanged.

The same corrected b209b85 author selection on the isolated native MySQL
`8.0.46-0ubuntu0.24.04.4` primary passes **14 cases/72 assertions**. The only
separate SQLite descriptor is the deliberately displaced connection identity
canary described above; all source fixtures and late callback refusals in this
selection use MySQL. Scoped Pint passed on both changed PHP paths.

A later independent service-command canary found a temporary-users-shadow MFA
withdrawal in the predecessor service domain. Root owns that separate repair;
its prior service approval is held pending successor review. The adapter itself
uses qualified AttachmentRows reads with temporary-shadow refusal. These child
receipts do not approve the uncorrected broader service composition.
