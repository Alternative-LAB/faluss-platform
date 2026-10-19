"""Two authenticated nonce admissions sharing one primary-index interval; no owner write."""
from pathlib import Path
import concurrent.futures
import hashlib
import secrets
import time


def run_checks(root, check, sql, fixture):
    root = Path(root).resolve()
    assert root.parent == Path('/var/tmp') and root.name.startswith('hub-pf-wp-')
    assert not root.is_symlink() and root.stat().st_mode & 0o777 == 0o700
    table = 'wp_token_engine_pf_b3bh_nonces'
    hashes = sql("SELECT nonce_sha256 FROM " + table + " WHERE peer='fixture.fans' ORDER BY nonce_sha256").splitlines()
    positions = [0] + sorted(int(value, 16) for value in hashes) + [2**256 - 1]
    lower, upper = max(zip(positions, positions[1:]), key=lambda pair: pair[1] - pair[0])
    chosen = []
    for _ in range(20000):
        nonce = secrets.token_hex(32)
        if lower < int(hashlib.sha256(nonce.encode()).hexdigest(), 16) < upper and nonce not in chosen:
            chosen.append(nonce)
        if len(chosen) == 2:
            break
    assert len(chosen) == 2, 'Two distinct nonce hashes in the same existing interval are required'
    _, _, descriptors = fixture['fixture']()
    fields = fixture['fields'](descriptors[0])
    key = secrets.token_hex(32)
    sealed = [fixture['sign'](fields, key, request=dict(nonce=value), context=dict(nonce=value)) for value in chosen]
    before, events = fixture['nonces'](), fixture['events']()
    gate = root / 'nonce-interval-go'
    markers = [root / ('nonce-interval-ready-' + str(slot)) for slot in (1, 2)]
    pool = concurrent.futures.ThreadPoolExecutor(max_workers=2)
    futures = []
    for path in [gate, *markers]:
        path.unlink(missing_ok=True)
    try:
        for slot, value in enumerate(sealed, 1):
            futures.append(pool.submit(fixture['worker'], 'hub', dict(action='admit-interval', slot=str(slot), wire=value['wire'])))
        until = time.monotonic() + 8
        while not all(path.exists() for path in markers) and time.monotonic() < until:
            if any(future.done() for future in futures):
                break
            time.sleep(.01)
        ready = all(path.exists() for path in markers)
        gate.write_text('go'); gate.chmod(0o600)
        results = [future.result() for future in futures]
        check('B3beh distinct authenticated nonces in the same index interval both commit',
              ready and results == [dict(admitted=True)] * 2 and fixture['nonces']() == before + 2)
        check('B3beh interval admissions change no owner event and both replays are refused',
              fixture['events']() == events and all(fixture['http'](value['wire'])[0] == 403 for value in sealed)
              and fixture['nonces']() == before + 2 and fixture['events']() == events)
    finally:
        # Release and drain any transaction before normal disposable-fixture cleanup.
        gate.touch(mode=0o600, exist_ok=True)
        pool.shutdown(wait=True)
        for path in [gate, *markers]:
            path.unlink(missing_ok=True)
