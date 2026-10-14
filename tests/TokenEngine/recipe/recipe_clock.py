"""Publish a complete private fixture clock, including during concurrent recipe calls."""
import decimal
import os
import pathlib
import tempfile
import threading


class RecipeClock:
    """All captured fixture copies share the same explicitly raised primary minimum."""
    def __init__(self, path, read):
        self.path, self.read = path, read
        self.minimum = decimal.Decimal(0)
        self.lock = threading.Lock()

    def __call__(self, minimum=0):
        with self.lock:
            self.minimum = max(self.minimum, decimal.Decimal(minimum))
            publish_clock(self.path, self.read(), self.minimum)


def publish_clock(path, value, minimum=decimal.Decimal(0)):
    stamp = format(max(decimal.Decimal(value), minimum), '.6f')
    descriptor, temporary = tempfile.mkstemp(prefix='.' + path.name + '-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            stream.write(stamp)
        os.replace(temporary, path)
    finally:
        pathlib.Path(temporary).unlink(missing_ok=True)
