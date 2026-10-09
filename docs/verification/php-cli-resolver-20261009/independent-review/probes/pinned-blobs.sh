#!/bin/bash
# Prints git blob ids of each pinned renderer at main 86e22f1a, current main e5e500ca, the subject dac1a79 and the worktree.
cd /home/user/rv-m16 || exit 1
for f in app/Domain/Grants/Free/FreeGrantRendererProcess.php \
         app/Domain/Grants/ProductionFree/ProductionFreeGrantRendererProcess.php \
         app/Domain/Grants/Paid/PaidGrantRendererProcess.php \
         scripts/render-free-grant.php scripts/render-production-free-grant.php scripts/render-paid-grant.php; do
    echo "$f"
    echo "  86e22f1a=$(git rev-parse "86e22f1a:$f")"
    echo "  e5e500ca=$(git rev-parse "e5e500ca:$f")"
    echo "  dac1a79 =$(git rev-parse "dac1a79:$f")"
    echo "  worktree=$(git hash-object "$f")"
done
