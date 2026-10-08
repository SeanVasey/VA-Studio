# Reviewer mutation driver (addendum 2): removes one of the two delimiter checks in one guard.
# usage: python3 mutate.py <guard: capability|identity|inquiry> <check: backtick|dquote>
import sys
files = {
    'capability': 'app/Domain/Commerce/ProductionPolicy/CapabilityMigrationOwnership.php',
    'identity': 'app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php',
    'inquiry': 'database/migrations/2026_10_07_243000_inquiry_notification_intents.php',
}
old = "if (str_contains($database, '`') || str_contains($database, '\"')) {"
new = {
    'backtick': "if (str_contains($database, '\"')) {",
    'dquote': "if (str_contains($database, '`')) {",
}
path = files[sys.argv[1]]
src = open(path).read()
assert src.count(old) == 1, (path, src.count(old))
open(path, 'w').write(src.replace(old, new[sys.argv[2]]))
print('mutated', path, 'removed', sys.argv[2], 'check')
