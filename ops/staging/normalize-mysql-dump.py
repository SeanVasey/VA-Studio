#!/usr/bin/env python3
"""Normalize only MySQL's optional utf8mb4 column charset attribute.

Bytes in rows, defaults, comments, identifiers, routines and triggers remain exact.
Only mysqldump's CREATE TABLE block following its matching DROP TABLE is eligible.
Unknown layouts remain unchanged, so the restore comparison fails closed.
"""
import re
import sys


IDENTIFIER = rb"`(?:``|[^`])+`"
DROP = re.compile(rb"DROP TABLE IF EXISTS (" + IDENTIFIER + rb");\r?\n?\Z")
CREATE = re.compile(rb"CREATE TABLE (" + IDENTIFIER + rb") \(\r?\n?\Z")
CLIENT_SETTING = re.compile(rb"/\*!\d{5} SET (?:@saved_cs_client = @@character_set_client|character_set_client = [a-zA-Z0-9_]+) \*/;\r?\n?\Z")
COLUMN = re.compile(rb"(  " + IDENTIFIER + rb" )(char|varchar|tinytext|text|mediumtext|longtext|enum|set)\b", re.I)
CHARSET = re.compile(rb" CHARACTER SET utf8mb4(?= COLLATE utf8mb4_[a-zA-Z0-9_]+(?: |,|\r?$))")


def type_end(line, offset):
    """Consume a type's balanced parameters without inspecting its string contents."""
    if line[offset:offset + 1] != b"(":
        return offset
    depth, quote, escaped = 0, None, False
    while offset < len(line):
        byte = line[offset]
        if quote is not None:
            if escaped:
                escaped = False
            elif byte == 92:
                escaped = True
            elif byte == quote:
                if offset + 1 < len(line) and line[offset + 1] == quote:
                    offset += 1
                else:
                    quote = None
        elif byte in (39, 34):
            quote = byte
        elif byte == 40:
            depth += 1
        elif byte == 41:
            depth -= 1
            if depth == 0:
                return offset + 1
        offset += 1
    return None


def normalize(lines):
    pending, in_table = None, False
    for line in lines:
        drop = DROP.fullmatch(line)
        create = CREATE.fullmatch(line)
        if drop:
            pending, in_table = drop[1], False
        elif create:
            in_table = pending == create[1]
            pending = None
        elif pending is not None and not CLIENT_SETTING.fullmatch(line):
            pending = None
        elif in_table and line.startswith(b")"):
            in_table = False
        if in_table:
            column = COLUMN.match(line)
            if column:
                end = type_end(line, column.end())
                if end is not None:
                    attribute = CHARSET.match(line, end)
                    if attribute:
                        line = line[:end] + line[attribute.end():]
        yield line


def main():
    if len(sys.argv) != 2:
        return 2
    try:
        with open(sys.argv[1], "rb") as source:
            for line in normalize(source):
                sys.stdout.buffer.write(line)
    except OSError:
        sys.stderr.write("dump normalization unavailable\n")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
