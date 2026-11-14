# Native race contract correction (static verification only)

Test-only commit `7f51996ce5a14b2fb42ec8aa5ad21409b662a8d0` changes only
`tests/Feature/ProductionMembershipBilling/MembershipNativeReservationRaceTest.php`
in `/workspace/VA-Studio-membership-reservation-entry`. Parent source is archival
merge `faa47b3293180e946f9266eeedbf2241878c9f0a`, whose application/test bytes before
this change match integrated `defe9580`. The original failed native test source
remains retained in the evidence packet reference capsule and original commits.

The authored next-writer contract allows a bounded loser refusal and requires
successful responses to name the original reservation when admitted. The previous
race asserted two successes unconditionally. The test correction keeps the real
60-second bound and requires first success, then either identical second success
or exactly operation_deadline after at least60 real seconds with no transaction.
It excludes committed_receipt_refused and all other errors. Before a separately
invoked parent retry, each corrected case proves the complete audited ledger,
original IDs/honor, cardinality and conserved balances. After retry it requires
an identical full receipt, full audit and every raw period/event/redemption row.
Transcripts retain concurrent outcome/timing, before/after hashes/counts/balances
and the fresh retry receipt or error. Other methods and all method names remain.

Exact final static commands, after sourcing the local toolchain:

```sh
source /workspace/.va-studio-toolchain/activate.sh
php -l tests/Feature/ProductionMembershipBilling/MembershipNativeReservationRaceTest.php > /workspace/runtime/membership-reservation-evidence/native-race-contract-syntax.txt 2>&1
native_contract_syntax=$?
printf '%s\n' "$native_contract_syntax" > /workspace/runtime/membership-reservation-evidence/native-race-contract-syntax.exit
vendor/bin/pint --test tests/Feature/ProductionMembershipBilling/MembershipNativeReservationRaceTest.php > /workspace/runtime/membership-reservation-evidence/native-race-contract-pint.txt 2>&1
native_contract_pint=$?
printf '%s\n' "$native_contract_pint" > /workspace/runtime/membership-reservation-evidence/native-race-contract-pint.exit
sha256sum tests/Feature/ProductionMembershipBilling/MembershipNativeReservationRaceTest.php > /workspace/runtime/membership-reservation-evidence/native-race-contract.sources
git diff --check
git add tests/Feature/ProductionMembershipBilling/MembershipNativeReservationRaceTest.php
git diff --cached --check
git commit -m 'Test bounded reservation race admission and retained retry identity'
```

Syntax and Pint passed, both exit0; unstaged/staged whitespace checks passed.
An earlier implementation snapshot also passed syntax/Pint before fresh-error
transcripts and original release-ID assertions were added; those tool outputs
were not separately logged. No PHPUnit discovery, functional test, native schema
access, hosted dispatch or push occurred in this correction lane. Independent
review and native execution of the revised assertions remain pending.
