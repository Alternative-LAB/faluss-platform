"""Protected native Fan choices on the actual disposable pair. No real SSO or purchase."""
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


def run_checks(root,source,fixture,origin,actors,deliver,own,config,hub_sql,check):
    fans=fixture['fans'];wp_config=fans/'wp-config.php';before=wp_config.read_text()
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    settings=root/'b6-ui-settings.json';adapter=fans/'wp-content/mu-plugins/b6-ui-recipe.php'
    assert 'http://127.0.0.1:9' in before and not settings.exists() and not adapter.exists()
    wp_config.write_text(before.replace('http://127.0.0.1:9',base));wp_config.chmod(0o600)
    settings.write_text(json.dumps(dict(origin=origin,attribution=config,fictitious_client_secret=secrets.token_urlsafe(32))));settings.chmod(0o600)
    shutil.copy2(source/'tests/TokenEngine/recipe/b6-ui-bootstrap.php',adapter)
    log=(root/'b6-selection-ui-runtime.log').open('wb');server=None
    try:
        fixture['set_clock']()
        cookies={role:deliver('fixture-cookie',actor=actors[role+'_user']) for role in ('fan','creator')}
        def read(path,cookie=None,fields=None):
            body=None if fields is None else urllib.parse.urlencode(fields,doseq=True).encode()
            headers={} if cookie is None else {'Cookie':cookie['name']+'='+cookie['value']}
            try:response=urllib.request.urlopen(urllib.request.Request(base+path,headers=headers,data=body),timeout=20)
            except urllib.error.HTTPError as error:response=error
            with response:return response.status,response.read().decode('utf8'),dict(response.headers)
        server=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(fans),str(source/'tests/TokenEngine/recipe/b6-ui-router.php')],
            stdout=log,stderr=log,start_new_session=True)
        path='/app/fan/hof?support='+config['profile']
        for _ in range(80):
            try:page=read(path,cookies['fan']);break
            except (OSError,TimeoutError):
                if server.poll() is not None:raise RuntimeError('Private selection server exited')
                time.sleep(.1)
        else:raise RuntimeError('Private selection server unavailable')
        def form(html):
            for item in Forms(html).forms:
                if 'fans_support_nonce' in item:return item
            raise RuntimeError('Protected selection form unavailable; private HTML omitted')
        def body(html,action,**fields):return dict(form(html),support_action=action,**fields)
        initial=own('selection-attempt-current')
        if initial and initial['phase'] in ('released','expired','confirmed'):
            page=read(path,cookies['fan'],body(page[1],'new'))
        check('B6sc the native page is private and visiting leaves the current attempt unchanged',
            page[0]==200 and 'no-store' in page[2].get('Cache-Control','')
            and 'sessions[]' in page[1] and own('selection-attempt-current')==initial)
        check('B6sc an anonymous visitor sees no selection or protected attribution form',
            read(path)[0]==403 and 'fans_support_nonce' not in read(path)[1])
        start=body(page[1],'reserve',quantity='1',**{'sessions[]':[config['sessions'][1]]})
        check('B6sc invalid and foreign local nonces cannot reserve a native support',
            read(path,cookies['fan'],dict(start,fans_support_nonce='invalid'))[0]==403
            and read(path,cookies['creator'],start)[0]==403 and own('selection-attempt-current')==initial)
        check('B6sc fabricated economic context and ambiguous form actions are rejected',
            read(path,cookies['fan'],dict(start,creator_faluss_id='browser-authority'))[0]==400
            and read(path,cookies['fan'],dict(start,hof_session_action='open'))[0]==400
            and own('selection-attempt-current')==initial)
        count=int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))
        prepared=read(path,cookies['fan'],start)
        current=own('selection-attempt-current')
        check('B6sc the native protected prepare freezes the explicit choice and consumes no purchased units',
            prepared[0]==200 and current['phase']=='reserved'
            and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count)
        fault=root/'ranked-http-fault';fault.write_text('drop-after-commit');fault.chmod(0o600)
        uncertain=read(path,cookies['fan'],body(prepared[1],'confirm'))
        check('B6sc native confirmation uncertainty exposes only recovery and never creates a new intention',
            uncertain[0]==503 and own('selection-attempt-current')['phase']=='unknown'
            and 'Vérifier le résultat auprès de Hub' in uncertain[1]
            and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count+1)
        recovered=read(path,cookies['fan'],body(uncertain[1],'lookup'))
        check('B6sc the native primary lookup confirms the exact choices without a second debit',
            recovered[0]==200 and own('selection-attempt-current')['phase']=='confirmed'
            and 'Attribution confirmée par Hub' in recovered[1]
            and int(hub_sql('SELECT COUNT(*) FROM wp_token_engine_pf_b3r_receipts'))==count+1)
        old=own('selection-attempt-current');new=read(path,cookies['fan'],body(recovered[1],'new'))
        check('B6sc preparing another form after terminal proof changes no stored intent on its own',
            new[0]==200 and 'sessions[]' in new[1] and own('selection-attempt-current')==old)
        if os.environ.get('FALUSS_B6_UI_EXPORT') and os.environ.get('FALUSS_B6_SELECTION_CAPTURE_COMMAND'):
            out=Path(os.environ['FALUSS_B6_UI_EXPORT']).resolve()
            assert out.parent==Path('/var/tmp') and out.name.startswith('fans-hof-ui-') and not out.is_symlink()
            live=out/'live-session.json';assert not live.exists()
            live.write_text(json.dumps(dict(base=base,cookies=cookies,selection=dict(path=path))));live.chmod(0o600)
            try:subprocess.run(json.loads(os.environ['FALUSS_B6_SELECTION_CAPTURE_COMMAND']),check=True,timeout=240)
            finally:live.unlink(missing_ok=True)
    finally:
        if server is not None and server.poll() is None:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=15)
        log.close();adapter.unlink(missing_ok=True);settings.unlink(missing_ok=True)
        wp_config.write_text(before);wp_config.chmod(0o600)
