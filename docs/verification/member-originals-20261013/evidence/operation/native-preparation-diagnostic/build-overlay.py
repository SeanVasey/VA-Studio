from pathlib import Path
import difflib,hashlib,json
root=Path('/workspace/VA-Studio-member-original-operation');out=Path('/workspace/runtime/member-original-operation-evidence/native-preparation-diagnostic')
logger='''<?php
namespace Tests\\Support;
final class MemberOriginalNativeDiagnostic {
    private static ?int $started = null;
    public static function emit(string $phase, ?int $deadline = null, ?\\Throwable $error = null): void {
        $now = \\hrtime(true); self::$started ??= $now;
        $record = ['phase' => $phase, 'elapsed_ms' => round(($now-self::$started)/1_000_000, 3),
            'remaining_ms' => $deadline === null ? null : round(($deadline-$now)/1_000_000, 3)];
        if ($error !== null) {
            $record['exception_class'] = get_class($error);
            $record['reason'] = property_exists($error, 'reason') ? $error->reason : null;
            $record['site'] = basename($error->getFile()).':'.$error->getLine();
            $record['frames'] = array_map(static fn (array $f): string => ($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? ''), array_slice($error->getTrace(), 0, 10));
        }
        \\fwrite(\\STDERR, 'MEMBER_DIAGNOSTIC '.json_encode($record, JSON_UNESCAPED_SLASHES)."\\n");
    }
}
'''
(out/'Diagnostic.php').write_text(logger)
manifest={}
for name in ['MemberOriginalSourceCapture','MemberOriginalInspection']:
 path=root/'app/Domain/Grants/Member'/f'{name}.php';original=path.read_text();s=original
 if name=='MemberOriginalSourceCapture':
  s=s.replace('        $idle = MemberOriginalIoFrame::captureIdle();',"        \\Tests\\Support\\MemberOriginalNativeDiagnostic::emit('inspect.start', $deadline);\n        $idle = MemberOriginalIoFrame::captureIdle();",1)
  for statement,label in [('$connection->beginTransaction();','begin'),('$rows = $observer->rows();','rows'),('$values = $inspection->values();','inspection-values'),('$observer->prepare($inspection);','observer-prepare'),('$connection->commit();','normal-commit'),('$observer->requireCommittedOwnership();','normal-commit-owned'),('$frame = IdentityCommittedFrame::begin($inspection->reader(), $deadline);','committed-frame-begin'),('$proof->prove($frame);','committed-proof')]:
   assert statement in s;s=s.replace(statement,statement+"\n                \\Tests\\Support\\MemberOriginalNativeDiagnostic::emit('after."+label+"', $deadline);",1)
  s=s.replace('            } catch (Throwable $error) {\n                $observer->abort();',"            } catch (Throwable $error) {\n                \\Tests\\Support\\MemberOriginalNativeDiagnostic::emit('current.refusal', $deadline, $error);\n                $observer->abort();",1)
  s=s.replace("            } catch (Throwable) {\n                throw new MemberGrantException('committed_source_refused');", "            } catch (Throwable $error) {\n                \\Tests\\Support\\MemberOriginalNativeDiagnostic::emit('committed.refusal', $deadline, $error);\n                throw new MemberGrantException('committed_source_refused');",1)
 else:
  statements=[('$this->reservation = $reservations->beginMemberCapture($permit, $redemptionId, $principal, $actor, $rows);','initial.reservation'),('$this->factsProof = $facts->lock($definitionId, $principal, $actor, $rows);','initial.facts'),('$this->facts->currentCallbacks($this->factsClosure, $this->rows);','current.facts-callbacks'),('$this->reservations->memberCurrentCallbacks($this->reservation, $this->rows);','current.reservation-callbacks'),('$this->facts->currentRaw($this->factsClosure, $this->rows);','current.facts-raw'),('$this->reservations->memberCurrentRaw($this->reservation, $this->rows);','current.reservation-raw'),('$this->facts->committedCallbacks($this->factsClosure, $frame);','committed.facts-callbacks'),('$this->reservations->memberCommittedCallbacks($this->reservation, $frame);','committed.reservation-callbacks'),('$this->facts->committedRaw($this->factsClosure, $frame);','committed.facts-raw'),('$this->reservations->memberCommittedRaw($this->reservation, $frame);','committed.reservation-raw'),('$frame->finish();','committed.finish'),('$this->reservations->memberPostFinish($this->reservation, $frame);','committed.post-finish')]
  for statement,label in statements:
   assert s.count(statement)==1,(name,statement)
   s=s.replace(statement,"\\Tests\\Support\\MemberOriginalNativeDiagnostic::emit('before."+label+"', $this->deadline);\n        "+statement+"\n        \\Tests\\Support\\MemberOriginalNativeDiagnostic::emit('after."+label+"', $this->deadline);",1)
 (out/(name+'.php')).write_text(s)
 (out/(name+'.diff')).write_text(''.join(difflib.unified_diff(original.splitlines(True),s.splitlines(True),fromfile=str(path.relative_to(root)),tofile='diagnostic/'+name+'.php')))
 manifest[name]={'source_path':str(path.relative_to(root)),'original_sha256':hashlib.sha256(original.encode()).hexdigest(),'overlay_sha256':hashlib.sha256(s.encode()).hexdigest(),'scope':'Diagnostic timing/exception output only; original deadline formulas, guards and callback ordering unchanged.'}
(out/'preload.php').write_text('<?php\nrequire __DIR__."/Diagnostic.php";\nrequire __DIR__."/MemberOriginalSourceCapture.php";\nrequire __DIR__."/MemberOriginalInspection.php";\n')
manifest['files']={p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(out.glob('*.php'))}
(out/'overlay-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
