#!/usr/bin/env python3
"""Builds the distributable plugin zips.

Outputs:
  dist/vetspire-scheduler.zip            files at zip ROOT — for manual wp-admin
                                         uploads (survives Windows re-zipping)
  dist/vetspire-scheduler-<ver>.zip      standard single-folder layout — attach
                                         this one to the GitHub release so the
                                         update checker installs it correctly

Always use forward slashes in zip entries: backslash paths (PowerShell
Compress-Archive) do NOT create directories when extracted on Linux hosts.
"""
import os
import re
import shutil
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIST = os.path.join(ROOT, 'dist')

INCLUDE_FILES = ['vetspire-scheduler.php', 'uninstall.php', 'README.md', '.htaccess']
INCLUDE_DIRS = ['includes', 'assets', 'lib']


def plugin_version():
    with open(os.path.join(ROOT, 'vetspire-scheduler.php'), encoding='utf-8') as fh:
        match = re.search(r"VSPS_VERSION',\s*'([0-9.]+)'", fh.read())
    if not match:
        raise SystemExit('Could not read VSPS_VERSION')
    return match.group(1)


def collect_files():
    files = list(INCLUDE_FILES)
    for base in INCLUDE_DIRS:
        for dirpath, _, names in os.walk(os.path.join(ROOT, base)):
            for name in names:
                files.append(os.path.relpath(os.path.join(dirpath, name), ROOT))
    return files


def build(out_path, prefix):
    with zipfile.ZipFile(out_path, 'w', zipfile.ZIP_DEFLATED) as bundle:
        for rel in collect_files():
            arc = (prefix + rel).replace('\\', '/')
            bundle.write(os.path.join(ROOT, rel), arc)
    with zipfile.ZipFile(out_path) as bundle:
        assert bundle.testzip() is None
        for name in bundle.namelist():
            assert '\\' not in name, 'backslash path in ' + name
    print(out_path, round(os.path.getsize(out_path) / 1024, 1), 'KB')


def main():
    version = plugin_version()
    os.makedirs(DIST, exist_ok=True)
    manual = os.path.join(DIST, 'vetspire-scheduler.zip')
    release = os.path.join(DIST, 'vetspire-scheduler-' + version + '.zip')
    build(manual, '')
    build(release, 'vetspire-scheduler/')
    # Convenience copy next to the project for manual installs.
    shutil.copy(manual, os.path.join(os.path.dirname(ROOT), 'vetspire-scheduler.zip'))
    print('version:', version)


if __name__ == '__main__':
    main()
