"""Keep fixture code changes visible and HTTP cache overrides effective."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest

WRAPPER = Path(__file__).resolve().parents[2] / '.github/actions/wordpress-runtime/php'


class CliBytecodeCacheTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.cache = self.root / 'cache'
        self.cache.mkdir()
        (self.cache / 'blacklist').write_text('')
        self.env = dict(os.environ, FALUSS_CI_PHP_BINARY=shutil.which('php'),
                        FALUSS_CI_PHP_CACHE=str(self.cache))

    def run_php(self, *arguments):
        result = subprocess.run(['bash', str(WRAPPER), *arguments], env=self.env,
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout

    def eligible(self, path, content):
        path.write_text(content)
        previous = time.time() - 10
        os.utime(path, (previous, previous))

    def test_cached_script_changes_are_revalidated(self):
        script = self.root / 'fixture.php'
        self.eligible(script, '<?php echo "before";')
        self.assertEqual(self.run_php(str(script)), 'before')
        self.assertTrue(list(self.cache.rglob('*.bin')), 'OPcache must actually cache code')
        script.write_text('<?php echo "after";')
        self.assertEqual(self.run_php(str(script)), 'after')

    def test_http_fixtures_can_disable_the_cli_cache(self):
        result = self.run_php('-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
                              '-r', 'echo json_encode([ini_get("opcache.enable"), ini_get("opcache.enable_cli")]);')
        self.assertEqual(json.loads(result), ['0', '0'])

    def test_fixture_configuration_is_not_persisted_as_bytecode(self):
        site = self.root / 'nested' / 'wordpress'
        site.mkdir(parents=True)
        config = site / 'wp-config.php'
        self.eligible(config, '<?php echo "fixture configuration";')
        self.assertEqual(self.run_php(str(config), '--path=' + str(site)), 'fixture configuration')
        self.assertFalse(list(self.cache.rglob('*wp-config.php.bin')))


    def test_http_server_arguments_are_forwarded_without_cache_flags(self):
        binary = self.root / 'php-recorder'
        binary.write_text('#!/usr/bin/env python3\nimport json, sys\nprint(json.dumps(sys.argv[1:]))\n')
        binary.chmod(0o755)
        self.env['FALUSS_CI_PHP_BINARY'] = str(binary)
        arguments = ['-S', '127.0.0.1:9999', '-t', str(self.root)]
        self.assertEqual(json.loads(self.run_php(*arguments)), arguments)


if __name__ == '__main__':
    unittest.main()
