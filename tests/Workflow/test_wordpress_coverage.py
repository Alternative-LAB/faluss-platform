"""Reject missing or invalid evidence when parallel recipes replace repeated runs."""

import importlib.util
import json
import os
import subprocess
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/validate-wordpress-coverage.py'
SPEC = importlib.util.spec_from_file_location('wordpress_coverage', SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class WordPressCoverageTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.directory = Path(temporary.name)
        self.baseline = {'required_reports': ['core.json', 'domain.json'],
                         'assertions': ['Core authorization', 'Domain recovery']}
        self.write('core.json', ['Core authorization'])
        self.write('domain.json', ['Domain recovery'])

    def write(self, name, checks, **fields):
        (self.directory / name).write_text(json.dumps(dict(checks=checks, count=len(checks), **fields)))

    def validate(self):
        return MODULE.validate_reports(self.directory, self.baseline)

    def test_accepts_full_coverage_and_new_assertions(self):
        self.write('domain.json', ['Domain recovery', 'New edge case'])
        result = self.validate()
        self.assertEqual(result['unique_assertions'], 3)
        self.assertEqual(result['baseline_assertions'], 2)

    def test_repeated_core_checks_cannot_hide_a_missing_domain_assertion(self):
        self.write('domain.json', ['Core authorization', 'Core authorization'])
        with self.assertRaisesRegex(ValueError, 'Domain recovery'):
            self.validate()

    def test_missing_recipe_report_fails_even_if_another_report_has_its_assertions(self):
        self.write('core.json', self.baseline['assertions'])
        (self.directory / 'domain.json').unlink()
        with self.assertRaisesRegex(ValueError, 'Missing recipe reports'):
            self.validate()

    def test_failed_recipe_report_is_rejected(self):
        self.write('domain.json', ['Domain recovery'], failed=1)
        with self.assertRaisesRegex(ValueError, 'Failed or inconsistent'):
            self.validate()

    def test_inconsistent_counts_and_invalid_assertions_are_rejected(self):
        for report in ({'checks': ['Domain recovery'], 'count': 2},
                       {'checks': [True], 'count': 1},
                       {'checks': 'Domain recovery', 'count': 1}):
            with self.subTest(report=report):
                (self.directory / 'domain.json').write_text(json.dumps(report))
                with self.assertRaises(ValueError):
                    self.validate()


class WordPressGateTest(unittest.TestCase):
    def result(self, job, variable, results):
        workflow = (SCRIPT.parents[1] / '.github/workflows/php-quality.yml').read_text()
        section = workflow.split('  ' + job + ':', 1)[1]
        if job == 'wordpress-coverage':
            section = section.split('  wordpress-integration:', 1)[0]
        code = section.split("run: python3 -c '", 1)[1].split("'", 1)[0]
        return subprocess.run(['python3', '-c', code],
                              env=dict(os.environ, **{variable: json.dumps(results)}),
                              capture_output=True, text=True)

    def test_gates_accept_successful_dependencies(self):
        for job, variable in [('wordpress-coverage', 'RECIPE_RESULTS'),
                              ('wordpress-integration', 'INTEGRATION_RESULTS')]:
            result = self.result(job, variable, {'suite': {'result': 'success'}})
            self.assertEqual(result.returncode, 0, result.stderr)

    def test_gates_reject_failed_cancelled_or_skipped_dependencies(self):
        for state in ('failure', 'cancelled', 'skipped'):
            for job, variable in [('wordpress-coverage', 'RECIPE_RESULTS'),
                                  ('wordpress-integration', 'INTEGRATION_RESULTS')]:
                with self.subTest(job=job, state=state):
                    result = self.result(job, variable, {'suite': {'result': 'success'},
                                                       'other': {'result': state}})
                    self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
