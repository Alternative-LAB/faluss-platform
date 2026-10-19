"""Run the trusted governance step against valid and incomplete PR bodies."""

import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[2]
SECTIONS = ['Objectif', 'Changements', 'Changelog', 'Vérifications effectuées',
            'Intégration et absence de régression', 'Risques et retour arrière',
            'Captures d’écran']
CATEGORIES = ['Ajouté', 'Modifié', 'Corrigé', 'Supprimé', 'Migration et compatibilité']


def valid_body():
    return '\n\n'.join('## ' + heading + '\n\n' + (
        '\n\n'.join('### ' + category + '\n\n- Sans objet' for category in CATEGORIES)
        if heading == 'Changelog' else 'Sans objet') for heading in SECTIONS)


class PrTemplateTest(unittest.TestCase):
    def validate(self, body):
        workflow = (ROOT / '.github/workflows/pr-governance.yml').read_text()
        step = workflow.split('      - name: Validate required PR sections', 1)[1]
        script = textwrap.dedent(step.split('        run: |\n', 1)[1].split('\n      - name:', 1)[0])
        return subprocess.run(['bash', '-c', script], env=dict(os.environ, PR_BODY=body),
                              capture_output=True, text=True)

    def test_accepts_complete_template_and_windows_newlines(self):
        for body in (valid_body(), valid_body().replace('\n', '\r\n')):
            self.assertEqual(self.validate(body).returncode, 0)

    def test_requires_every_top_level_section(self):
        for heading in SECTIONS:
            with self.subTest(heading=heading):
                self.assertNotEqual(self.validate(valid_body().replace('## ' + heading, '## Omitted')).returncode, 0)

    def test_requires_every_changelog_category(self):
        for heading in CATEGORIES:
            with self.subTest(heading=heading):
                self.assertNotEqual(self.validate(valid_body().replace('### ' + heading, '### Omitted')).returncode, 0)

    def test_rejects_empty_changelog_categories(self):
        body = valid_body().replace('### Ajouté\n\n- Sans objet', '### Ajouté\n\n<!-- Fill this -->')
        self.assertNotEqual(self.validate(body).returncode, 0)

    def test_commented_sections_do_not_satisfy_requirements(self):
        body = valid_body().replace('## Captures d’écran\n\nSans objet', '<!--\n## Captures d’écran\n\nSans objet\n-->')
        self.assertNotEqual(self.validate(body).returncode, 0)

    def test_generated_release_pr_satisfies_the_same_template(self):
        workflow = (ROOT / '.github/workflows/prepare-release.yml').read_text()
        step = workflow.split('      - name: Open release pull request', 1)[1]
        script = textwrap.dedent(step.split('        run: |\n', 1)[1]).split('gh pr create', 1)[0]
        with tempfile.TemporaryDirectory() as directory:
            result = subprocess.run(['bash', '-c', script], capture_output=True, text=True,
                                    env=dict(os.environ, GH_TOKEN='fixture', RUNNER_TEMP=directory,
                                             RELEASE_VERSION='1.2.3'))
            self.assertEqual(result.returncode, 0, result.stderr)
            result = self.validate((Path(directory) / 'release-pr.md').read_text())
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
