#!/usr/bin/env python3
"""HOST-only Git object/path controls; no Git command or snapshot is executed."""
import hashlib
import importlib.util
from pathlib import Path
import unittest

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('source_snapshot', HERE / 'source-snapshot.py')
snapshot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(snapshot)
EMPTY = 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391'


class SourceControls(unittest.TestCase):
    def test_known_git_object_ids(self):
        self.assertEqual(snapshot.object_id('blob', b''), EMPTY)
        self.assertEqual(snapshot.object_id('blob', b'hello world\n'),
                         '3b18e512dba79e4c8300dd08aeb37f8e728b8dad')
        self.assertEqual(snapshot.tree_id({}), '4b825dc642cb6eb9a060e54bf8d69288fbee4904')

    def test_git_directory_sorting_and_executable_mode_bind_tree(self):
        subtree = hashlib.sha1(b'tree 29\0' + b'100755 z\0' + bytes.fromhex(EMPTY)).digest()
        payload = b'100644 a.c\0' + bytes.fromhex(EMPTY) + b'40000 a\0' + subtree
        expected = hashlib.sha1(b'tree ' + str(len(payload)).encode() + b'\0' + payload).hexdigest()
        files = {'a/z': {'mode': '100755', 'git_oid': EMPTY},
                 'a.c': {'mode': '100644', 'git_oid': EMPTY}}
        self.assertEqual(snapshot.tree_id(files), expected)
        files['a/z']['mode'] = '100644'
        self.assertNotEqual(snapshot.tree_id(files), expected)

    def test_incomplete_or_nonblob_listing_refuses(self):
        for raw in (b'', b'100644 blob ' + EMPTY.encode() + b'\tx',
                    b'120000 blob ' + EMPTY.encode() + b'\tx\0',
                    b'160000 commit ' + EMPTY.encode() + b'\tx\0'):
            with self.subTest(raw=raw), self.assertRaises((snapshot.runtime.Refusal, ValueError)):
                snapshot.listing(raw)

    def test_duplicate_and_ambient_environment_objects_refuse(self):
        row = b'100644 blob ' + EMPTY.encode() + b'\tx\0'
        for raw in (row + row, row.replace(b'\tx\0', b'\t.env\0'),
                    row.replace(b'\tx\0', b'\t.env.testing\0')):
            with self.subTest(raw=raw), self.assertRaises(snapshot.runtime.Refusal):
                snapshot.listing(raw)

    def test_path_escapes_and_git_metadata_refuse(self):
        for name in ('/etc/passwd', '../out', 'a/../out', 'a//b', './x', '.git/config',
                     'a/.git/objects', 'a\\b', 'a\nb'):
            with self.subTest(name=name), self.assertRaises(snapshot.runtime.Refusal):
                snapshot.safe_name(name)

    def test_file_directory_collision_refuses(self):
        for names in (('a', 'a/b'), ('a/b', 'a')):
            with self.subTest(names=names), self.assertRaises(snapshot.runtime.Refusal):
                snapshot.tree_id({name: {'mode': '100644', 'git_oid': EMPTY} for name in names})


if __name__ == '__main__':
    unittest.main()
