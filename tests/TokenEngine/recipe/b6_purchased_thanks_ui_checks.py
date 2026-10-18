"""Existing native message configuration on the physically isolated pair, not central SSO."""
from concurrent.futures import ThreadPoolExecutor
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
import socket
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

from b6_privacy_http_checks import Forms


def run_checks(root,source,cli_path,fixture,origin,actors,deliver,check):
    fans=fixture['fans'];config=fans/'wp-config.php';before=config.read_text()
    assert 'http://127.0.0.1:9' in before
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    settings=root/'b6-ui-settings.json';adapter=fans/'wp-content/mu-plugins/b6-ui-recipe.php'
    assert not settings.exists() and not adapter.exists()
    config.write_text(before.replace('http://127.0.0.1:9',base));config.chmod(0o600)
    settings.write_text(json.dumps(dict(origin=origin,automatic_messages=True,fictitious_client_secret=secrets.token_urlsafe(32))));settings.chmod(0o600)
    shutil.copy2(source/'tests/TokenEngine/recipe/b6-ui-bootstrap.php',adapter)
    log=(root/'b6-thanks-ui-runtime.log').open('wb');server=None
    try:
        fixture['set_clock']()
        cookies={role:deliver('fixture-cookie',actor=actors[role+'_user']) for role in ('creator','fan')}
        def read(path,cookie=None,fields=None):
            headers={} if cookie is None else {'Cookie':cookie['name']+'='+cookie['value']}
            body=None if fields is None else urllib.parse.urlencode(fields).encode()
            request=urllib.request.Request(base+path,headers=headers,data=body)
            try:response=urllib.request.urlopen(request,timeout=20)
            except urllib.error.HTTPError as error:response=error
            with response:return response.status,response.read().decode('utf8')
        server=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(fans),str(source/'tests/TokenEngine/recipe/b6-ui-router.php')],
            stdout=log,stderr=log,start_new_session=True)
        path='/app/creator/messages?section=automatic'
        for _ in range(80):
            try:page=read(path,cookies['creator']);break
            except (OSError,TimeoutError):
                if server.poll() is not None:raise RuntimeError('Private automatic UI server exited')
                time.sleep(.1)
        else:raise RuntimeError('Private automatic UI server unavailable')
        own=lambda:deliver('thanks-settings',actor=actors['creator_user'])
        check('B6at native protected configuration reads the actual independently enabled welcome and thanks',
            page[0]==200 and 'fu-auto-thanks' in page[1] and 'Les remerciements sont indisponibles' not in page[1]
            and own()['thanks_delivery_available'] is True)
        form=Forms(page[1]).action('kind','thanks')
        fields=dict(form,body='Merci fictif depuis le formulaire natif.',enabled='yes',intent='save')
        old=own()
        check('B6at an invalid native nonce cannot edit or enable the closed thanks source',
            read(path,cookies['creator'],dict(fields,fans_messages_nonce='invalid'))[0]==403 and own()==old)
        check('B6at a native thanks form refuses an image field without changing settings',
            read(path,cookies['creator'],dict(fields,image_id='not-a-public-image'))[0]==400 and own()==old)
        check('B6at a linked Fan cannot reuse the Creator automation form',
            read(path,cookies['fan'],fields)[0]>=400 and own()==old)
        preview=read(path,cookies['creator'],dict(fields,intent='preview'))
        check('B6at native preview escapes and displays text without saving or delivering a message',
            preview[0]==200 and fields['body'] in preview[1] and own()==old)
        with ThreadPoolExecutor(max_workers=2) as pool:statuses=list(pool.map(lambda _:read(path,cookies['creator'],fields)[0],range(2)))
        check('B6at concurrent native edits preserve one version and leave welcome independent',
            sorted(statuses)==[200,409] and own()['thanks']['body']==fields['body'] and own()['welcome']==old['welcome'])
        if os.environ.get('FALUSS_B6_UI_EXPORT') and os.environ.get('FALUSS_B6_THANKS_CAPTURE_COMMAND'):
            out=Path(os.environ['FALUSS_B6_UI_EXPORT']).resolve()
            assert out.parent==Path('/var/tmp') and out.name.startswith('fans-hof-ui-') and not out.is_symlink()
            live=out/'live-session.json';assert not live.exists()
            live.write_text(json.dumps(dict(base=base,cookies=cookies,automatic=dict(path=path))));live.chmod(0o600)
            try:subprocess.run(json.loads(os.environ['FALUSS_B6_THANKS_CAPTURE_COMMAND']),check=True,timeout=240)
            finally:live.unlink(missing_ok=True)
    finally:
        if server is not None and server.poll() is None:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=15)
        log.close();adapter.unlink(missing_ok=True);settings.unlink(missing_ok=True)
        config.write_text(before);config.chmod(0o600)
