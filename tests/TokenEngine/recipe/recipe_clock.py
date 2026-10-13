"""Publish a complete private fixture clock, including during concurrent recipe calls."""
import decimal
import os
import pathlib
import tempfile


def publish_clock(path, value, minimum=decimal.Decimal(0)):
    stamp = format(max(decimal.Decimal(value), minimum), '.6f')
    descriptor, temporary = tempfile.mkstemp(prefix='.' + path.name + '-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            stream.write(stamp)
        os.replace(temporary, path)
    finally:
        pathlib.Path(temporary).unlink(missing_ok=True)
