import assert from 'node:assert/strict';
import test from 'node:test';
import { mkdtempSync, chmodSync, writeFileSync, readFileSync, readdirSync, lstatSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { initialize, readWorkspace, acquireLease, isolatedEnvironment } from '/workspace/scratch/2876616e88c3/VA-Studio-pre-epoch-upgrade-old/scripts/dev/persistent-content.mjs';
import { reviewedCheckout, upgradeWorkspace } from '/workspace/scratch/2876616e88c3/VA-Studio-pre-epoch-upgrade-new/scripts/ops/persistent-content-upgrade.mjs';
const old = '/workspace/scratch/2876616e88c3/VA-Studio-pre-epoch-upgrade-old';
const current = '/workspace/scratch/2876616e88c3/VA-Studio-pre-epoch-upgrade-new';
const oldSha = '5cba90191a21aca3acef7b9e01f65efecdbd0dcd';
const newSha = '9f1bda29a20a6f6936efa9b86e9731194bd3ccf7';
const output = new URL('./outcomes.json', import.meta.url);
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
function php(workspace, args, input, env = isolatedEnvironment(workspace)) {
 const result = spawnSync('php', args, { cwd: workspace.checkout, env, input, encoding: 'utf8' });
 assert.equal(result.status, 0, result.stderr + result.stdout); return result.stdout;
}
function inventory(directory) {
 const files = {};
 function walk(relative='') { for (const name of readdirSync(join(directory,relative)).sort()) { const key=relative ? relative+'/'+name : name; const path=join(directory,key); if(lstatSync(path).isDirectory())walk(key); else files[key]=hash(readFileSync(path)); } }
 walk();return files;
}
function snapshot(workspace) {
 return JSON.parse(php(workspace,['-r', '$p=new PDO("sqlite:".getenv("DB_DATABASE"));$schema=$p->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE \'sqlite_%\' ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);$tables=[];foreach($schema as $s){if($s["type"]==="table"){$tables[$s["name"]]=$p->query("SELECT * FROM \"".str_replace("\"","\"\"",$s["name"])."\" ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);}}echo json_encode(["schema"=>$schema,"tables"=>$tables,"sequences"=>$p->query("SELECT name,seq FROM sqlite_sequence ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR);']));
}
test('exact historical pre-epoch installation upgrades through genuine stopped-copy CLI without rewriting old evidence', async () => {
 const base=mkdtempSync(join(tmpdir(),'vasey-pre-epoch-upgrade-'));chmodSync(base,0o700);
 try {
  const reviewedOld=reviewedCheckout(old,oldSha);const reviewedNew=reviewedCheckout(current,newSha);
  const directory=join(base,'old-installation');await initialize(directory,{checkout:old,output:{write(){}}});
  let source=readWorkspace(directory,old);const sourceEnv=isolatedEnvironment(source);const lease=await acquireLease(source,sourceEnv);
  try {
   php(source,['scripts/dev/persistent-content-bootstrap.php','operator'],'NONBINDING upgrade operator\npre-epoch@example.test\nSyntheticPreEpoch987654321\n',sourceEnv);
   php(source,['-r','umask(0077);require "vendor/autoload.php";$a=require "bootstrap/app.php";$d=getenv("VASEY_CONTENT_DIRECTORY");$a->useEnvironmentPath($d);$a->useStoragePath($d);$a->usePublicPath($d."/public");$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();app(App\\Domain\\Catalog\\SaveTrackMetadata::class)->handle(null,["title"=>"NONBINDING retained draft","slug"=>"retained-draft","artist"=>"Synthetic operator"],App\\Models\\User::sole());'],undefined,sourceEnv);
  } finally { await lease.release(); }
  writeFileSync(join(directory,'app/private/retained-source.bin'),Buffer.from([0,255,16,42]),{mode:0o600});
  writeFileSync(join(directory,'framework/sessions/retained-session'),'NONBINDING opaque retained session',{mode:0o600});source=readWorkspace(directory,old);
  const originalBytes=inventory(directory);const before=snapshot(source);const oldMigrations=before.tables.migrations;
  assert.equal(before.schema.some(s=>s.name==='catalog_discovery_epoch'),false);assert.equal(before.schema.some(s=>s.name.startsWith('cde_')),false);
  const destination=join(base,'upgraded');const proof=await upgradeWorkspace({sourceCheckout:old,sourceDirectory:directory,directory:destination,checkout:current,expectedSourceSha:oldSha,expectedTargetSha:newSha,output:{write(){}}});
  assert.deepEqual(inventory(directory),originalBytes);const target=readWorkspace(destination,current);const after=snapshot(target);
  for(const [name,rows] of Object.entries(before.tables)) { if(name==='migrations')assert.deepEqual(after.tables[name].slice(0,rows.length),rows);else assert.deepEqual(after.tables[name],rows); }
  for(const object of before.schema)assert.deepEqual(after.schema.find(s=>s.type===object.type&&s.name===object.name),object);
  assert.deepEqual(after.sequences.filter(s=>s.name!=='migrations'),before.sequences.filter(s=>s.name!=='migrations'));
  const appended=after.tables.migrations.slice(oldMigrations.length);assert.equal(appended.length,1);assert.equal(appended[0].migration,'2026_10_07_240000_catalog_discovery_epoch');
  const additions=after.schema.filter(s=>!before.schema.some(o=>o.type===s.type&&o.name===s.name));assert.equal(additions.length,55);assert.equal(additions.filter(s=>s.type==='table'&&s.name==='catalog_discovery_epoch').length,1);assert.equal(additions.filter(s=>s.type==='trigger'&&s.name.startsWith('cde_')).length,54);
  assert.deepEqual(after.tables.catalog_discovery_epoch,[{id:1,epoch:0,schema_version:1}]);
  for(const key of ['app_key','session_cookie','installation_id'])assert.equal(target.identity[key],source.identity[key]);
  for(const path of ['app/private/retained-source.bin','framework/sessions/retained-session'])assert.deepEqual(readFileSync(join(destination,path)),readFileSync(join(directory,path)));
  const forward=php(target,['artisan','migrate','--force','--no-interaction']);assert.match(forward,/Nothing to migrate/);assert.deepEqual(snapshot(target),after);
  const ownership=php(target,['-r','require "vendor/autoload.php";$a=require "bootstrap/app.php";$d=getenv("VASEY_CONTENT_DIRECTORY");$a->useEnvironmentPath($d);$a->useStoragePath($d);$a->usePublicPath($d."/public");$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$e=new App\\Domain\\Catalog\\Discovery\\DiscoveryEpoch;$e->assertInstalled(Illuminate\\Support\\Facades\\DB::connection()->getPdo(),"sqlite");echo $e->current(Illuminate\\Support\\Facades\\DB::connection()->getPdo());']);assert.equal(ownership,'0');
  assert.equal(proof.original_workspace_unchanged,true);assert.equal(proof.production_backup_or_restore,false);
  writeFileSync(output,JSON.stringify({old_source:oldSha,new_source:newSha,reviewed_old:reviewedOld,reviewed_new:reviewedNew,old_migration_records:oldMigrations.length,new_migration_records:after.tables.migrations.length,old_tables_verified:Object.keys(before.tables).length,old_rows_verified:Object.values(before.tables).reduce((n,r)=>n+r.length,0),old_schema_objects_verified:before.schema.length,original_files_verified:Object.keys(originalBytes).length,original_files_unchanged:true,old_cells_and_bookkeeping_exact:true,old_schema_exact:true,business_sequences_exact:true,installation_key_and_session_identity_exact:true,new_schema_objects:additions.map(s=>({type:s.type,name:s.name})),new_epoch_singleton:after.tables.catalog_discovery_epoch,new_migration:appended,forward_migrate_usable:true,owned_epoch_verified:true,synthetic_only:true,provider_calls:0},null,2)+'\n');
 } finally { rmSync(base,{recursive:true,force:true}); }
});
