"""Owned private-socket admin transport. Proof is observed BEFORE secret dispatch.
No child or filesystem work at import. Fixed source caller only; no public SQL/provider API.
"""
import os,secrets,select,subprocess,time
from capture import local_env,Refusal
class Admin:
    def __init__(self,owner,initial=False):
        self.owner=owner;self.p=None;self.pending=bytearray();self.eof=[False,False];self.total=0;self.failed=False
        self.status={'stage':'private_admin','exit':None,'reaped':False,'timeout':False,'overflow':False,'stdout_eof':False,'stderr_eof':False,'stdin_closed':False,'capture_complete':False,'stderr_present':False,'stderr_bytes':0,'stdout_bytes':0}
        args=['docker','exec','-i','--user','0:0',owner.container,'mysql']
        args+=['--no-defaults','-uroot','--protocol=SOCKET','--socket=/run/fixture/mysql.sock'] if initial else ['--defaults-file=/root/root.cnf']
        args+=['--batch','--raw','--skip-column-names','--unbuffered']
        owner.record('private_admin_before',{'fixed_command':args,'private_stdin':True})
        try:
            self.p=subprocess.Popen(args,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,bufsize=0,env=local_env());self.status['owned_cli_pid']=self.p.pid
            for pipe in [self.p.stdin,self.p.stdout,self.p.stderr]:os.set_blocking(pipe.fileno(),False)
            owner.record('private_admin_child_started',{'owned_cli_pid':self.p.pid})
        except Exception:self.failed=True;self.close();raise Refusal('PRIVATE_ADMIN_START_REFUSED') from None
    def remaining(self,deadline):
        n=min(deadline,self.owner.limit)-time.monotonic()
        if n<=0:self.status['timeout']=True;raise Refusal('PRIVATE_ADMIN_DEADLINE')
        return n
    def take(self,ready):
        for pipe in ready:
            index=0 if pipe is self.p.stdout else 1
            try:part=os.read(pipe.fileno(),4096)
            except BlockingIOError:continue
            if not part:self.eof[index]=True;continue
            self.total+=len(part);self.status['stdout_bytes' if index==0 else 'stderr_bytes']+=len(part)
            if index==1:self.status['stderr_present']=True;raise Refusal('PRIVATE_ADMIN_STDERR')
            if self.total>65536 or len(self.pending)+len(part)>16384:self.status['overflow']=True;raise Refusal('PRIVATE_ADMIN_CAPTURE_CAP')
            self.pending.extend(part)
    def send(self,data,deadline):
        view=memoryview(data)
        try:
            while view:
                readable,writable,_=select.select([p for i,p in enumerate([self.p.stdout,self.p.stderr]) if not self.eof[i]],[self.p.stdin],[],min(.1,self.remaining(deadline)))
                self.take(readable)
                if self.pending:raise Refusal('PRIVATE_ADMIN_UNEXPECTED_PREWRITE_DATA')
                if any(self.eof) or self.p.poll() is not None:raise Refusal('PRIVATE_ADMIN_ENDED_BEFORE_WRITE')
                if writable:
                    try:n=os.write(self.p.stdin.fileno(),view[:4096])
                    except BlockingIOError:continue
                    if n<=0:raise Refusal('PRIVATE_ADMIN_WRITE_REFUSED')
                    view=view[n:]
        finally:view.release()
    def query(self,sql):
        """Only owned fixed source statements reach here; no values/SQL/markers persisted."""
        if self.failed:raise Refusal('PRIVATE_ADMIN_STICKY_FAILURE')
        if type(sql) is not str or len(sql.encode())>2048:
            self.failed=True;self.owner.receipt['transport_uncertain']=True;raise Refusal('PRIVATE_ADMIN_SOURCE_SQL_CAP')
        deadline=min(self.owner.limit,time.monotonic()+12);marker=('account_admin_'+secrets.token_hex(12)).encode();rows=[]
        try:
            if self.pending or any(self.eof) or self.p.poll() is not None:raise Refusal('PRIVATE_ADMIN_FRAME_NOT_EMPTY')
            self.send(sql.encode()+b';\nSELECT @@warning_count;\nSELECT \''+marker+b'\';\n',deadline)
            while True:
                at=self.pending.find(b'\n')
                if at>=0:
                    line=bytes(self.pending[:at]);del self.pending[:at+1]
                    if line==marker:
                        if self.pending:raise Refusal('PRIVATE_ADMIN_TRAILING_FRAME')
                        if not rows or rows[-1]!=b'0':raise Refusal('PRIVATE_ADMIN_OWNED_SQL_WARNINGS')
                        return [row.decode('utf-8','strict') for row in rows[:-1]]
                    rows.append(line)
                    if len(rows)>64:raise Refusal('PRIVATE_ADMIN_ROW_CAP')
                    continue
                ready,_,_=select.select([p for i,p in enumerate([self.p.stdout,self.p.stderr]) if not self.eof[i]],[],[],min(.1,self.remaining(deadline)))
                self.take(ready)
                if any(self.eof) or self.p.poll() is not None:raise Refusal('PRIVATE_ADMIN_INCOMPLETE_FRAME')
        except Exception:
            self.failed=True;self.owner.receipt['transport_uncertain']=True;self.pending.clear();raise Refusal('PRIVATE_ADMIN_FRAME_REFUSED') from None
        finally:sql=None;rows.clear();marker=None
    def close(self):
        p=self.p
        try:
            if p is not None:
                deadline=min(self.owner.end,time.monotonic()+4)
                if not self.failed:
                    self.send(b'quit\n',deadline);p.stdin.close();self.status['stdin_closed']=True
                    while self.eof!=[True,True] or p.poll() is None:
                        ready,_,_=select.select([pipe for i,pipe in enumerate([p.stdout,p.stderr]) if not self.eof[i]],[],[],min(.1,self.remaining(deadline)))
                        self.take(ready)
                if p.poll() is None:p.kill() # Owned CLI only; backend state remains UNKNOWN on this path.
                p.wait(timeout=min(1,max(.01,self.owner.end-time.monotonic())));self.status['exit']=p.returncode;self.status['reaped']=p.returncode is not None
        except Exception as exc:self.status['close_error_class']=type(exc).__name__;self.failed=True
        finally:
            if p is not None:
                if p.poll() is None:
                    try:p.kill();p.wait(timeout=min(1,max(.01,self.owner.end-time.monotonic())));self.status['reaped']=p.returncode is not None;self.status['exit']=p.returncode
                    except Exception:self.status['reaped']=False
                for pipe in [p.stdin,p.stdout,p.stderr]:
                    try:pipe.close()
                    except Exception:self.failed=True
            self.status['stdout_eof'],self.status['stderr_eof']=self.eof
            self.status['capture_complete']=self.eof==[True,True] and self.status['stdin_closed'] and not self.status['timeout'] and not self.status['overflow']
            ok=not self.failed and self.status['exit']==0 and self.status['reaped'] and self.status['capture_complete'] and not self.status['stderr_present'] and not self.pending
            if not ok:self.owner.receipt['transport_uncertain']=True
            self.pending.clear();self.owner.receipt['commands'].append(self.status);self.owner.record('private_admin_complete',self.status)
        if not ok:raise Refusal('PRIVATE_ADMIN_CLOSURE_UNVERIFIED')
