"""Protect release retries against duplicate issues and unavailable GitHub access."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[2]


class ReleaseIssueTest(unittest.TestCase):
    def run_step(self, pages, fail_api=False, token='test-token', mapping=None, available_labels=None):
        workflow = (ROOT / '.github/workflows/prepare-release.yml').read_text()
        step = workflow.split('      - name: Find or create release issue before changing files', 1)[1]
        script = textwrap.dedent(step.split('        run: |\n', 1)[1].split('\n      - name:', 1)[0])
        with tempfile.TemporaryDirectory() as directory:
            temporary = Path(directory)
            (temporary / '.git').write_text('gitdir: ' + str(ROOT / '.git') + '\n')
            (temporary / 'docs').mkdir()
            (temporary / 'docs/ISSUE_TEMPLATE.md').write_text((ROOT / 'docs/ISSUE_TEMPLATE.md').read_text())
            (temporary / 'docs/WORKFLOW_LABELS.json').write_text(
                json.dumps(mapping) if mapping is not None else (ROOT / 'docs/WORKFLOW_LABELS.json').read_text())
            executable = temporary / 'gh'
            executable.write_text('''#!/usr/bin/env python3
import json
import os
from pathlib import Path
import sys
root = Path(os.environ['RUNNER_TEMP'])
with (root / 'calls.jsonl').open('a') as log:
    log.write(json.dumps(sys.argv[1:]) + '\\n')
if sys.argv[1] == 'api':
    if os.environ['FAIL_API'] == '1':
        raise SystemExit(1)
    print(os.environ['LABEL_PAGES'] if '/labels?' in sys.argv[-1] else os.environ['ISSUE_PAGES'])
elif sys.argv[1:3] == ['issue', 'edit']:
    pass
elif sys.argv[1:3] == ['issue', 'create']:
    print('https://github.com/example/plugin/issues/204')
else:
    raise SystemExit(2)
''')
            executable.chmod(0o755)
            env = dict(os.environ, PATH=str(temporary) + os.pathsep + os.environ['PATH'],
                       RUNNER_TEMP=directory, GITHUB_OUTPUT=str(temporary / 'output'),
                       GITHUB_REPOSITORY='example/plugin', GH_TOKEN=token,
                       FAIL_API=str(int(fail_api)), ISSUE_PAGES=json.dumps(pages),
                       LABEL_PAGES=json.dumps([available_labels or []]))
            result = subprocess.run(['bash', '-c', script], cwd=temporary, env=env,
                                    capture_output=True, text=True)
            calls = temporary / 'calls.jsonl'
            output = temporary / 'output'
            body = temporary / 'release-issue.md'
            return (result, calls.read_text() if calls.exists() else '',
                    output.read_text() if output.exists() else '',
                    body.read_text() if body.exists() else '')

    def matching_issue(self, state='open', number=42):
        sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
        return dict(title='Préparer la publication depuis ' + sha, number=number, state=state)

    def test_reuses_open_issue_on_later_page_without_creating(self):
        result, calls, output, body = self.run_step([[], [self.matching_issue()]])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(output, 'number=42\n')
        self.assertNotIn('create', calls)
        self.assertEqual(body, '')

    def test_creates_from_unique_template_when_no_issue_matches(self):
        result, calls, output, body = self.run_step([[]])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(output, 'number=204\n')
        self.assertIn('create', calls)
        self.assertNotIn('<!--', body)
        self.assertIn("## Critères d'acceptation", body)
        self.assertIn('## Informations complémentaires', body)

    def test_closed_issue_blocks_second_publication(self):
        result, calls, output, _ = self.run_step([[self.matching_issue('closed')]])
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('create', calls)
        self.assertEqual(output, '')

    def test_duplicate_issues_block_arbitrary_selection(self):
        result, calls, output, _ = self.run_step([[self.matching_issue(), self.matching_issue(number=43)]])
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('create', calls)
        self.assertEqual(output, '')

    def test_api_failure_is_not_treated_as_empty_search(self):
        result, calls, output, body = self.run_step([], fail_api=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('create', calls)
        self.assertEqual((output, body), ('', ''))

    def test_missing_credentials_prevent_api_calls(self):
        result, calls, output, body = self.run_step([], token='')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((calls, output, body), ('', '', ''))

    def test_missing_configured_label_blocks_creation(self):
        mapping = {'type': {'maintenance': 'chore'}, 'priority': {'normal': None},
                   'status': {'in_progress': None}}
        result, calls, output, body = self.run_step([[]], mapping=mapping)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('create', calls)
        self.assertEqual((output, body), ('', ''))

    def test_applies_configured_labels_to_reused_issue(self):
        mapping = {'type': {'maintenance': 'chore'}, 'priority': {'normal': None},
                   'status': {'in_progress': None}}
        result, calls, output, _ = self.run_step([[self.matching_issue()]], mapping=mapping,
                                                available_labels=[{'name': 'chore'}])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(output, 'number=42\n')
        self.assertIn('"--add-label", "chore"', calls)
        self.assertNotIn('create', calls)


if __name__ == '__main__':
    unittest.main()
