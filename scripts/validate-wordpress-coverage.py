#!/usr/bin/env python3
"""Keep every named assertion from the trusted WordPress recipe baseline."""

import argparse
import json
from pathlib import Path
import sys

BASELINE = Path(__file__).resolve().parents[1] / 'tests/Workflow/fixtures/wordpress-assertions.json'


def validate_reports(directory: Path, baseline: dict) -> dict:
    files = {path.name: path for path in directory.glob('*.json')}
    missing_reports = set(baseline['required_reports']) - files.keys()
    if missing_reports:
        raise ValueError('Missing recipe reports: ' + ', '.join(sorted(missing_reports)))

    observed = set()
    executions = 0
    for name, path in files.items():
        report = json.loads(path.read_text())
        if not isinstance(report, dict):
            raise ValueError('Invalid recipe report: ' + name)
        checks = report.get('checks')
        if not isinstance(checks, list) or not all(isinstance(check, str) for check in checks):
            raise ValueError('Invalid assertion list: ' + name)
        declared = report.get('total', report.get('count'))
        if type(declared) is not int or declared != len(checks) or report.get('failed', 0) != 0:
            raise ValueError('Failed or inconsistent recipe report: ' + name)
        observed.update(checks)
        executions += len(checks)

    missing_assertions = set(baseline['assertions']) - observed
    if missing_assertions:
        raise ValueError(f'{len(missing_assertions)} baseline assertions missing: '
                         + '; '.join(sorted(missing_assertions)[:10]))
    return dict(reports=len(files), unique_assertions=len(observed),
                baseline_assertions=len(baseline['assertions']), executions=executions)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('reports', type=Path)
    options = parser.parse_args()
    try:
        baseline = json.loads(BASELINE.read_text())
        print(json.dumps(validate_reports(options.reports, baseline), sort_keys=True))
    except (ValueError, OSError) as error:
        print(str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
