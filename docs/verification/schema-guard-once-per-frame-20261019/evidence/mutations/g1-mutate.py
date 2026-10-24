#!/usr/bin/env python3
"""G1 mutations: apply one named mutation to the mutation copy (apply|restore <name>)."""
import sys, pathlib
ROOT = pathlib.Path('/tmp/claude-0/-home-user-VA-Studio/b5dc44df-c06e-5677-8541-57d7464f9ccc/scratchpad/g1-mut')
F = 'app/Domain/Customers/ProductionIdentity/SchemaProofFrame.php'
M = {
 # memo not invalidated on a TEMPORARY shadow
 'M1a-mysql-no-temporary-counters': (F, "                $state = [$session, $counters];", "                $state = [$session];"),
 'M1b-sqlite-no-temp-catalog': (F, "                    $this->primary->query('SELECT type, name, tbl_name, sql FROM sqlite_temp_master')->fetchAll(PDO::FETCH_NUM),\n", ""),
 # memo shared across connections (one static memo for every frame)
 'M2-shared-static-memo': (F, "        if (($this->proofs[$guard] ?? null) === $token) {", "        if ((self::$shared[$guard] ?? null) === $token) {"),
 # memo surviving frame end (no owner witness)
 'M3-no-frame-witness': (F, "            ($this->witness)();\n", ""),
 # terminal re-proof skipped
 'M4a-terminal-noop': (F, "        $this->terminal = true;\n        $this->proofs = [];", "        // terminal skipped"),
 'M4b-reservation-observer-no-terminal': ('app/Domain/Memberships/Production/MembershipReservationObserver.php', "            $this->transaction->beginTerminalProof();\n", ""),
 'M4c-award-observer-no-terminal': ('app/Domain/Memberships/Production/MembershipAwardObserver.php', "            $this->transaction->beginTerminalProof();\n", ""),
 # metadata pin skipped
 'M5-no-pin': (F, "        if ($this->driver !== 'mysql') {\n            return true;\n        }\n        foreach ($tables as $table) {", "        if (true) {\n            return true;\n        }\n        foreach ($tables as $table) {"),
 # dependents scan skipped on reuse
 'M6-no-dependents-on-hit': (F, "                (new IdentityMigrationOwnership)->assertNoDependents($this->primary);\n", ""),
 # session inputs not in the MySQL token
 'M7-no-session-state': (F, "                $state = [$session, $counters];", "                $state = [$counters];"),
}
EXTRA = {'M2-shared-static-memo': [(F, "            $this->proofs[$guard] = $token;\n", "            self::$shared[$guard] = $token;\n"),
                                   (F, "    private bool $terminal = false;\n", "    private bool $terminal = false;\n\n    private static array $shared = [];\n")]}
def edits(name):
    return [M[name]] + EXTRA.get(name, [])
cmd, name = sys.argv[1], sys.argv[2]
for path, old, new in edits(name):
    p = ROOT / path; s = p.read_text()
    if cmd == 'apply':
        assert s.count(old) == 1, (name, path, old[:60]); s = s.replace(old, new)
    else:
        assert s.count(new) >= 1 or new == '', (name, 'not applied')
    p.write_text(s)
if cmd == 'restore':
    import subprocess
    for path, _, _ in edits(name):
        subprocess.check_call(['cp', '/home/user/wt/g1/' + path, str(ROOT / path)])
print(cmd, name, 'ok')
