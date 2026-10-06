#!/usr/bin/env python3
"""Reject a stale or ambiguous full-suite dispatch before expensive jobs start."""
import os
import re
import subprocess
import sys


def verify(event, expected, event_sha, actual):
    return (event == 'workflow_dispatch' and re.fullmatch(r'[0-9a-f]{40}', expected) is not None
            and expected == event_sha == actual)


def verify_gitlab(enabled, event, expected, event_sha, actual):
    return (enabled == 'true' and event in {'web', 'api'}
            and re.fullmatch(r'[0-9a-f]{40}', expected) is not None
            and expected == event_sha == actual)


if __name__ == '__main__':
    actual = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
    if sys.argv[1:] == ['--gitlab']:
        accepted = verify_gitlab(os.environ.get('GITLAB_CI', ''), os.environ.get('CI_PIPELINE_SOURCE', ''),
                                 os.environ.get('EXPECTED_SHA', ''), os.environ.get('CI_COMMIT_SHA', ''), actual)
    else:
        accepted = not sys.argv[1:] and verify(os.environ.get('GITHUB_EVENT_NAME', ''),
                                             os.environ.get('EXPECTED_SHA', ''), os.environ.get('GITHUB_SHA', ''), actual)
    if not accepted:
        sys.exit('Full verification refused: explicitly dispatch the exact reviewed 40-character SHA.')
    print(f'Final verification candidate: {actual}')
