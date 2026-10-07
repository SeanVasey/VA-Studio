# Synthetic membership customer library capability

Successor to independently reviewed private membership history HTTP. Adds the
canonical `MembershipPolicy::enabled()` predicate using exactly the previous
requireEnabled conditions: local/testing environment and literal true test flag.
The authoritative requireEnabled writer/read boundary delegates to that predicate;
production remains unconditionally refused, defaults remain off.

After the customer library's current-session check, its private Inertia props add
`testMembershipsEnabled` and `membershipHistoryScope`. Disabled state returns
false/null. Enabled state returns true and a fresh32hex random value per render.
The scope is a transient frontend invalidation token, never an account/owner/user
ID, stable identifier, authorization claim or request credential. The server
history handlers do not accept it. Fresh renders and same-name account switches
receive different values, so a reused component can clear retained history.
Guests and withdrawn customers receive no library props. Existing private/no-store
history encryption stays in place.

No UI edit, data/migration/credit change, provider/billing configuration or CI
policy modification. Membership UI author owns the consuming component and
CustomerLibrary; this child owns MembershipPolicy, CustomerSessionController and
its eight focused capability tests. Exact frozen source/check evidence follows.

Frozen executable `69fce09d7b73f4675ff4a7b73422d55451c17385`, tree
`7d56567acc179860be2514df04a7c87a8861be9a`: four-file affected SQLite passed
49 cases /358 assertions, zero failures/errors/skips. New capability child alone
passed8 cases /48 assertions before freeze. Scoped syntax/Pint/diff checks passed.
Receipt binds source, exact command, environment, artifacts and parsed results.
No new native run: this child changes the pure canonical predicate/private props,
with the existing PHP/native membership boundaries retained. Previous native
14 history and5 HTTP method cases remain bound to their recorded earlier sources,
not relabeled as49 native cases. Full/browser/device acceptance remains open.
Independent sensitive review is retained separately by the integration lead.
