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
CLIENT_SETTING = re.compile(rb"/\*!\d{5} SET (?:@saved_cs_client[ \t]+=[ \t]+@@character_set_client|character_set_client[ \t]+=[ \t]+[a-zA-Z0-9_]+) \*/;\r?\n?\Z")
DELIMITER = re.compile(rb"DELIMITER[ \t]+([^\s]+)[ \t]*\r?\n?\Z", re.I)
UNSUPPORTED_MODE = re.compile(rb"(?:/\*!\d{5} )?SET [^\r\n]*\bSQL_MODE\b[^\r\n]*\bNO_BACKSLASH_ESCAPES\b", re.I)
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


class SqlContext:
    """Track lexical context so routine string/comment bytes cannot become directives."""
    def __init__(self):
        self.quote, self.block, self.escaped = None, False, False

    def outside(self):
        return self.quote is None and not self.block

    def consume(self, line):
        offset = 0
        while offset < len(line):
            byte = line[offset]
            following = line[offset + 1:offset + 2]
            if self.block:
                if byte == 42 and following == b"/":
                    self.block = False
                    offset += 1
            elif self.quote is not None:
                if self.escaped:
                    self.escaped = False
                elif byte == 92 and self.quote in (39, 34):
                    self.escaped = True
                elif byte == self.quote:
                    if following == bytes([self.quote]):
                        offset += 1
                    else:
                        self.quote = None
            elif byte in (39, 34, 96):
                self.quote = byte
            elif byte == 47 and following == b"*":
                self.block = True
                offset += 1
            elif byte == 35 or (byte == 45 and following == b"-" and (offset + 2 == len(line) or line[offset + 2] <= 32)):
                break
            offset += 1


def normalize(lines):
    pending, in_table, delimiter = None, False, b";"
    context = SqlContext()
    for line in lines:
        original = line
        outside = context.outside()
        if outside and UNSUPPORTED_MODE.match(line):
            # The known dump contract enables backslash escapes. Unknown lexical modes cannot
            # safely admit later schema spellings; fail instead of approximating string boundaries.
            raise ValueError("unsupported dump lexical mode")
        directive = DELIMITER.fullmatch(line) if outside else None
        if directive:
            delimiter = directive[1]
            pending, in_table = None, False
        if directive:
            yield line
            continue
        if delimiter != b";" or not outside:
            context.consume(original)
            yield line
            continue
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
        context.consume(original)
        yield line


def main():
    if len(sys.argv) != 2:
        return 2
    try:
        with open(sys.argv[1], "rb") as source:
            for line in normalize(source):
                sys.stdout.buffer.write(line)
    except (OSError, ValueError):
        sys.stderr.write("dump normalization unavailable\n")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
