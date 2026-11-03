"""Selected inherited transport selectors only; never dump or read credential bodies."""
import os
from pathlib import Path

PROXIES = ('HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy')
INNER_CA = '/run/ledger-network/ca-bundle.pem'


def ca_bundle():
    # The managed system bundle extends ordinary roots with the session CA. Its
    # body is never read here or copied into an image. Docker mounts the selector.
    path = Path(os.environ.get('SSL_CERT_FILE') or '/etc/ssl/certs/ca-certificates.crt')
    if not path.is_absolute() or not path.is_file():
        raise RuntimeError('Combined CA bundle unavailable.')
    return path.resolve()


def build_flags():
    flags = ['--secret', 'id=network_ca,src=' + str(ca_bundle())]
    for key in PROXIES:
        if key in os.environ:
            flags += ['--build-arg', key]  # Docker reads the inherited value; never a logged argument.
    return flags


def container_flags():
    flags = ['--mount', 'type=bind,src=' + str(ca_bundle()) + ',dst=' + INNER_CA + ',readonly']
    for key in PROXIES:
        if key in os.environ:
            flags += ['--env', key]
    return flags


def daemon_environment():
    value = {'PATH': '/usr/local/bin:/usr/bin:/usr/sbin:/bin:/sbin', 'HOME': '/root',
             'SSL_CERT_FILE': str(ca_bundle())}
    value.update({key: os.environ[key] for key in PROXIES if key in os.environ})
    return value


def client_config_mount():
    # Preserve configured registry auth/proxy defaults without opening their files.
    path = Path(os.environ.get('DOCKER_CONFIG') or str(Path.home() / '.docker'))
    if not path.exists():
        return []
    if not path.is_absolute() or not path.is_dir():
        raise RuntimeError('Docker client configuration selector unavailable.')
    return ['--mount', 'type=bind,src=' + str(path.resolve()) + ',dst=/root/.docker,readonly',
            '--env', 'DOCKER_CONFIG=/root/.docker']
