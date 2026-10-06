"""Characterize the Hub module on disposable WordPress/MariaDB.

Optional closed H1/H2/H3 and loopback HTTP use fictitious data only. Never remote
sites, payment, or production data. Generated configuration stays in a fresh 0700 root.
"""
import argparse
import datetime
import hashlib
import json
import os
import pathlib
import pwd
import secrets
import shutil
import signal
import subprocess
import tempfile
import time
import uuid
from zoneinfo import ZoneInfo


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True)
    parser.add_argument('--core', required=True)
    parser.add_argument('--cli', required=True)
    parser.add_argument('--output')
    parser.add_argument('--h1', action='store_true', help='Explicit closed H1 model recipe after unchanged H0 checks')
    parser.add_argument('--h2-reservations', action='store_true', help='Closed H2 reservations after H0 and H1')
    parser.add_argument('--h2', action='store_true', help='Closed H2 consumption and recovery after H0, H1 and H2 reservations')
    parser.add_argument('--h3-proofs', action='store_true', help='Closed H3 signed receipt and nonce persistence after H2')
    parser.add_argument('--h3-http', action='store_true', help='Closed Hub/Fans HTTP after all owner proofs; not central SSO')
    parser.add_argument('--h4', action='store_true', help='Closed owner cumulative corrections and recovery after unchanged H0-H3')
    parser.add_argument('--h4-snapshots', action='store_true', help='Closed complete owner snapshots after H4 corrections')
    parser.add_argument('--h4-http', action='store_true', help='Closed private complete snapshot delivery between disposable Hub and Fans')
    parser.add_argument('--f1a', action='store_true', help='Closed private Fan/Creator rebuildable projections after unchanged H0-H4')
    parser.add_argument('--b3-barriers', action='store_true', help='Closed B3 owner barriers on the primary; metadata only, no new consumption')
    parser.add_argument('--b3-ranked', action='store_true', help='Closed B3 ranked consumption with official debit; fictitious owner proofs only')
    options = parser.parse_args()
    if options.b3_ranked:
        options.b3_barriers = True
    if options.b3_barriers:
        options.h3_proofs = True
    if options.f1a:
        options.h4_http = True
    if options.h4_http:
        options.h4_snapshots = True
    if options.h4_snapshots:
        options.h4 = True
    if options.h4:
        options.h3_http = True
    if options.h3_http:
        options.h3_proofs = True
    if options.h3_proofs:
        options.h2 = True
    source = pathlib.Path(options.source).resolve()
    core = pathlib.Path(options.core).resolve()
    root = pathlib.Path(tempfile.mkdtemp(prefix='hub-pf-wp-', dir='/var/tmp')).resolve()
    wp = root / 'wordpress'
    workers = []
    checks = []
    database = None
    report = None
    log = open(root / 'runtime.log', 'w')

    def command(arguments):
        result = subprocess.run(arguments, capture_output=True, text=True, timeout=90)
        if result.returncode:
            # Do not print command arguments (installation credentials) or data.
            raise RuntimeError('Disposable fixture command failed; inspect private runtime locally.')
        return result.stdout

    def cli(*arguments):
        return command(['php', options.cli, '--allow-root', '--path=' + str(wp), *arguments])

    def sql(query):
        return command(['mariadb', '--no-defaults', '--socket=' + str(root / 'sql.sock'),
                        '-uroot', '--batch', '--skip-column-names', 'hub_pf_recipe', '-e', query]).strip()

    def check(name, condition):
        if not condition:
            raise AssertionError(name)
        checks.append(name)
        print('PASS ' + name, flush=True)

    def start(data):
        input_file = root / (str(uuid.uuid4()) + '.json')
        input_file.write_text(json.dumps(data))
        process = subprocess.Popen(['php', options.cli, '--allow-root', '--path=' + str(wp),
                                    'eval-file', str(source / 'tests/TokenEngine/recipe/worker.php'),
                                    str(input_file), '--use-include'], stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, text=True, start_new_session=True)
        workers.append(process)
        return process

    def finish(process):
        output, errors = process.communicate(timeout=40)
        if process.returncode:
            raise RuntimeError('Hub fixture worker failed: ' + errors[-800:])
        return json.loads(output)

    def call(action, subject='', **values):
        return finish(start(dict(action=action, subject=subject, **values)))

    def await_file(path, processes):
        deadline = time.monotonic() + 25
        while not path.exists():
            if any(process.poll() is not None for process in processes):
                raise RuntimeError('Worker exited before controlled interleaving.')
            if time.monotonic() > deadline:
                raise RuntimeError('Fixture interleaving timed out.')
            time.sleep(.02)

    def parallel(inputs):
        barrier = root / str(uuid.uuid4())
        processes = [start(dict(data, barrier=str(barrier), worker=index))
                     for index, data in enumerate(inputs)]
        for index in range(len(processes)):
            await_file(pathlib.Path(str(barrier) + '.' + str(index) + '.ready'), processes)
        pathlib.Path(str(barrier) + '.go').touch()
        return [finish(process) for process in processes]

    def entries(subject):
        return sql("SELECT entry_uuid,amount_pf,direction,economic_class,category,idempotency_key,"
                   "source_event_reference,metadata,occurred_at,created_at FROM wp_token_engine_pf_ledger "
                   "WHERE faluss_id='" + subject + "' ORDER BY id")

    def killed_claim(subject, fault):
        marker = root / str(uuid.uuid4())
        process = start(dict(action='hub', subject=subject, fault=fault, marker=str(marker)))
        await_file(marker, [process])
        os.killpg(process.pid, signal.SIGKILL)
        process.wait(timeout=10)

    try:
        wp.mkdir()
        for item in core.iterdir():
            if item.name in ('wp-content', 'wp-config.php', 'router.php'):
                continue
            if not (item.name.startswith('wp-') or item.name in ('index.php', 'xmlrpc.php')):
                continue
            if item.is_dir():
                shutil.copytree(item, wp / item.name)
            else:
                shutil.copy2(item, wp / item.name)
        plugin = wp / 'wp-content/plugins/faluss-platform'
        plugin.mkdir(parents=True)
        for name in ('src', 'assets', 'vendor'):
            shutil.copytree(source / name, plugin / name)
        for name in ('faluss-platform.php', 'composer.json', 'composer.lock'):
            shutil.copy2(source / name, plugin / name)
        shutil.copytree(core / 'wp-content/themes/twentytwentyfive', wp / 'wp-content/themes/twentytwentyfive')
        command(['mariadb-install-db', '--no-defaults', '--datadir=' + str(root / 'db'),
                 '--auth-root-authentication-method=normal'])
        database = subprocess.Popen(['mariadbd', '--no-defaults',
                                     '--user=' + pwd.getpwuid(os.geteuid()).pw_name,
                                     '--datadir=' + str(root / 'db'), '--socket=' + str(root / 'sql.sock'),
                                     '--pid-file=' + str(root / 'sql.pid'), '--skip-networking',
                                     '--innodb-buffer-pool-size=64M'], stdout=log, stderr=log)
        for _ in range(100):
            result = subprocess.run(['mariadb', '--no-defaults', '--socket=' + str(root / 'sql.sock'),
                                     '-uroot', '-e', 'CREATE DATABASE IF NOT EXISTS hub_pf_recipe'], capture_output=True)
            if result.returncode == 0:
                break
            time.sleep(.1)
        else:
            raise RuntimeError('Private MariaDB unavailable.')
        values = dict(DB_NAME='hub_pf_recipe', DB_USER='root', DB_PASSWORD='',
                      DB_HOST='localhost:' + str(root / 'sql.sock'), DB_CHARSET='utf8mb4', DB_COLLATE='',
                      WP_HOME='http://127.0.0.1:9', WP_SITEURL='http://127.0.0.1:9',
                      WP_HTTP_BLOCK_EXTERNAL=True, DISABLE_WP_CRON=True, WP_DEBUG=True,
                      WP_DEBUG_DISPLAY=False, WP_DEBUG_LOG=str(root / 'debug.log'),
                      FALUSS_PLATFORM_ROLE='hub', FALUSS_PLATFORM_TOKEN_ENGINE=True,
                      FALUSS_HUB_PF_RECIPE_ONLY=True)
        if options.h1 or options.h2_reservations or options.h2:
            values['WP_ENVIRONMENT_TYPE'] = 'local'
        for key in ('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
                    'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'):
            values[key] = secrets.token_urlsafe(48)
        config = '<?php\n' + ''.join('define(' + json.dumps(key) + ',' + json.dumps(value) + ');\n'
                                    for key, value in values.items())
        config += "$table_prefix='wp_';\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire_once ABSPATH.'wp-settings.php';\n"
        (wp / 'wp-config.php').write_text(config)
        (wp / 'wp-config.php').chmod(0o600)
        cli('core', 'install', '--url=http://127.0.0.1:9', '--title=Disposable Hub PF recipe',
            '--admin_user=fixture', '--admin_password=' + secrets.token_urlsafe(32),
            '--admin_email=fixture@example.invalid', '--skip-email')
        cli('plugin', 'activate', 'faluss-platform')
        inspected = call('inspect')
        check('Real Hub module installs schema v5 on InnoDB',
              inspected['schema'] == '5' and inspected['pf_engine'] == 'InnoDB')
        check('Only historical daily methods are exposed by Platform facade',
              sorted(inspected['facade']) == ['claimHubDaily', 'hubDailyStatus'])
        check('Fresh fixture has no PF or generic ALB entries',
              inspected['pf_count'] == inspected['generic_count'] == 0)

        subject = str(uuid.uuid4())
        check('Missing Hub server proof refuses claim without writing',
              call('hub', subject, proof={})['state'] == 'ineligible' and entries(subject) == '')
        check('Me proof without published card refuses claim without writing',
              call('me', subject, proof=dict(owner='faluss-me', identity_active=True))['state'] == 'ineligible'
              and entries(subject) == '')
        results = parallel([dict(action='hub', subject=subject)] * 4 + [dict(action='me', subject=subject)] * 4)
        check('Eight concurrent Hub and Me claims commit exactly one credit each',
              all(result['state'] == 'claimed' for result in results)
              and sum(result['claimed_now'] for result in results) == 2
              and len(entries(subject).splitlines()) == 2)
        check('Historic 20 and 75 PF remain earned and cumulative without funded conversion',
              call('balances', subject) == dict(earned=95, funded=0, promotional=0))
        unchanged = entries(subject)
        check('Daily replay leaves original ledger rows unchanged',
              not call('hub', subject)['claimed_now'] and not call('me', subject)['claimed_now']
              and entries(subject) == unchanged)
        logical_date = datetime.datetime.now(ZoneInfo('Europe/Paris')).strftime('%Y-%m-%d')
        expected_keys = {
            'pf.daily.' + hashlib.sha256('|'.join([owner, reward, subject, logical_date, '1.0.0']).encode()).hexdigest()[:48]
            for owner, reward in [('faluss-hub', 'hub.daily_accrual'), ('faluss-me', 'me.profile_daily_claim')]
        }
        check('Daily logical date and keys remain scoped to Europe Paris and policy',
              all(result['logical_date'] == logical_date for result in results)
              and {row.split('\t')[5] for row in unchanged.splitlines()} == expected_keys)

        subject = str(uuid.uuid4())
        killed_claim(subject, 'before-commit')
        check('Process killed before COMMIT rolls back uncommitted PF insert', entries(subject) == '')
        check('Retry after pre-COMMIT termination credits once', call('hub', subject)['claimed_now']
              and call('balances', subject)['earned'] == 20 and len(entries(subject).splitlines()) == 1)
        subject = str(uuid.uuid4())
        killed_claim(subject, 'after-commit')
        committed = entries(subject)
        check('Process killed after COMMIT preserves the committed credit',
              len(committed.splitlines()) == 1 and call('balances', subject)['earned'] == 20)
        check('Status and same daily retry recover after lost committed response without new credit',
              call('status', subject)['state'] == 'claimed' and not call('hub', subject)['claimed_now']
              and entries(subject) == committed)
        subject = str(uuid.uuid4())
        uncertain = call('hub', subject, fault='lost-commit-ack', marker=str(root / 'unused'))
        check('Injected lost COMMIT acknowledgement returns unavailable despite persisted credit',
              uncertain['state'] == 'unavailable' and call('balances', subject)['earned'] == 20)
        check('Retry resolves ambiguous COMMIT without double credit',
              not call('hub', subject)['claimed_now'] and len(entries(subject).splitlines()) == 1)

        subject = str(uuid.uuid4())
        call('hub', subject)
        original_row = entries(subject)
        original = original_row.split('\t')[0]
        events = ['fixture.compensation.first', 'fixture.compensation.second']
        corrections = parallel([dict(action='compensate', original=original, event=event) for event in events])
        winner = next(index for index, result in enumerate(corrections) if 'error' not in result)
        check('Concurrent full compensations permit one correction per original',
              sum('error' not in result for result in corrections) == 1
              and corrections[1 - winner]['error'] == 'pf_already_compensated')
        check('Compensation preserves original bytes, full amount and class; net stays nonnegative',
              entries(subject).splitlines()[0] == original_row
              and entries(subject).splitlines()[1].split('\t')[1:4] == ['20', 'compensation', 'earned']
              and call('balances', subject) == dict(earned=0, funded=0, promotional=0))
        replay = call('compensate', original=original, event=events[winner])
        check('Full correction replay returns same reference without duplicate',
              replay['idempotent'] and replay['entry_uuid'] == corrections[winner]['entry_uuid']
              and len(entries(subject).splitlines()) == 2)
        changed = call('compensate', original=original, event=events[winner], reason='different fixture reason')
        check('Document current gap: same correction key does not compare changed reason payload',
              changed['idempotent'] and changed['entry_uuid'] == replay['entry_uuid'])
        check('A compensation cannot itself be compensated',
              call('compensate', original=replay['entry_uuid'], event='fixture.compensation.third')['error']
              == 'pf_invalid_compensation')

        # A synthetic historical debit exists only as seed data in this database.
        # It is NOT a demonstrated support/pack consumption through the Hub API.
        subject = str(uuid.uuid4())
        call('hub', subject)
        original = entries(subject).split('\t')[0]
        sql("INSERT INTO wp_token_engine_pf_ledger (entry_uuid,faluss_id,amount_pf,direction,economic_class,category,"
            "category_version,source_owner,source_event_reference,idempotency_key,policy_version,occurred_at,created_at) "
            "VALUES ('" + str(uuid.uuid4()) + "','" + subject + "',20,'debit','earned','fans_support','1.0.0',"
            "'fixture-seed','fixture.seed.reference','fixture.seed.debit.key','1.0.0',UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        seeded = entries(subject)
        check('Seeded consumed balance: full compensation fails closed if class balance is insufficient',
              call('compensate', original=original, event='fixture.insufficient.reversal')['error']
              == 'pf_insufficient_class_balance' and entries(subject) == seeded
              and call('balances', subject)['earned'] == 0)

        before = call('inspect')['pf_count']
        for category in ('pf_pack_purchase', 'pf_pack_bonus', 'fans_support', 'cosmetic_redemption', 'manual_adjustment'):
            rejected = parallel([dict(action='future', subject=str(uuid.uuid4()), category=category)] * 2)
            check('Future ' + category + ' stays closed under concurrent calls',
                  all(result['error'] == 'pf_feature_not_enabled' for result in rejected))
        check('Closed future operations write nothing and generic ALB ledger stays separate',
              call('inspect')['pf_count'] == before and call('inspect')['generic_count'] == 0)
        h0_total = len(checks)
        if options.h1 or options.h2_reservations or options.h2:
            from h1_checks import run_checks
            run_checks(root, wp, check, call, sql, start, finish, parallel, await_file)
        h1_total = len(checks) - h0_total
        if options.h2_reservations or options.h2:
            from h2_reservation_checks import run_checks
            run_checks(root, wp, check, call, sql, start, finish, parallel, await_file)
        h2_reservations_total = len(checks) - h0_total - h1_total
        if options.h2:
            from h2_consumption_checks import run_checks
            run_checks(root, wp, check, call, sql, start, finish, parallel, await_file)
        h2_consumption_total = len(checks) - h0_total - h1_total - h2_reservations_total
        h3_start = len(checks)
        if options.h3_proofs:
            from h3_proof_checks import run_checks
            run_checks(root, wp, check, call, sql, start, finish, parallel, await_file)
        h3_http_start = len(checks)
        if options.h3_http:
            from h3_http_checks import run_checks
            run_checks(root, wp, source, core, options.cli, check, call, sql, command, workers, log)
        h4_start = len(checks)
        if options.h4:
            from h4_correction_checks import run_checks
            run_checks(root, wp, check, call, sql, start, finish, parallel, await_file)
        h4_snapshots_start = len(checks)
        if options.h4_snapshots:
            from h4_snapshot_checks import run_checks
            run_checks(root, wp, check, call, sql, start, finish, parallel, await_file)
        h4_http_start = len(checks)
        if options.h4_http:
            from h4_http_checks import run_checks
            run_checks(root, wp, source, options.cli, check, call, sql, command, workers, log)
        f1a_start = len(checks)
        if options.f1a:
            from f1a_checks import run_checks
            run_checks(root, wp, source, options.cli, check, call, sql, command, workers, log)
        b3_barriers_start = len(checks)
        if options.b3_barriers:
            from b3_barrier_checks import run_checks
            run_checks(root, wp, check, call, sql, start, finish, parallel, await_file)
        b3_ranked_start = len(checks)
        if options.b3_ranked:
            from b3_ranked_checks import run_checks
            run_checks(root, wp, check, call, sql, start, finish, parallel, await_file)
        report = dict(checks=checks, total=len(checks), failed=0, wordpress=cli('core', 'version').strip(),
                      php=command(['php', '-r', 'echo PHP_VERSION;']).strip(),
                      database=sql('SELECT VERSION()'), schema='5',
                      owner_service_lf_sha256=hashlib.sha256((plugin / 'src/TokenEngine/Legacy/includes/class-token-engine-points-service.php').read_bytes().replace(b'\r\n', b'\n')).hexdigest(),
                      scope='Current Hub module on disposable WordPress/MariaDB; synthetic data only',
                      not_proven=['Any real purchase producer, site operation, partial refund or current economic score',
                                  'Real network COMMIT acknowledgement loss or replica/restore recovery',
                                  'Target Hub configuration, production or staging', 'Future Fans to Hub HTTP protocol'])
        report['h0_total'] = h0_total
        report['h1_total'] = h1_total
        report['h2_reservations_total'] = h2_reservations_total
        report['h2_consumption_total'] = h2_consumption_total
        report['h3_proofs_total'] = h3_http_start - h3_start
        report['h3_http_total'] = h4_start - h3_http_start
        report['h4_total'] = h4_snapshots_start - h4_start
        report['h4_snapshots_total'] = h4_http_start - h4_snapshots_start
        report['h4_http_total'] = f1a_start - h4_http_start
        report['f1a_total'] = b3_barriers_start - f1a_start
        report['b3_barriers_total'] = b3_ranked_start - b3_barriers_start
        report['b3_ranked_total'] = len(checks) - b3_ranked_start
        if options.h1 or options.h2_reservations or options.h2:
            report['scope'] += '; closed H1 model explicitly installed only in this fixture'
            report['model_schema'] = '1 (closed_h1_model)'
        if options.h2_reservations or options.h2:
            report['scope'] += '; closed H2 synthetic ledger credits and reservations, not site operations'
            report['reservation_schema'] = '1 (closed_h2_reservations)'
        if options.h2:
            report['scope'] += '; closed synthetic consumption and atomic pending journal, no network admission'
            report['consumption_schema'] = '1 (closed_h2_consumption)'
        if options.h3_proofs:
            report['scope'] += '; closed H3 signed proofs and SQL nonces'
            if not options.h3_http:
                report['scope'] += ', no HTTP or actual SSO'
            report['protocol_schema'] = '1 (closed_h3_hub)'
        if options.b3_barriers:
            report['scope'] += '; closed B3b1 owner barriers, SQL lookup and selection locks, no ranked economic operation'
            report['barrier_schema'] = '1 (closed_b3_barriers)'
            report['not_proven'] += ['B3 ranked atomic debit/order/receipt, cross-instance barrier transport and Fans closing state',
                                     'Any production installation, real account, purchase, score or retention policy']
        if options.b3_ranked:
            report['scope'] += '; closed B3b2 atomic owner order/context/signed receipt and official H2 debit, partial H4 corrections with fictitious evidence'
            report['ranked_schema'] = '1 (closed_b3_ranked)'
            report['not_proven'] = ['B3 cross-instance ranked HTTP, snapshot 2.0 and Fans closure recovery',
                                    'Real account/SSO, purchase producer, production admission, activation, public score or retention policy',
                                    'Real network COMMIT loss, replica or inconsistent backup restore']
        if options.h3_http:
            report['scope'] += '; real private loopback HTTP between distinct disposable Hub/Fans WP and databases, fictitious links/keys, not true SSO'
            report['fans_protocol_schema'] = '1 (closed_h3_fans)'
            report['not_proven'] = ['Real purchase producer, real account or target/staging configuration',
                                    'True Faluss Identity passwordless/SSO, TLS and production peer admission',
                                    'Refunds H4, score HoF F1, retention policy, replica/restore recovery']
        if options.h4:
            report['scope'] += '; owner partial cumulative corrections, disputes and durable 100-allocation fragments with fictitious source proofs'
            report['correction_schema'] = '1 (closed_h4_corrections)'
            report['not_proven'] = ['Monetary refunds, real purchase source, target or staging sites',
                                    'True SSO, public score F1, retention policy #150, replica or inconsistent backup restore',
                                    'H4 complete cross-instance snapshots (next lot)']
        if options.h4_snapshots:
            report['scope'] += '; complete materialized owner snapshots and final primary fence'
            report['snapshot_schema'] = '1 (closed_h4_snapshots)'
            report['not_proven'][-1] = 'Cross-instance signed H4 snapshot delivery (next lot)'
        if options.h4_http:
            report['scope'] += '; signed complete H4 pages with durable Fans private staging and primary fence, fictitious SSO links'
            report['fans_snapshot_schema'] = '1 (closed_h4_fans)'
            report['not_proven'][-1] = 'Public HoF/Fan projection F1, real economic admission and asynchronous production delivery'
        if options.f1a:
            report['scope'] += '; private persistent rebuildable Fan/Creator points from complete latest H4 facts only'
            report['fans_projection_schema'] = '1 (closed_f1a_fans)'
            report['not_proven'][-1] = 'F1b periods/ranks/visibility/sessions, public scores, continuous production freshness and asynchronous delivery'
    finally:
        for process in workers:
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait(timeout=10)
            if process.stdout:
                process.stdout.close()
            if process.stderr:
                process.stderr.close()
        if database is not None:
            database.terminate()
            database.wait(timeout=20)
        log.close()
        # Only this freshly allocated, verified test root is recursively removed.
        if root.parent != pathlib.Path('/var/tmp') or not root.name.startswith('hub-pf-wp-'):
            raise RuntimeError('Unsafe fixture cleanup target.')
        shutil.rmtree(root)
    if report is not None:
        report['fixture_removed'] = not root.exists()
        if options.output:
            pathlib.Path(options.output).write_text(json.dumps(report, indent=2) + '\n')
        print(json.dumps(report), flush=True)


if __name__ == '__main__':
    main()
