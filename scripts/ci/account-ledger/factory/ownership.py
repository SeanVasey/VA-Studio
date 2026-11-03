"""Pure source profile/phase guards. No Docker/SQL/filesystem activity at import."""
import copy,re
class Refusal(RuntimeError):pass
def need(ok):
    if not ok:raise Refusal('OWNERSHIP_PROFILE_REFUSED')
def php(row,spec):
    need(row['Id']==spec['php_id'] and row['Name']==spec['php_name'] and row['Image']==spec['php_image'] and row['Created']==spec['php_created'])
    s=row['State'];need(s['Running'] is True and all(s[k] is False for k in ['Paused','Restarting','Dead','OOMKilled']))
    need(row['NetworkMode']=='bridge' and row['Networks']=={'bridge':spec['bridge_id']})
    return {'exact_php_identity':True,'network_unchanged':True,'shared_bridge_not_isolated':True}
def created(row,spec,phase,prior=None):
    need(phase in ['bootstrap','main'] and re.fullmatch('[0-9a-f]{64}',row['Id']) is not None)
    need(row['Name']=='/'+spec['container_name'] and row['Image']==spec['image_id'] and type(row['RestartCount']) is int and row['RestartCount']==0)
    need((prior is None) if phase=='bootstrap' else (type(prior) is str and row['Id']!=prior))
    h=row['HostConfig'];need(h['Memory']==2147483648 and h['MemorySwap']==2147483648 and h['NanoCpus']==1000000000 and h['PidsLimit']==256 and h['Init'] is False and h['RestartPolicy']=={'Name':'no','MaximumRetryCount':0} and h['OomKillDisable'] is False)
    need(h['NetworkMode']==('none' if phase=='bootstrap' else 'bridge') and h['LogConfig']==spec['startup_logging'])
    need(row['Config']['Labels'].get(spec['owner_label'])==spec['owner_value'] and row['Config']['Entrypoint']==['sh'])
    need(len(row['Mounts'])==1 and row['Mounts'][0]['Type']=='volume' and row['Mounts'][0]['Name']==spec['volume_name'] and row['Mounts'][0]['Destination']=='/var/lib/mysql' and row['Mounts'][0]['RW'] is True)
    return copy.deepcopy({k:row[k] for k in ['Id','Name','Image','Created','Config','HostConfig','Mounts']})
def running(anchor,row,spec,phase):
    expected=copy.deepcopy(anchor);need(expected['HostConfig']['OomKillDisable'] is False and row['HostConfig']['OomKillDisable'] is None)
    expected['HostConfig']['OomKillDisable']=None # Explicit Docker28.4 creation→running field normalization only.
    need({k:row[k] for k in expected}==expected)
    s=row['State'];need(s['Running'] is True and s['Status']=='running' and type(s['Pid']) is int and s['Pid']>0 and type(row['RestartCount']) is int and row['RestartCount']==0 and all(s[k] is False for k in ['Paused','Restarting','Dead','OOMKilled']) and s['Error']=='')
    need(type(s['StartedAt']) is str and not s['StartedAt'].startswith('0001-'))
    if phase=='bootstrap':need(set(row['NetworkSettings']['Networks'])=={'none'} and row['NetworkSettings']['Ports']=={})
    else:
        need(set(row['NetworkSettings']['Networks'])=={'bridge'} and row['NetworkSettings']['Networks']['bridge']['NetworkID']==spec['bridge_id'])
        need({k:v for k,v in row['NetworkSettings']['Ports'].items() if v is not None}=={'3306/tcp':[{'HostIp':'127.0.0.1','HostPort':str(spec['host_port'])}]})
    return {'id':row['Id'],'started_at':s['StartedAt'],'pid':s['Pid']}
def stopped(anchor,row,process_anchor):
    need({k:row[k] for k in anchor}=={**anchor,'HostConfig':{**anchor['HostConfig'],'OomKillDisable':None}})
    s=row['State'];need(row['Id']==process_anchor['id'] and s['StartedAt']==process_anchor['started_at'] and s['Status']=='exited' and s['Running'] is False and s['Pid']==0 and type(s['ExitCode']) is int and s['ExitCode']==0 and type(row['RestartCount']) is int and row['RestartCount']==0 and all(s[k] is False for k in ['Paused','Restarting','Dead','OOMKilled']) and s['Error']=='')
    return True
