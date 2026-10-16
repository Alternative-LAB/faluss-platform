"""Actual protected Fans routes on the existing disposable pair; never central Me SSO."""
import json
import os
import pathlib
import secrets
import shutil
import signal
import socket
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request


def run_checks(root,source,cli_path,fixture,origin,actors,session,month,deliver,check,origin_action=None):
    fans=fixture['fans']
    with socket.socket() as listener:
        listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    config=fans/'wp-config.php'
    before=config.read_text()
    if 'http://127.0.0.1:9' not in before:raise RuntimeError('Expected closed CLI fixture origin')
    config.write_text(before.replace('http://127.0.0.1:9',base));config.chmod(0o600)
    settings=root/'b6-ui-settings.json'
    settings.write_text(json.dumps(dict(origin=origin,fictitious_client_secret=secrets.token_urlsafe(32))));settings.chmod(0o600)
    mu=fans/'wp-content/mu-plugins';mu.mkdir(exist_ok=True)
    adapter=mu/'b6-ui-recipe.php';shutil.copy2(source/'tests/TokenEngine/recipe/b6-ui-bootstrap.php',adapter)
    log=(root/'b6-ui-runtime.log').open('wb');server=None
    try:
        # Existing WordPress rewrite/route machinery, not a replacement page or fake reader.
        for arguments in [('option','update','permalink_structure','/%postname%/'),('rewrite','flush')]:
            result=subprocess.run(['php',cli_path,'--allow-root','--path='+str(fans),*arguments],capture_output=True,timeout=90)
            if result.returncode:raise RuntimeError('B6 UI fixture route registration failed; no private output printed')
        cookies={family:deliver('fixture-cookie',actor=actors[family+'_user']) for family in ('fan','creator','admin')}
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self,req,fp,code,msg,headers,newurl):return None
        def read(path,cookie=None,fields=None,follow=True):
            headers={} if cookie is None else {'Cookie':cookie['name']+'='+cookie['value']}
            body=None if fields is None else urllib.parse.urlencode(fields).encode()
            request=urllib.request.Request(base+path,headers=headers,data=body)
            open_request=urllib.request.urlopen if follow else urllib.request.build_opener(NoRedirect).open
            try:response=open_request(request,timeout=20)
            except urllib.error.HTTPError as error:response=error
            with response:return response.status,response.read().decode('utf8'),response.headers
        server=subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(fans),str(source/'tests/TokenEngine/recipe/b6-ui-router.php')],
                                stdout=log,stderr=log,start_new_session=True)
        for _ in range(80):
            try:status,body,headers=read('/app/fan/hof');break
            except (OSError,TimeoutError):
                if server.poll() is not None:raise RuntimeError('B6 UI fixture server exited')
                time.sleep(.1)
        else:raise RuntimeError('B6 UI fixture server did not start')
        check('B6dl real guest HoF route reads the approved signed session and is never shared-cached',
              status==200 and 'Rencontres de recette B6' in body and 'private' in headers.get('Cache-Control','') and 'no-store' in headers.get('Cache-Control',''))
        check('B6dl real guest and unlinked administrator cannot read a private statement',
              read('/app/fan/espace')[0]==403 and read('/app/fan/espace',cookies['admin'])[0]==403)
        query='?month='+urllib.parse.quote(month)
        fan=read('/app/fan/espace'+query,cookies['fan']);creator=read('/app/creator/progression'+query,cookies['creator'])
        check('B6dl distinct cookie-authenticated local Identity links read their own monthly views',
              fan[0]==creator[0]==200 and 'fu-hof__metric' in fan[1] and 'fu-hof__metric' in creator[1])
        check('B6dl real linked Fan has no creator-space permission without a creator profile',read('/app/creator/progression',cookies['fan'])[0]==404)
        session_path='/app/fan/hof/session?session='+urllib.parse.quote(session['session_id'])
        check('B6dl real cookie-authenticated session route exposes only approved participant names',
              read(session_path,cookies['fan'])[0]==200 and 'Atelier de recette B6' in read(session_path,cookies['fan'])[1])
        broken=dict(cookies['fan'],value=cookies['fan']['value']+'x')
        expired=deliver('fixture-cookie',actor=actors['fan_user'],expired=True)
        check('B6dl forged or expired local cookie cannot retain private route access',
              read('/app/fan/espace',broken)[0]==403 and read('/app/fan/espace',expired)[0]==403)
        from b6_privacy_http_checks import run_checks as privacy_checks
        privacy_checks(read,cookies,actors,month,deliver,check,origin_action)
        from b6_session_ui_http_checks import run_checks as session_checks
        management=session_checks(read,cookies,actors,session,deliver,check)
        if os.environ.get('FALUSS_B6_UI_EXPORT'):
            out=pathlib.Path(os.environ['FALUSS_B6_UI_EXPORT']).resolve()
            if out.parent!=pathlib.Path('/var/tmp') or not out.name.startswith('fans-hof-ui-') or out.is_symlink():raise RuntimeError('Unsafe private UI capture destination')
            out.mkdir(mode=0o700,exist_ok=True)
            # Private capability file for the local renderer, removed before the server is stopped.
            live=out/'live-session.json'
            live.write_text(json.dumps(dict(base=base,cookies=cookies,month=month,session=session['session_id'],session_management=management)));live.chmod(0o600)
            try:
                if os.environ.get('FALUSS_B6_UI_CAPTURE_COMMAND'):
                    command=json.loads(os.environ['FALUSS_B6_UI_CAPTURE_COMMAND'])
                    subprocess.run(command,check=True,timeout=240)
            finally:live.unlink(missing_ok=True)
    finally:
        if server is not None and server.poll() is None:
            os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=15)
        log.close();adapter.unlink(missing_ok=True);settings.unlink(missing_ok=True)
        config.write_text(before);config.chmod(0o600)
