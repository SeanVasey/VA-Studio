#!/usr/bin/env python3
"""usage: mutate.py apply|restore <mutant-id>   operates on the hard-link copy MUT_ROOT, never on the worktree."""
import os, sys, json
SRC = '/home/user/wt/f9'
MUT = os.environ.get('MUT_ROOT', '<scratch>/f9-mut')
IDX = 'app/Domain/Memberships/Production/MembershipCustomerIndex.php'
CTL = 'app/Http/Membership/MembershipController.php'
MUTANTS = {
  'K1': [(CTL, "return $keys === ['expectedRevision', 'offerId', 'requestKey']", "return array_keys($body) === ['offerId', 'expectedRevision', 'requestKey']")],
  'K2': [(CTL, "return $keys === ['expectedRevision', 'offerId', 'requestKey']", "return array_diff($keys, ['expectedRevision', 'offerId', 'requestKey']) === []")],
  'P1': [(IDX, '''                $owned = new MembershipAwardTransaction($connection, $primary, memberGrantProofs: true);
                $rows = new MembershipRows($owned);''', '''                $rows = new MembershipRows;'''),
         (IDX, '''                $owned->beginTerminalProof();
''', '')],
  'P2': [(IDX, 'memberGrantProofs: true);', 'memberGrantProofs: false);')],
  'P3': [(IDX, "$state['historical'][$key] ??= $access->verifyHistoricalBinding($buyer, $rows->current());",
               "$state['historical'][$key] = $access->verifyHistoricalBinding($buyer, $rows->current());"),
         (IDX, '''                foreach (self::bindings($periods) as [$binding, $historical]) {
                    $access->proveHistoricalBindingCurrent($binding, $rows->current(), $historical);
                }''', '''                foreach ($periods as $period) {
                    $access->proveHistoricalBindingCurrent($period['invoice']->originalBuyerBinding, $rows->current(), $period['historical']);
                }'''),
         (IDX, '''                foreach (self::bindings($state['periods']) as [$binding, $historical]) {
                    $state['access']->proveHistoricalBindingCommitted($binding, $frame, $historical);
                }''', '''                foreach ($state['periods'] as $period) {
                    $state['access']->proveHistoricalBindingCommitted($period['invoice']->originalBuyerBinding, $frame, $period['historical']);
                }''')],
  'P4': [(IDX, '''                $history->committedCallbacksAll(array_column($state['periods'], 'snapshot'), $frame);
                foreach ($state['periods'] as $period) {
                    $this->audit($period);
                }''', '''                foreach ($state['periods'] as $period) {
                    $history->committedCallbacks($period['snapshot'], $frame);
                    $this->audit($period);
                }''')],
  'T1': [(IDX, '''                $owned->beginTerminalProof();
''', '')],
  'T2': [(IDX, '''                $state['access']->proveCommitted($principal, $actor, $frame, $state['identity']);
''', '')],
  'T3': [(IDX, '''                $history->committedRawAll(array_column($state['periods'], 'snapshot'), $frame);
''', '')],
  'T4': [(IDX, '''                $history->committedCallbacksAll(array_column($state['periods'], 'snapshot'), $frame);
''', '')],
}
def write_atomic(path, data):
    tmp = path + '.mut-tmp'
    with open(tmp, 'w') as f: f.write(data)
    os.replace(tmp, path)
mode, mid = sys.argv[1], sys.argv[2]
files = sorted({f for f, _, _ in MUTANTS[mid]})
if mode == 'apply':
    for f in files:
        s = open(os.path.join(SRC, f)).read()
        for g, a, b in MUTANTS[mid]:
            if g != f: continue
            assert s.count(a) == 1, (mid, a[:60])
            s = s.replace(a, b)
        write_atomic(os.path.join(MUT, f), s)
    print('applied', mid, files)
else:
    for f in files:
        write_atomic(os.path.join(MUT, f), open(os.path.join(SRC, f)).read())
        assert open(os.path.join(MUT, f)).read() == open(os.path.join(SRC, f)).read()
    print('restored', mid, files)
