#!/usr/bin/env python3
"""Apply one reviewer mutation to the review worktree (revert with `git checkout -- app database`)."""
import sys, pathlib
ROOT = pathlib.Path('/home/user/VA-Studio-review-trigscan')
MUTATIONS = {
    # M1: identity classification by schema only (drop the qualified-name / PREPARE rule).
    'M1_identity_schema_only': ('app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php',
        "return $this->references($sql, $tables) && (strtolower((string) $schema) === strtolower($database)\n            || $this->references($sql, [$database, 'prepare']));",
        "return $this->references($sql, $tables) && strtolower((string) $schema) === strtolower($database);"),
    # M2: capability qualifier matched as a raw substring (prefix-unsafe: `<db>_other` would count as `<db>`).
    'M2_capability_substring_qualifier': ('app/Domain/Commerce/ProductionPolicy/CapabilityMigrationOwnership.php',
        "|| $this->references($sql, [$database, 'prepare']));",
        "|| stripos($sql, $database) !== false || $this->references($sql, ['prepare']));"),
    # M3: 243 database-name qualifier matched case-sensitively; 'prepare' stays case-insensitive (an upper-case qualifier names the same schema on lower_case_table_names=1/2).
    'M3_inquiry_case_sensitive_qualifier': ('database/migrations/2026_10_07_243000_inquiry_notification_intents.php',
        "if (preg_match('/(?<![a-z0-9_])'.preg_quote($name, '/').'(?![a-z0-9_])/i', $sql) === 1) {",
        "if (preg_match('/(?<![a-zA-Z0-9_])'.preg_quote($name, '/').'(?![a-zA-Z0-9_])/'.($name === $database ? '' : 'i'), $sql) === 1) {"),
    # M4: batched prefilter compared in binary (drops collation aliases the per-name predicate matches).
    'M4_identity_binary_prefilter': ('app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php',
        "$prefilter = ' AND '.$part[4].$part[5].$part[6].' IN ('",
        "$prefilter = ' AND BINARY '.$part[4].$part[5].$part[6].' IN ('"),
}
name = sys.argv[1]
path, old, new = MUTATIONS[name]
f = ROOT / path
s = f.read_text()
assert s.count(old) == 1, f'{name}: anchor count {s.count(old)}'
f.write_text(s.replace(old, new))
print(f'{name}: applied to {path}')
