# L2-4: the invoice-id binding comparison is now covered

Lane 2 independent review (`../../independent-review/DECISION.md`): removing the invoice-id comparison from
`BillingSettlement::bindingValidated()` left every test green, so the code was correct but unguarded.

`BillingSettlementTest::test_binding_is_not_validated_when_the_retrieved_invoice_is_not_the_requested_one` asserts the exact
graph validates (control) and a graph whose invoice id differs from the requested one does not, with every other binding field equal.

- Green on the lane source: 1 test, 2 assertions (`green.txt`).
- Red against the review's surviving mutation (the `($invoice['id'] ?? null) === $s->requestedInvoiceRef` clause removed, then the
  source restored): 1 failure (`red-mutation-invoice-id-check-removed.txt`).
- `tests/Feature/ProductionMembershipBilling` on SQLite: 144 tests, 628 assertions, 1 skipped, rc 0 (`sqlite-billing-directory.txt`).
- Pint on the changed test: passed (`pint.txt`).

No application code changed.
