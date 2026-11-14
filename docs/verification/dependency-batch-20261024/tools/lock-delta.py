"""Compares two lock files and prints every changed, added or removed record.

Usage: lock-delta.py composer OLD NEW | lock-delta.py npm OLD NEW
"""
import json, sys

kind, old_path, new_path = sys.argv[1:4]
old, new = json.load(open(old_path)), json.load(open(new_path))
if kind == "composer":
    def records(lock):
        return {p["name"]: (p["version"], p["source"]["reference"][:8], p) for p in lock["packages"] + lock["packages-dev"]}
    a, b = records(old), records(new)
    print(f"records {len(a)} -> {len(b)}")
    print("content-hash same:", old["content-hash"] == new["content-hash"])
    for name in sorted(set(a) | set(b)):
        if name not in a:
            print("ADDED", name, b[name][0])
        elif name not in b:
            print("REMOVED", name, a[name][0])
        elif a[name][2] != b[name][2]:
            print("CHANGED", name, f"{a[name][0]} ({a[name][1]}) -> {b[name][0]} ({b[name][1]})",
                  "| require/require-dev/suggest identical:",
                  all(a[name][2].get(k) == b[name][2].get(k) for k in ("require", "require-dev", "suggest", "conflict", "replace")))
    for name in ("symfony/console", "livewire/livewire", "filament/filament", "tecnickcom/tc-lib-pdf", "tecnickcom/tc-lib-pdf-font",
                 "stripe/stripe-php", "phpunit/phpunit", "inertiajs/inertia-laravel"):
        print("unchanged-check", name, a[name][0], b[name][0], "OK" if a[name][2] == b[name][2] else "CHANGED")
else:
    a, b = old["packages"], new["packages"]
    print("lockfileVersion", new["lockfileVersion"], "| records", len(a), "->", len(b))
    for name in sorted(set(a) | set(b)):
        if name not in a:
            print("ADDED", name, b[name]["version"])
        elif name not in b:
            print("REMOVED", name, a[name]["version"])
        elif a[name] != b[name]:
            print("CHANGED", name or "(root manifest)", a[name].get("version"), "->", b[name].get("version"), "dev=" + str(b[name].get("dev", False)))
