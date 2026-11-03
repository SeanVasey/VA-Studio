"""Bounded owned CLI transport. No children or IO at import; secret input never recorded."""
import os,selectors,subprocess,time
class Refusal(RuntimeError):pass

def local_env():
    env=os.environ.copy()
    for name in ['DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH','DOCKER_API_VERSION']:env.pop(name,None)
    env['DOCKER_HOST']='unix:///var/run/docker.sock'
    return env

class Capture:
    def command(self,args,stage,seconds=12,input_bytes=None,binary=False,accepted=(0,)):
        self.record(stage+'_before',{'fixed_command':args,'private_stdin':input_bytes is not None})
        deadline=min(self.limit,time.monotonic()+seconds)
        if deadline<=time.monotonic():raise Refusal('COMMAND_DEADLINE')
        if input_bytes is not None and (not isinstance(input_bytes,bytes) or len(input_bytes)>32768):raise Refusal('INPUT_CAP')
        p=None;output=[bytearray(),bytearray()];eof=[False,False];private_view=memoryview(input_bytes or b'')
        status={'stage':stage,'exit':None,'reaped':False,'timeout':False,'overflow':False,'stdout_eof':False,'stderr_eof':False,'stdin_closed':input_bytes is None,'capture_complete':False,'stderr_present':False}
        try:
            p=subprocess.Popen(args,stdin=subprocess.PIPE if input_bytes is not None else subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,bufsize=0,env=local_env())
            status['owned_cli_pid']=p.pid
            if stage=='native_diagnostic':
                self.receipt['native_cli_spawned']=True;self.receipt['admission_state']='NATIVE_DIAGNOSTIC_STARTED_RESULT_UNKNOWN'
            self.record(stage+'_child_started',{'owned_cli_pid':p.pid})
            with selectors.DefaultSelector() as selector:
                for channel,pipe in enumerate([p.stdout,p.stderr]):os.set_blocking(pipe.fileno(),False);selector.register(pipe,selectors.EVENT_READ,channel)
                if input_bytes is not None:
                    os.set_blocking(p.stdin.fileno(),False)
                    if private_view:selector.register(p.stdin,selectors.EVENT_WRITE,2)
                    else:p.stdin.close();status['stdin_closed']=True
                while selector.get_map() or p.poll() is None:
                    remaining=deadline-time.monotonic()
                    if remaining<=0:status['timeout']=True;break
                    for key,unused in selector.select(min(.1,remaining)):
                        if key.data==2:
                            try:count=os.write(key.fileobj.fileno(),private_view[:4096])
                            except BlockingIOError:continue
                            if count<=0:raise Refusal('PRIVATE_STDIN_INCOMPLETE')
                            private_view=private_view[count:]
                            if not private_view:selector.unregister(key.fileobj);key.fileobj.close();status['stdin_closed']=True
                            continue
                        try:chunk=os.read(key.fileobj.fileno(),4096)
                        except BlockingIOError:continue
                        if key.data==1 and chunk:status['stderr_present']=True
                        if not chunk:eof[key.data]=True;selector.unregister(key.fileobj)
                        elif len(output[0])+len(output[1])+len(chunk)>65536:status['overflow']=True;break
                        else:
                            if key.data==1:status['stderr_present']=True
                            output[key.data].extend(chunk)
                    if status['overflow']:break
                status['capture_complete']=not selector.get_map() and eof==[True,True] and status['stdin_closed'] and not status['timeout'] and not status['overflow']
        except BaseException as exc:status['transport_error_class']=type(exc).__name__
        finally:
            private_view.release();input_bytes=None
            if p is not None:
                try:
                    if p.poll() is None:p.kill() # CLI only. Never backend-closure evidence.
                    p.wait(timeout=min(1,max(.01,self.end-time.monotonic())));status['reaped']=p.returncode is not None
                except BaseException as exc:status['wait_error_class']=type(exc).__name__
                status['exit']=p.returncode
                for pipe in [p.stdin,p.stdout,p.stderr]:
                    if pipe is not None:
                        try:pipe.close()
                        except BaseException as exc:status['close_error_class']=type(exc).__name__
            status['stdout_eof'],status['stderr_eof']=eof;status['stderr_bytes']=len(output[1]);status['stdout_bytes']=len(output[0])
            if status['stderr_present'] and 'stderr_observed' in self.receipt:self.receipt['stderr_observed']=True
            if not status['reaped'] and 'cli_reap_proven' in self.receipt:self.receipt['cli_reap_proven']=False
            if not status['reaped'] or not status['capture_complete'] or any(k.endswith('_error_class') for k in status):self.receipt['transport_uncertain']=True
            self.receipt['commands'].append(status);self.record(stage+'_complete',status)
        if status['exit'] not in accepted or not status['reaped'] or not status['capture_complete'] or status['stderr_present'] or any(k.endswith('_error_class') for k in status):raise Refusal('BOUNDED_CLI_REFUSED')
        result=bytes(output[0]);output[0].clear();output[1].clear()
        return result if binary else result.decode('utf-8','strict')
