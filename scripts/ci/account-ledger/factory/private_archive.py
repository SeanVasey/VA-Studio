"""Pure, bounded single-file private archive codec. No file/process/I/O actions at import."""
import io
import tarfile

NAMES=frozenset(['root.cnf','dml.cnf','load.txt'])
MAX_PAYLOAD=16384
MAX_ARCHIVE=32768
class ArchiveMismatch(RuntimeError):pass

def require(value,message):
    if not value:raise ArchiveMismatch(message)

def verify(blob,name,payload):
    require(name in NAMES and isinstance(payload,bytes) and len(payload)<=MAX_PAYLOAD,'private expected-file mismatch')
    require(isinstance(blob,bytes) and 1536<=len(blob)<=MAX_ARCHIVE and len(blob)%512==0,'private archive bounds mismatch')
    try:
        with tarfile.open(fileobj=io.BytesIO(blob),mode='r:') as archive:
            members=archive.getmembers();require(len(members)==1,'private member count mismatch')
            member=members[0]
            require(member.name==name and member.isreg() and member.uid==0 and member.gid==0 and member.mode==0o600 and member.size==len(payload) and not member.linkname and not member.pax_headers and member.offset==0 and member.offset_data==512,'private regular header mismatch')
            end=512+((member.size+511)//512)*512
            require(len(blob)>=end+1024 and not any(blob[512+member.size:]),'private archive terminator/trailing-data mismatch')
            with archive.extractfile(member) as body:require(body.read()==payload,'private payload mismatch')
    except (tarfile.TarError,OSError,EOFError,ValueError,OverflowError):
        raise ArchiveMismatch('private archive parse mismatch') from None
    return {'header_equal':True,'payload_equal':True}

def build(name,payload):
    require(name in NAMES and isinstance(payload,bytes) and len(payload)<=MAX_PAYLOAD,'private expected-file mismatch')
    target=io.BytesIO()
    with tarfile.open(fileobj=target,mode='w',format=tarfile.USTAR_FORMAT) as archive:
        member=tarfile.TarInfo(name);member.uid=0;member.gid=0;member.mode=0o600;member.mtime=0;member.uname='';member.gname='';member.size=len(payload)
        archive.addfile(member,io.BytesIO(payload))
    blob=target.getvalue();verify(blob,name,payload);return blob
