"""Bounded synthetic loopback sink. Receives one SMTP connection; never uses DNS or external SMTP."""
import json, socket, sys
mode, capture = sys.argv[1:]
server = socket.socket(); server.bind(('127.0.0.1', 0)); server.listen(1); server.settimeout(15)
print(server.getsockname()[1], flush=True)
connection, _ = server.accept(); connection.settimeout(8)
stream = connection.makefile('rwb', buffering=0)
def reply(value): stream.write(value.encode() + b'\r\n')
reply('220 synthetic.test ESMTP')
data = []
while True:
    raw = stream.readline(20000)
    if not raw: break
    line = raw.decode('ascii').rstrip('\r\n')
    if line.startswith('EHLO '): reply('250-synthetic.test'); reply('250 SIZE 16384')
    elif line.startswith('MAIL FROM:'): reply('250 sender accepted')
    elif line.startswith('RCPT TO:'):
        if mode == 'reject_rcpt': reply('550 synthetic refusal'); break
        reply('250 recipient accepted')
    elif line == 'DATA':
        reply('354 send data')
        while True:
            raw = stream.readline(20000)
            if not raw: break
            if raw == b'.\r\n': break
            data.append(raw.decode('ascii'))
        with open(capture, 'w', encoding='utf8') as output: json.dump({'data': ''.join(data)}, output)
        if mode == 'lost_ack': break
        reply('250 synthetic message accepted'); break
    else: reply('500 synthetic unexpected command'); break
connection.close(); server.close()
