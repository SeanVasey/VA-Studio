import subprocess, sys
D='app/Domain/Commerce/ProductionTaxCheckout/'
F=D+'TaxCheckoutEvidence.php'
M=[
 ('R1-pi-amount-received', "CheckoutException::require($payment['amount_received'] === $total && $payment['amount_capturable'] === 0", "CheckoutException::require($payment['amount_capturable'] === 0"),
 ('R2-pi-amount', "&& ($payment['amount'] ?? null) === $total && is_int", "&& is_int"),
 ('R3-pi-currency', "=== ($context['funds_mode'] === 'live') && ($payment['currency'] ?? null) === 'usd'", "=== ($context['funds_mode'] === 'live')"),
 ('R4-session-currency', "&& ($session['currency'] ?? null) === 'usd' && ($session['client_reference_id']", "&& ($session['client_reference_id']"),
]
out=open(sys.argv[1],'w')
orig=open(F).read()
for name,old,new in M:
    assert orig.count(old)==1, name
    open(F,'w').write(orig.replace(old,new))
    out.write(f'=== mutation {name} (reviewer review-evidence/mutations/{name}.diff) applied to {F}\n'); out.flush()
    r=subprocess.run([sys.argv[2],'--filter','mismatched_money_fact','tests/Feature/ProductionTaxCheckout/ProductionTaxCheckoutJourneyTest.php'],capture_output=True,text=True)
    out.write(r.stdout+r.stderr); out.write(f'rc={r.returncode}\n'); out.flush()
    open(F,'w').write(orig)
out.write('=== all mutations reverted\n')
