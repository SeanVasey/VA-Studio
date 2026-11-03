#!/usr/bin/env python3
"""Runtime identity/API availability only; accepted supervisor proves actual custody."""
import hashlib
import json
import os
from pathlib import Path
import signal
import socket
import sys

try:
    spec = json.loads(Path('/qualification/runtime-policy.json').read_bytes())['runtime']
    if not (sys.version.split()[0] == spec['python_version']
            and os.path.realpath(sys.executable) == spec['python_binary']
            and hashlib.sha256(Path(sys.executable).read_bytes()).hexdigest() == spec['python_binary_sha256']
            and os.geteuid() == 1000 and os.getegid() == 1000
            and all(hasattr(os, key) for key in ('pidfd_open', 'fork', 'waitpid'))
            and hasattr(signal, 'pidfd_send_signal')
            and all(hasattr(socket, key) for key in ('SO_PASSCRED', 'SCM_CREDENTIALS', 'SOCK_SEQPACKET'))
            and not Path('/var/run/docker.sock').exists()):
        raise ValueError('Runtime refused')
    print('{"profile_match":true}')
except BaseException:
    sys.exit(2)
