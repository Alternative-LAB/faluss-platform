"""Keep corpus setup refusals observable without disclosing signed fixture data."""
import importlib.util
from pathlib import Path
import tempfile
import unittest


SOURCE = Path(__file__).resolve().parents[1] / 'TokenEngine/recipe/b3_corpus_reader_checks.py'
SPEC = importlib.util.spec_from_file_location('corpus_reader', SOURCE)
READER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(READER)


class CorpusHttpDiagnosticsTest(unittest.TestCase):
    def fixture(self, status):
        return {
            'request': lambda progress: ('private fields', 'private signed request', None),
            'proof_for': lambda fields, sealed: (status, {'wire': 'private signed reply'}),
        }

    def test_success_returns_the_original_proof(self):
        with tempfile.TemporaryDirectory() as directory:
            proof = READER.lookup_proof(Path(directory), self.fixture(200), {})
        self.assertEqual(proof, {'wire': 'private signed reply'})

    def test_refusal_reports_status_reason_and_available_space(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'corpus-http-diagnostic').write_text('pf_nonce_rejected')
            with self.assertRaises(RuntimeError) as caught:
                READER.lookup_proof(root, self.fixture(403), {})
        message = str(caught.exception)
        self.assertIn('"status": 403', message)
        self.assertIn('pf_nonce_rejected', message)
        self.assertIn('temporary_free_bytes', message)
        self.assertNotIn('private', message)

    def test_missing_reason_still_fails_with_http_status(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(RuntimeError, '"status": 503'):
                READER.lookup_proof(Path(directory), self.fixture(503), {})

    def test_arbitrary_diagnostic_contents_are_not_disclosed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'corpus-http-diagnostic').write_text('secret password / private payload')
            with self.assertRaises(RuntimeError) as caught:
                READER.lookup_proof(root, self.fixture(403), {})
        self.assertIn('unavailable', str(caught.exception))
        self.assertNotIn('secret', str(caught.exception))


if __name__ == '__main__':
    unittest.main()
