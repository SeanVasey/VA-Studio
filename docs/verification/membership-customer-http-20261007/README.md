# Private synthetic customer membership history HTTP

Successor to the retained customer history child. Adds bodyless, private GET
`/account/membership-credits` for bounded owned bucket selection IDs and
`/account/membership-credits/{bucket}` for the previously verified retained
synthetic history. Principal, user and account ownership come exclusively from
the existing current authenticated customer session. Staff login and client
owner/account/version fields confer no customer identity. The unchanged global
CustomerPrivacy rejects query/range/cross-origin overrides and protects every
response; these handlers also reject nonempty GET bodies. Unknown and foreign
buckets share the same private failure. Decimal bucket route values are bounded
and positive; bucket IDs are never principal evidence.

Discovery returns only selection hints, no balance, invoice, plan, entitlement or
paid-period assertions. It retains up to100 exact owned bucket rows with a101st
sentinel; any overflow refuses the entire result. Terminal captured PDO proof
compares the full account range including absence/additions after application
callbacks, account/users and each bucket. The only MembershipEvidence extension
permits customer_account_id as an exact bucket-range selector. No cursor or
partial unverified balance pages. Individual selected histories still validate
every bounded event and retained version through unchanged CreditLedger.

The controller performs no callback-enabled authority read after each service's
terminal proof. Customer identity is resolved before it; final proof belongs to
the service. Response JSON/privacy header construction performs no database read.
No credit, plan, migration, schema, CI, provider or permission mutation; production
and default-disabled membership/customer configurations refuse both routes.

The first HTTP suite failed11 of16 cases (5passed58assertions): Laravel's getJson
helper sends a nonempty `[]` GET body, correctly rejected by the new bodyless
contract. The fixtures now make genuine bodyless GETs; explicit nonempty GET
refusal stays tested. Corrected16case predecessor passed123 assertions before
removing a redundant post-service authority query and adding expiry/unknown
identity transport cases. Final frozen source and checks follow in the receipt.

This is a JSON transport child, not a mounted customer UI. The next ready child
is an independently reviewed retained-history component in
resources/js/Pages/CustomerLibrary.tsx using this discovery/show contract,
honest synthetic copy, original plan/credit movements, unavailable/overflow
states and current-session withdrawal handling. U-10, source paid periods,
renewal/cancellation/rollover, production grants and external billing remain open.

Frozen executable `6a1aed576160a349a0e0320d2636a9ca64235c7f`, tree
`fa1fd18d5149368e12f6d485717cf060b1756797`: four-file affected SQLite passed
67 cases / 414 assertions, zero failures/errors/skips. Native MySQL8.4.11 passed
five selected HTTP cases /41 assertions, zero failures/errors/skips: real session
ownership, extra bucket range addition, current-account withdrawal,100+1 bound,
and expiry crossing. These checks prove the relevant native selection/proof
paths; they are not all18 native HTTP cases or cross-process races. Scoped
syntax/Pint/diff checks passed. Receipt binds exact commands/source/artifacts.
Independent sensitive review remains a separately retained root gate.
