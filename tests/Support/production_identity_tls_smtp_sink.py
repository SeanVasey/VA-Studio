"""Synthetic TLS/AUTH fixture. Literal loopback only; no DNS or external sends."""
import base64
import email.policy
from email.parser import BytesParser
import json
import socket
import ssl
import sys

security, mode, capture, certificate, key = sys.argv[1:]
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.minimum_version = ssl.TLSVersion.TLSv1_2
context.load_cert_chain(certificate, key)
server = socket.socket()
server.bind(('127.0.0.1', 0))
server.listen(1)
server.settimeout(12)
print(server.getsockname()[1], flush=True)
connection = None
stream = None
facts = {'authenticated': False, 'tls': False, 'data': '', 'body': ''}


def reply(value):
    stream.write(value.encode('ascii') + b'\r\n')


def line():
    value = stream.readline(20001)
    if len(value) > 20000 or not value.endswith(b'\r\n'):
        raise ValueError('synthetic bounded command')
    return value.rstrip(b'\r\n')


try:
    connection, _ = server.accept()
    connection.settimeout(6)
    if security == 'implicit_tls':
        connection = context.wrap_socket(connection, server_side=True)
        facts['tls'] = True
    stream = connection.makefile('rwb', buffering=0)
    reply('220 synthetic.test ESMTP')
    for command_count in range(48):
        raw = line()
        if raw.startswith(b'EHLO '):
            reply('250-synthetic.test')
            if not facts['tls'] and mode != 'no_tls':
                reply('250-STARTTLS')
            if facts['tls'] and mode != 'no_auth':
                reply('250-AUTH LOGIN')
            reply('250 SIZE 16384')
        elif raw == b'STARTTLS' and not facts['tls'] and mode != 'no_tls':
            reply('220 start TLS')
            stream.close()
            connection = context.wrap_socket(connection, server_side=True)
            stream = connection.makefile('rwb', buffering=0)
            facts['tls'] = True
        elif raw == b'AUTH LOGIN' and facts['tls']:
            reply('334 VXNlcm5hbWU6')
            username = base64.b64decode(line(), validate=True)
            reply('334 UGFzc3dvcmQ6')
            password = base64.b64decode(line(), validate=True)
            if username != b'synthetic-account' or password != b'SyntheticSmtpPassword123':
                reply('535 synthetic auth refusal')
            else:
                facts['authenticated'] = True
                reply('235 synthetic auth accepted')
        elif raw.startswith(b'MAIL FROM:') and facts['tls']:
            reply('250 sender accepted')
        elif raw.startswith(b'RCPT TO:') and facts['tls']:
            reply('550 synthetic recipient refusal' if mode == 'reject_rcpt' else '250 recipient accepted')
        elif raw == b'DATA' and facts['authenticated']:
            reply('354 send data')
            data = bytearray()
            while len(data) <= 16384:
                value = line()
                if value == b'.':
                    break
                if value.startswith(b'..'):
                    value = value[1:]
                data.extend(value + b'\r\n')
            if len(data) > 16384:
                raise ValueError('synthetic bounded data')
            facts['data'] = data.decode('ascii')
            message = BytesParser(policy=email.policy.default).parsebytes(bytes(data))
            facts['body'] = message.get_content()
            if mode == 'lost_ack':
                break
            reply('250 synthetic message accepted')
        elif raw == b'RSET':
            reply('250 synthetic reset')
        elif raw == b'QUIT':
            reply('221 synthetic close')
            break
        else:
            reply('500 synthetic unexpected command')
            break
except (OSError, ValueError, ssl.SSLError):
    # Expected certificate/refusal/disconnect cases retain only nonsecret synthetic facts.
    pass
finally:
    with open(capture, 'w', encoding='utf8') as output:
        json.dump(facts, output)
    if stream is not None:
        stream.close()
    if connection is not None:
        connection.close()
    server.close()
