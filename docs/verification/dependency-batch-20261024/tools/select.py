"""Deterministic cross-section selection: files in tests/ grouped by feature, then sampled."""
import re, subprocess, sys
files = [l.strip() for l in open(sys.argv[1]) if l.strip().startswith(("tests/Feature", "tests/Unit"))]
cats = {
 "auth-mfa": r"Mfa|Totp|TwoFactor|StaffSignIn|StaffAuth|Login|Password|CustomerIdentity|Authentication",
 "filament-admin": r"Filament|/admin|Livewire",
 "queues": r"ShouldQueue|QueueFake|Queue::|Bus::|dispatchSync|ProcessQueue|Worker|Horizon|queue:work",
 "sessions": r"session\(|Session|withSession|flushSession",
 "transactions": r"DB::transaction|lockForUpdate|sharedLock|Concurren|Serializ|afterCommit",
 "downloads": r"Download|SignedUrl|temporarySignedRoute|URL::signed",
 "webhooks": r"Webhook|Stripe-Signature|whsec",
 "checkout": r"Checkout",
 "membership": r"Membership",
}
text = {f: open(f, encoding="utf-8", errors="replace").read() for f in files}
picked, per = {}, {}
for cat, rx in cats.items():
    hits = sorted(f for f in files if re.search(rx, f) or re.search(rx, text[f]))
    name_hits = [f for f in hits if re.search(rx, f)]
    quota = {"webhooks": 99}.get(cat, 30)
    chosen = list(name_hits[:quota])
    rest = [f for f in hits if f not in chosen]
    step = max(1, len(rest) // max(1, quota - len(chosen)))
    chosen += rest[::step][: max(0, quota - len(chosen))]
    per[cat] = len(chosen)
    for f in chosen:
        picked.setdefault(f, []).append(cat)
for f in sorted(picked):
    print(f)
print({**per, "unique": len(picked)}, file=sys.stderr)
