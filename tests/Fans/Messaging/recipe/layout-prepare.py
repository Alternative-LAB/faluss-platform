"""Prepare only a fresh admission-wordpress.py --backoffice disposable fixture."""
import argparse, json, pathlib, re, subprocess

p = argparse.ArgumentParser()
p.add_argument('--root', required=True)
p.add_argument('--base', required=True)
p.add_argument('--cli', required=True)
a = p.parse_args()
root = pathlib.Path(a.root).resolve()
assert re.fullmatch(r'/var/tmp/fans-admission-wp-[a-z0-9_]+', str(root))
assert re.fullmatch(r'http://127\.0\.0\.1:[0-9]+', a.base)
config = root/'wordpress/wp-config.php'
text = config.read_text()
assert 'define("DB_NAME","admission_recipe");' in text
assert (root/'session.json').is_file()
for flag in ['FALUSS_PLATFORM_FANS_IMAGE_DELIVERY', 'FALUSS_PLATFORM_FANS_MESSAGING', 'FALUSS_FANS_MESSAGING_POLICY_ATTESTED']:
    assert flag not in text, 'Use a fresh disposable fixture'
    text = text.replace('<?php\n', '<?php\ndefine('+json.dumps(flag)+',true);\n', 1)
config.write_text(text)
(root/'wordpress/wp-content/mu-plugins/local-rest.php').write_text("<?php add_filter('rest_url',static function($url){return str_replace('https://fans.example.test',"+json.dumps(a.base)+",$url);});")
subprocess.run(['php', a.cli, '--allow-root', '--path='+str(root/'wordpress'), 'eval-file', str(pathlib.Path(__file__).with_name('layout-seed.php')), str(root/'session.json'), '--use-include'], check=True)
