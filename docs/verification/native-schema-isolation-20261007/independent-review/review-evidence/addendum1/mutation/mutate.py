#!/usr/bin/env python3
"""Addendum 1 mutation A1-M5: identity qualifies() drops the 'complete identifier followed by a dot' requirement
and ends the name at a narrow [A-Za-z0-9_] boundary instead (the pre-fix semantics with the new alternatives).
A peer named <db>-2 / <db>$x would then count as the selected schema. Revert: git checkout -- app database."""
import pathlib
f = pathlib.Path('/home/user/VA-Studio-review-trigscan/app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php')
old = r"""(?:\s|\/\*.*?\*\/|(?:--\s|#)[^\n]*)*\./isu'"""
new = r"""(?![A-Za-z0-9_])/isu'"""
s = f.read_text()
assert s.count(old) == 1, s.count(old)
f.write_text(s.replace(old, new))
print('A1-M5 applied to', f)
