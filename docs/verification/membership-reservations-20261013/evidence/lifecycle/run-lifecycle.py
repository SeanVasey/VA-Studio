from pathlib import Path
import subprocess,json,hashlib,os,sys
base=Path('/workspace/runtime/membership-reservation-lifecycle-evidence')/sys.argv[1]
command=['php','vendor/bin/phpunit','tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php','--log-junit',str(base.with_suffix('.xml'))]
files=sorted(str(p) for p in Path('app/Domain/Memberships/Production').glob('*.php'))+['tests/Support/MembershipReservationFixtures.php','tests/Feature/ProductionMembershipBilling/MembershipReservationLifecycleTest.php']
env=os.environ.copy();env.update(APP_ENV='testing',APP_DEBUG='false',DB_CONNECTION='sqlite',DB_DATABASE=':memory:',DB_URL='',DB_SOCKET='',CACHE_STORE='array',SESSION_DRIVER='array',QUEUE_CONNECTION='sync',TMPDIR='/tmp/va-studio-membership-redemption-policy-20261013')
base.with_suffix('.source.json').write_text(json.dumps({'head':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'command':command,'environment':{k:env[k] for k in ['APP_ENV','APP_DEBUG','DB_CONNECTION','DB_DATABASE','DB_URL','DB_SOCKET','CACHE_STORE','SESSION_DRIVER','QUEUE_CONNECTION','TMPDIR']},'sha256':{p:hashlib.sha256(Path(p).read_bytes()).hexdigest() for p in files}},indent=2)+'\n')
r=subprocess.run(command,env=env,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
base.with_suffix('.txt').write_bytes(r.stdout);base.with_suffix('.exit').write_text(str(r.returncode)+'\n')
print(r.stdout.decode());print('Observed exit:',r.returncode)
raise SystemExit(r.returncode)
