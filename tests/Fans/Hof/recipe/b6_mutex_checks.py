"""Owned session mutex before a caller transaction, on actual private WordPress/MariaDB."""
from b1_checks import data, call, check, checks, root, sql, start, finish
import time
import uuid


def run_checks():
    baseline = len(checks)
    data('admin', 'session_install')
    value = data('guest', 'session_mutex')
    check('B6mx sessions mutex starts no transaction and preserves the caller rollback',
          value == dict(value=dict(before='0', during='1'), error=None, transaction_after='0', probe_rows='0', mutex_free='1'))
    check('B6mx rejected callback releases the session mutex',
          data('guest', 'session_mutex', mode='reject') == dict(value=None, error='hof_fixture_refused', transaction_after='0', probe_rows='0', mutex_free='1'))
    check('B6mx nested entry refuses without committing the existing caller transaction',
          data('guest', 'session_mutex', mode='nested') == dict(value=None, error='hof_nested_transaction', transaction_after='1', probe_rows='0', mutex_free='1'))
    check('B6mx an unfinished callback transaction is rolled back before mutex release',
          data('guest', 'session_mutex', mode='unfinished') == dict(value=None, error='hof_unfinished_transaction', transaction_after='0', probe_rows='0', mutex_free='1'))
    guard = root/uuid.uuid4().hex
    held = start('guest', 'session_mutex', hold=str(guard)); writer = None
    deadline = time.monotonic()+12
    try:
        while not guard.with_name(guard.name+'.ready').exists():
            if held.poll() is not None or time.monotonic() > deadline: raise RuntimeError('Private session mutex checkpoint unavailable')
            time.sleep(.05)
        writer = start('admin', 'session_mutex', mode='ordinary')
        deadline = time.monotonic()+12
        while int(sql("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE STATE='User lock'")) == 0:
            if writer.poll() is not None or time.monotonic() > deadline: raise RuntimeError('Private concurrent mutex wait unavailable')
            time.sleep(.05)
        check('B6mx a concurrent ordinary session transaction waits on the same domain mutex', writer.poll() is None)
    finally:
        guard.with_name(guard.name+'.release').touch()
        if held.poll() is None: held.wait(timeout=20)
        if writer is not None and writer.poll() is None: writer.wait(timeout=20)
    held_result = finish(held)['data']; writer_result = finish(writer)['data']
    check('B6mx existing transaction commits only after the composed reader releases its mutex',
          held_result['probe_rows'] == '0' and writer_result['probe_rows'] == '1'
          and writer_result['value'] == dict(before='1',during='1')
          and data('guest','session_mutex')['mutex_free'] == '1')
    return len(checks)-baseline
