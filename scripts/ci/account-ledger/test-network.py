#!/usr/bin/env python3
"""HOST-only selector tests: synthetic proxy values, no network or secret reads."""
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import network


class NetworkControls(unittest.TestCase):
    def test_selected_proxy_and_ca_reach_daemon_without_other_credentials(self):
        with tempfile.NamedTemporaryFile() as bundle, patch.dict(os.environ, {'HTTP_PROXY': 'http://synthetic.invalid:80',
                'HTTPS_PROXY': 'http://synthetic.invalid:80', 'NO_PROXY': 'localhost', 'SSL_CERT_FILE': bundle.name,
                'UNRELATED_SECRET': 'must-not-forward'}, clear=True):
            env = network.daemon_environment()
            self.assertEqual(env['HTTPS_PROXY'], 'http://synthetic.invalid:80')
            self.assertEqual(env['NO_PROXY'], 'localhost')
            self.assertNotIn('UNRELATED_SECRET', env)
            flags = network.build_flags()
            self.assertIn('HTTP_PROXY', flags)
            self.assertFalse(any('synthetic.invalid' in value for value in flags))
            self.assertIn('id=network_ca,src=' + bundle.name, flags)

    def test_running_https_gets_readonly_combined_ca(self):
        with tempfile.NamedTemporaryFile() as bundle, patch.dict(os.environ, {'SSL_CERT_FILE': bundle.name}, clear=True):
            flags = network.container_flags()
            self.assertIn('type=bind,src=' + bundle.name + ',dst=' + network.INNER_CA + ',readonly', flags)

    def test_configured_registry_proxy_directory_is_preserved_without_body_read(self):
        with tempfile.TemporaryDirectory() as config, patch.dict(os.environ, {'DOCKER_CONFIG': config}, clear=True):
            flags = network.client_config_mount()
            self.assertIn('type=bind,src=' + config + ',dst=/root/.docker,readonly', flags)
            self.assertEqual(os.environ['DOCKER_CONFIG'], config)

    def test_no_tls_bypass_or_session_ca_image_copy(self):
        root = Path(__file__).resolve().parent
        for name in ('Runtime.Dockerfile', 'Controller.Dockerfile'):
            source = (root / name).read_text()
            self.assertIn('--mount=type=secret,id=network_ca,required=true', source)
            for unsafe in ('Verify-Peer=false', '--insecure', 'COPY /run/', 'COPY /etc/ssl', 'trusted=yes'):
                self.assertNotIn(unsafe, source)
        source = (root / 'bootstrap.py').read_text() + (root / 'service-producer.py').read_text()
        self.assertNotIn("os.environ['DOCKER_CONFIG'] =", source)


if __name__ == '__main__':
    unittest.main()
