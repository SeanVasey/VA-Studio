import assert from 'node:assert/strict';
import {mkdtempSync,mkdirSync,cpSync,writeFileSync,readFileSync,readdirSync,rmSync} from 'node:fs';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {createWorkspace,readWorkspace,schemaHash} from '../VA-Studio-dependency-independent/scripts/dev/persistent-content.mjs';
const root='/workspace/scratch/2876616e88c3/VA-Studio-dependency-independent';
const parent=mkdtempSync(join(tmpdir(),'va-dependency-identity-'));
const fixture=join(parent,'checkout'); mkdirSync(fixture,{mode:0o700});
try {
 for(const p of ['database/migrations','public/build']) cpSync(join(root,p),join(fixture,p),{recursive:true});
 mkdirSync(join(fixture,'vendor'));writeFileSync(join(fixture,'vendor/autoload.php'),'<?php');
 const old=execFileSync('git',['show','HEAD^:composer.lock'],{cwd:root});
 const candidate=readFileSync(join(root,'composer.lock'));writeFileSync(join(fixture,'composer.lock'),old);
 const workspace=createWorkspace(join(parent,'installation'),fixture);
 writeFileSync(join(workspace.directory,'app/private/retained-test-content'),'NONBINDING retained bytes',{mode:0o600});
 const snapshot=()=>Object.fromEntries(['identity.json','database.sqlite','lease','app/private/retained-test-content'].map(p=>[p,createHash('sha256').update(readFileSync(join(workspace.directory,p))).digest('hex')]));
 const before=snapshot();const oldHash=schemaHash(fixture);
 assert.equal(readWorkspace(workspace.directory,fixture,{allowInitializing:true}).identity.schema_hash,oldHash);
 writeFileSync(join(fixture,'composer.lock'),candidate);const newHash=schemaHash(fixture);assert.notEqual(newHash,oldHash);
 assert.throws(()=>readWorkspace(workspace.directory,fixture,{allowInitializing:true}),/safety checks/);
 assert.deepEqual(snapshot(),before);
 writeFileSync(join(fixture,'composer.lock'),old);assert.equal(readWorkspace(workspace.directory,fixture,{allowInitializing:true}).identity.schema_hash,oldHash);assert.deepEqual(snapshot(),before);
 console.log(JSON.stringify({old_schema_hash:oldHash,candidate_schema_hash:newHash,refused_changed_lock:true,retained_file_digests_unchanged:true,old_graph_recovery:true,state:'initializing',boundary:'readWorkspace identity gate; not initialized HTTP restart'},null,2));
}finally{rmSync(parent,{recursive:true,force:true});}
