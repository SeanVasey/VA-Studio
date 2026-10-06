#!/usr/bin/env python3
"""Development evidence is deliberately distinct from complete acceptance."""
import json
import os
import sys


def accept(needs):
    if set(needs) != {'scope', 'documentation', 'frontend', 'backend-quality'}:
        raise ValueError('Missing or unexpected preflight job')
    if needs['scope'].get('result') != 'success':
        raise ValueError('Scope classification did not succeed')
    mode = needs['scope'].get('outputs', {}).get('mode')
    if mode not in {'docs', 'full'}:
        raise ValueError('Unknown scope mode')
    expected = {'documentation': 'success' if mode == 'docs' else 'skipped',
                'frontend': 'skipped' if mode == 'docs' else 'success',
                'backend-quality': 'skipped' if mode == 'docs' else 'success'}
    if any(needs[name].get('result') != result for name, result in expected.items()):
        raise ValueError('Preflight checks are incomplete or unsuccessful')
    return mode


if __name__ == '__main__':
    try:
        accept(json.loads(os.environ['NEEDS_JSON']))
    except (ValueError, TypeError, KeyError, AttributeError) as error:
        sys.exit(f'Development preflight rejected: {error}')
    print('Development preflight passed. Full database and browser suites were NOT executed.')
