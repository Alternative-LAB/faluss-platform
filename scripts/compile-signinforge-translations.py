"""Compile le catalogue public français sans dépendance système gettext."""
import json
from pathlib import Path
import struct
import sys


def compile_catalog(source, destination):
    messages = json.loads(Path(source).read_text())
    messages[''] = 'Content-Type: text/plain; charset=UTF-8\nLanguage: fr_FR\nPlural-Forms: nplurals=2; plural=(n > 1);\n'
    singular = 'Too many failed login attempts. Please try again in %d minute.'
    plural = 'Too many failed login attempts. Please try again in %d minutes.'
    messages[singular + '\0' + plural] = messages.pop(singular) + '\0' + messages.pop(plural)
    entries = sorted((key.encode(), value.encode()) for key, value in messages.items())
    count = len(entries)
    start = 28 + 16 * count
    keys = b''
    values = b''
    key_table = []
    value_table = []
    for key, value in entries:
        key_table.append((len(key), start + len(keys)))
        keys += key + b'\0'
        value_table.append((len(value), len(values)))
        values += value + b'\0'
    content = struct.pack('<7I', 0x950412DE, 0, count, 28, 28 + 8 * count, 0, 0)
    content += b''.join(struct.pack('<2I', *entry) for entry in key_table)
    content += b''.join(struct.pack('<2I', length, start + len(keys) + offset) for length, offset in value_table)
    Path(destination).write_bytes(content + keys + values)


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Usage: compile-signinforge-translations.py source.json destination.mo')
    compile_catalog(sys.argv[1], sys.argv[2])
