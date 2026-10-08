import sys, hashlib, json, re
root = '/home/user/VA-Studio-review-free256b-mut/'
D = root + 'app/Domain/Grants/ProductionFree/'
def sub(path, old, new, count=1):
    s = open(path).read()
    assert s.count(old) >= 1, (path, old)
    s = s.replace(old, new, count)
    open(path, 'w').write(s)
name = sys.argv[1]
if name == 'S1':
    sub(D+'ProductionFreeGrantDefinitions.php', "ProductionFreeGrantException::require((int) $graph['payload']['author_user_id'] !== (int) $reviewer->getKey(), 'self_review');", "")
elif name == 'S2':
    sub(D+'ProductionFreeGrantSchema.php', " AND d.author_user_id <> NEW.reviewer_user_id", "")
elif name == 'S3':
    sub(D+'ProductionFreeGrants.php', "ProductionFreeGrantException::require($count < $graph['payload']['max_origins'], 'cap_reached');", "")
elif name == 'S4':
    sub(D+'ProductionFreeGrantDownloads.php', "ProductionFreeGrantException::require($graph['revocation'] === [], 'revoked');", "")
elif name == 'S5':
    sub(D+'ProductionFreeGrantFiles.php', "$output = @fopen($path, 'x+b');", "$output = @fopen($path, 'c+b');")
elif name == 'S6':
    sub(D+'ProductionFreeGrantDownloads.php', "|| ! hash_equals($target['sha256'], hash_final($hash)) ", "")
elif name in ('U1', 'U2', 'U3'):
    text = D+'ProductionFreeGrantText.php'
    manifest = root+'resources/contracts/production-free-v1/profile-assets.json'
    if name in ('U1', 'U2'):
        s = open(text).read()
        open(text, 'w').write(s.rstrip('\n') + '\n// review probe: a later renderer revision\n')
    if name == 'U1':
        m = open(manifest).read()
        old = json.loads(m)['files']['app/Domain/Grants/ProductionFree/ProductionFreeGrantText.php']
        new = hashlib.sha256(open(text, 'rb').read()).hexdigest()
        open(manifest, 'w').write(m.replace(old, new))
        profile = D+'ProductionFreeGrantRenderProfile.php'
        sub(profile, re.search(r"MANIFEST_HASH = '([a-f0-9]{64})'", open(profile).read()).group(1), hashlib.sha256(open(manifest, 'rb').read()).hexdigest())
    if name == 'U3':
        m = open(manifest).read()
        open(manifest, 'w').write(m.rstrip('\n') + '\n\n')
else:
    raise SystemExit('unknown mutation')
print('applied', name)
