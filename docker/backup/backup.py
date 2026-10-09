"""Offline-friendly school backups. Restore only into a fresh deployment."""
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tarfile
import time
import fcntl

ROOT = Path('/backups')
STORAGE = Path('/school-storage')
PROJECT = os.environ.get('SCHOOL_PROJECT', 'madrassa')
ALGERIA = dt.timezone(dt.timedelta(hours=1))


def sql(query):
    return subprocess.check_output(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-At', '-c', query], text=True).strip()


def wait_database():
    for _ in range(60):
        if subprocess.run(['pg_isready'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
            if int(sql("SHOW server_version_num")) // 10000 != 16:
                raise RuntimeError('This backup workflow requires PostgreSQL 16')
            return
        time.sleep(1)
    raise RuntimeError('PostgreSQL did not become ready within 60 seconds')


def counts():
    tables = json.loads(sql("SELECT coalesce(json_agg(format('%I.%I', schemaname, tablename) ORDER BY schemaname, tablename), '[]') FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')"))
    return {table: int(sql('SELECT count(*) FROM '+table)) for table in tables}


def digest(file):
    with file.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def atomic_json(path, value):
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(value, indent=2)+'\n')
    tmp.replace(path)


def unlock_maintenance():
    marker = STORAGE / 'framework/down'
    if marker.exists() and json.loads(marker.read_text()).get('madrassa_backup') == PROJECT:
        marker.unlink()


def backup():
    wait_database()
    keep = int(os.environ.get('BACKUP_KEEP', '14'))
    if keep < 1:
        raise RuntimeError('BACKUP_KEEP must be at least 1')
    if not (STORAGE / 'app/.app-key').is_file():
        raise RuntimeError('Persistent application key is missing; start the updated app first')
    framework = STORAGE / 'framework'
    framework.mkdir(parents=True, exist_ok=True)
    # Shared with manual backups; a second process must never remove another's maintenance marker.
    with (framework / 'madrassa-backup.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        unlock_maintenance()  # Recover our own marker after an interrupted backup.
        marker = framework / 'down'
        if marker.exists():
            raise RuntimeError('Application is already in maintenance; backup postponed')
        now = dt.datetime.now(dt.timezone.utc)
        name = 'backup-'+now.strftime('%Y%m%dT%H%M%S%fZ')
        staging = ROOT / ('.partial-'+name)
        staging.mkdir(mode=0o700)
        try:
            atomic_json(marker, {'time': int(time.time()), 'retry': 60, 'refresh': 15, 'status': 503, 'template': None, 'redirect': None, 'madrassa_backup': PROJECT})
            # Drain requests already accepted before maintenance. Dump takes a consistent DB snapshot.
            time.sleep(int(os.environ.get('BACKUP_DRAIN_SECONDS', '15')))
            subprocess.run(['pg_dump', '--format=custom', '--file='+str(staging/'database.dump')], check=True)
            snapshot_counts = counts()
            with tarfile.open(staging/'files.tar.gz', 'w:gz') as archive:
                archive.add(STORAGE/'app', arcname='app')
            subprocess.run(['pg_restore', '--list', str(staging/'database.dump')], check=True, stdout=subprocess.DEVNULL)
            manifest = {'version': 1, 'project': PROJECT, 'created_at': now.isoformat(), 'postgres_major': 16,
                        'database': os.environ['PGDATABASE'], 'tables': snapshot_counts,
                        'sha256': {file: digest(staging/file) for file in ('database.dump','files.tar.gz')}}
            atomic_json(staging/'manifest.json', manifest)
            staging.rename(ROOT/name)
            atomic_json(ROOT/'status.json', {'last_success': time.time(), 'backup': name, 'last_error': None})
            # Only prune completed backups belonging to this deployment, after a successful new copy.
            completed = sorted(p for p in ROOT.glob('backup-*') if p.is_dir() and (p/'manifest.json').is_file()
                               and json.loads((p/'manifest.json').read_text()).get('project') == PROJECT)
            for old in completed[:-keep]:
                shutil.rmtree(old)
            print(json.dumps({'backup': name, 'tables': len(snapshot_counts), 'verified_checksums': True}), flush=True)
            return name
        finally:
            unlock_maintenance()
            if staging.exists():
                shutil.rmtree(staging)


def validate(name):
    if Path(name).name != name or not name.startswith('backup-'):
        raise RuntimeError('Choose a backup directory name inside /backups')
    folder = ROOT/name
    manifest = json.loads((folder/'manifest.json').read_text())
    if manifest.get('version') != 1 or manifest.get('postgres_major') != 16:
        raise RuntimeError('Unsupported backup format')
    for file in ('database.dump', 'files.tar.gz'):
        if digest(folder/file) != manifest['sha256'][file]:
            raise RuntimeError('Backup checksum failed: '+file)
    subprocess.run(['pg_restore','--list',str(folder/'database.dump')], check=True, stdout=subprocess.DEVNULL)
    # Reject traversal, links and device files before making any database changes.
    with tarfile.open(folder/'files.tar.gz') as archive:
        for member in archive.getmembers():
            parts = Path(member.name).parts
            if not parts or parts[0] != 'app' or '..' in parts or not (member.isfile() or member.isdir()):
                raise RuntimeError('Unsafe file archive')
        key = archive.extractfile('app/.app-key').read().strip()
        if not key.startswith(b'base64:'):
            raise RuntimeError('Backup encryption key missing or invalid')
    return folder, manifest


def restore(name):
    folder, manifest = validate(name)
    wait_database()
    if counts():
        raise RuntimeError('Restore refused: destination database is not empty. Use a new Compose project.')
    if STORAGE.exists() and any(STORAGE.iterdir()):
        raise RuntimeError('Restore refused: destination storage is not empty.')
    # --single-transaction ensures a failed database restore leaves an empty destination.
    subprocess.run(['pg_restore', '--exit-on-error', '--single-transaction', '--no-owner', '--dbname='+os.environ['PGDATABASE'], str(folder/'database.dump')], check=True)
    if counts() != manifest['tables']:
        raise RuntimeError('Restored table counts differ from the backup')
    STORAGE.mkdir(exist_ok=True)
    with tarfile.open(folder/'files.tar.gz') as archive:
        archive.extractall(STORAGE, filter='data')
    print(json.dumps({'restored': name, 'source_project': manifest['project'], 'tables_verified': len(manifest['tables']), 'checksums_verified': True}), flush=True)


def health():
    status = json.loads((ROOT/'status.json').read_text())
    if status.get('last_error') or time.time()-status['last_success'] > 26*3600:
        raise RuntimeError('No successful recent backup; inspect backup service logs')
    validate(status['backup'])


def schedule():
    hour = int(os.environ.get('BACKUP_HOUR', '3'))
    if not 0 <= hour <= 23:
        raise RuntimeError('BACKUP_HOUR must be 0..23 (Algeria time)')
    # First start and missed schedules produce an immediate catch-up backup.
    while True:
        now = dt.datetime.now(ALGERIA)
        due = now.replace(hour=hour, minute=0, second=0, microsecond=0)
        if due > now:
            due -= dt.timedelta(days=1)
        try:
            status = json.loads((ROOT/'status.json').read_text()) if (ROOT/'status.json').exists() else {}
            if status.get('last_success', 0) < due.timestamp() or status.get('last_error'):
                backup()
        except Exception as error:
            # Do not log database credentials or subprocess environment.
            print('Backup failed: '+str(error), file=sys.stderr, flush=True)
            previous = json.loads((ROOT/'status.json').read_text()) if (ROOT/'status.json').exists() else {}
            previous['last_error'] = str(error)
            atomic_json(ROOT/'status.json', previous)
            time.sleep(300)
            continue
        time.sleep(30)


if __name__ == '__main__':
    os.umask(0o077)
    ROOT.mkdir(exist_ok=True)
    # Graceful container stop also runs the maintenance cleanup finally block.
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
    try:
        action = sys.argv[1] if len(sys.argv) > 1 else 'schedule'
        if action == 'schedule': schedule()
        elif action == 'backup': backup()
        elif action == 'restore': restore(sys.argv[2])
        elif action == 'verify':
            validate(sys.argv[2]); print('Backup checksums and archive verified')
        elif action == 'health': health()
        else: raise RuntimeError('Use schedule, backup, verify NAME, restore NAME, or health')
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
