"""A concurrent reader must never see a truncated, private recipe timestamp."""
import concurrent.futures
import decimal
import pathlib
import stat
import tempfile
import threading
import unittest
from unittest import mock

from recipe_clock import publish_clock


class RecipeClockTest(unittest.TestCase):
    def test_primary_minimum_and_private_permissions(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'clock'
            publish_clock(path, '1780000000', decimal.Decimal('1780000001'))
            self.assertEqual('1780000001.000000', path.read_text())
            self.assertEqual(0o600, stat.S_IMODE(path.stat().st_mode))

    def test_reader_sees_old_complete_value_until_atomic_publication(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'clock'
            publish_clock(path, '1780000000')
            import os
            replace = os.replace
            entered, release = threading.Event(), threading.Event()
            def held_replace(source, destination):
                entered.set()
                if not release.wait(5):
                    raise RuntimeError('Clock publication test did not release writer')
                replace(source, destination)
            with mock.patch('recipe_clock.os.replace', side_effect=held_replace):
                with concurrent.futures.ThreadPoolExecutor() as pool:
                    writer = pool.submit(publish_clock, path, '1780000001')
                    try:
                        self.assertTrue(entered.wait(5))
                        self.assertEqual(decimal.Decimal('1780000000'), decimal.Decimal(path.read_text()))
                    finally:
                        release.set()
                    writer.result()
            self.assertEqual('1780000001.000000', path.read_text())

    def test_concurrent_writers_never_publish_empty_or_partial_time(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'clock'
            publish_clock(path, '1780000000')
            def writer(number):
                for _ in range(40):
                    publish_clock(path, str(1780000000 + number))
            def reader():
                for _ in range(1000):
                    self.assertRegex(path.read_text(), r'^178000000[0-7]\.000000$')
            with concurrent.futures.ThreadPoolExecutor(max_workers=11) as pool:
                jobs = [pool.submit(writer, number) for number in range(8)]
                jobs += [pool.submit(reader) for _ in range(3)]
                for job in jobs:
                    job.result()
            self.assertEqual([path], list(path.parent.iterdir()))

    def test_failed_publication_preserves_previous_time_and_cleans_temporary_file(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'clock'
            publish_clock(path, '1780000000')
            with mock.patch('recipe_clock.os.replace', side_effect=OSError('fixture-only failure')):
                with self.assertRaises(OSError):
                    publish_clock(path, '1780000001')
            self.assertEqual('1780000000.000000', path.read_text())
            self.assertEqual([path], list(path.parent.iterdir()))
