"""The platform's backups (NFR-REC-01, NFR-SEC-09): the database, the files' bucket and the
encryption key, encrypted, kept N days; restored only into a fresh installation (NFR-REC-02).

    schedule | backup | verify NAME | restore NAME | health
"""
import contextlib
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path('/backups')
STORAGE = Path('/app-storage')
PROJECT = os.environ.get('BACKUP_PROJECT', 'madrassa-platform')
BUCKET = os.environ.get('FILES_BUCKET', 'madrassa-files')
ALGERIA = dt.timezone(dt.timedelta(hours=1))
ARCHIVES = ('database.dump', 'key.tar.gz', 'objects.tar.gz')
# The roles the schema's privileges name (001_foundation.sql); a fresh database lacks them.
ROLES = {'school_runtime': 'NOBYPASSRLS', 'platform_admin': 'NOBYPASSRLS', 'platform_provisioner': 'BYPASSRLS'}


def sql(query):
    return subprocess.check_output(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-At', '-c', query], text=True).strip()


def wait_database():
    for _ in range(60):
        if subprocess.run(['pg_isready'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
            if int(sql('SHOW server_version_num')) // 10000 != 16:
                raise RuntimeError('This backup workflow requires PostgreSQL 16')
            return
        time.sleep(1)
    raise RuntimeError('PostgreSQL did not become ready within 60 seconds')


def counts():
    tables = json.loads(sql("SELECT coalesce(json_agg(format('%I.%I', schemaname, tablename) ORDER BY schemaname, tablename), '[]') "
                            "FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')"))
    return {table: int(sql('SELECT count(*) FROM ' + table)) for table in tables}


def bucket_size():
    """Objects and bytes in the files' bucket, as rclone counts them."""
    size = json.loads(subprocess.check_output(['rclone', 'size', '--json', 'files:' + BUCKET], text=True))
    return {'objects': size['count'], 'bytes': size['bytes']}


def digest(file):
    with file.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def passphrase():
    value = os.environ.get('BACKUP_PASSPHRASE', '')
    if len(value) < 12:
        raise RuntimeError('BACKUP_PASSPHRASE (12 characters or more) is required: backups hold personal data and the encryption key')
    return value


def gpg(args, source, target):
    # The passphrase on stdin, never on a command line.
    with tempfile.TemporaryDirectory() as home:
        subprocess.run(['gpg', '--homedir', home, '--batch', '--yes', '--quiet', '--pinentry-mode', 'loopback',
                        '--passphrase-fd', '0', *args, '--output', str(target), str(source)], input=passphrase().encode(), check=True)


def encrypt(source):
    gpg(['--symmetric', '--cipher-algo', 'AES256', '--compress-algo', 'none'], source, source.with_name(source.name + '.gpg'))
    source.unlink()


def atomic_json(path, value):
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(value, indent=2) + '\n')
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
        raise RuntimeError('The encryption key is missing from the storage volume: start the installation first')
    framework = STORAGE / 'framework'
    framework.mkdir(parents=True, exist_ok=True)
    # One backup at a time; a second process never removes another's maintenance marker.
    with (framework / 'madrassa-backup.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        unlock_maintenance()  # Our own marker, left by an interrupted backup.
        marker = framework / 'down'
        if marker.exists():
            raise RuntimeError('The application is already in maintenance; backup postponed')
        passphrase()  # Refused before maintenance, not after the dump.
        now = dt.datetime.now(dt.timezone.utc)
        name = 'backup-' + now.strftime('%Y%m%dT%H%M%S%fZ')
        staging = ROOT / ('.partial-' + name)
        staging.mkdir(mode=0o700)
        try:
            # Requests answer 503 and jobs pause: the database and the files are copied at rest.
            atomic_json(marker, {'time': int(time.time()), 'retry': 60, 'refresh': 15, 'status': 503, 'template': None,
                                 'redirect': None, 'except': [], 'madrassa_backup': PROJECT})
            marker.chmod(0o644)  # Read by the application, which runs as www-data.
            time.sleep(int(os.environ.get('BACKUP_DRAIN_SECONDS', '15')))
            subprocess.run(['pg_dump', '--format=custom', '--file=' + str(staging / 'database.dump')], check=True)
            snapshot = counts()
            with tarfile.open(staging / 'key.tar.gz', 'w:gz') as archive:
                archive.add(STORAGE / 'app/.app-key', arcname='app/.app-key')
            objects = staging / 'objects'
            subprocess.run(['rclone', 'copy', '--quiet', 'files:' + BUCKET, str(objects)], check=True)
            stored = bucket_size()
            with tarfile.open(staging / 'objects.tar.gz', 'w:gz') as archive:
                archive.add(objects, arcname='objects')
            shutil.rmtree(objects)
            subprocess.run(['pg_restore', '--list', str(staging / 'database.dump')], check=True, stdout=subprocess.DEVNULL)
            # Encrypted at rest: the copy holds personal data and the encryption key.
            plain = {file: digest(staging / file) for file in ARCHIVES}
            for file in ARCHIVES:
                encrypt(staging / file)
            atomic_json(staging / 'manifest.json', {
                'version': 1, 'project': PROJECT, 'created_at': now.isoformat(), 'postgres_major': 16, 'database': os.environ['PGDATABASE'],
                'tables': snapshot, 'files': stored, 'bucket': BUCKET, 'encryption': 'gpg-aes256',
                'sha256': {file + '.gpg': digest(staging / (file + '.gpg')) for file in ARCHIVES}, 'sha256_plain': plain})
            staging.rename(ROOT / name)
            atomic_json(ROOT / 'status.json', {'last_success': time.time(), 'backup': name, 'last_error': None})
            # Only this installation's completed backups are pruned, after a new one succeeded.
            completed = sorted(p for p in ROOT.glob('backup-*') if p.is_dir() and (p / 'manifest.json').is_file()
                               and json.loads((p / 'manifest.json').read_text()).get('project') == PROJECT)
            for old in completed[:-keep]:
                shutil.rmtree(old)
            print(json.dumps({'backup': name, 'tables': len(snapshot), 'objects': stored['objects'], 'verified_checksums': True}), flush=True)
            return name
        finally:
            unlock_maintenance()
            if staging.exists():
                shutil.rmtree(staging)


@contextlib.contextmanager
def opened(name, deep=True):
    """Checks a backup and yields (manifest, {archive: plain file}); decrypted copies are removed afterwards."""
    if Path(name).name != name or not name.startswith('backup-'):
        raise RuntimeError('Choose a backup directory name inside /backups')
    folder = ROOT / name
    manifest = json.loads((folder / 'manifest.json').read_text())
    if manifest.get('version') != 1 or manifest.get('postgres_major') != 16:
        raise RuntimeError('Unsupported backup format')
    for file, expected in manifest['sha256'].items():
        if digest(folder / file) != expected:
            raise RuntimeError('Backup checksum failed: ' + file)
    if not deep:
        yield manifest, None
        return
    work = Path(tempfile.mkdtemp(dir=ROOT, prefix='.open-'))
    try:
        plain = {}
        for file in ARCHIVES:
            plain[file] = work / file
            gpg(['--decrypt'], folder / (file + '.gpg'), plain[file])
            if digest(plain[file]) != manifest['sha256_plain'][file]:
                raise RuntimeError('The decrypted backup differs from the original: ' + file)
        subprocess.run(['pg_restore', '--list', str(plain['database.dump'])], check=True, stdout=subprocess.DEVNULL)
        # No traversal, no links, no device files, before anything is written.
        for file, top in (('key.tar.gz', 'app'), ('objects.tar.gz', 'objects')):
            with tarfile.open(plain[file]) as archive:
                for member in archive.getmembers():
                    parts = Path(member.name).parts
                    if not parts or parts[0] != top or '..' in parts or not (member.isfile() or member.isdir()):
                        raise RuntimeError('Unsafe archive: ' + file)
        with tarfile.open(plain['key.tar.gz']) as archive:
            if not archive.extractfile('app/.app-key').read().strip().startswith(b'base64:'):
                raise RuntimeError('The encryption key is missing from the backup')
        yield manifest, plain
    finally:
        shutil.rmtree(work)


def garage(method, path, body=None):
    request = urllib.request.Request(os.environ['GARAGE_ADMIN_URL'] + path, method=method,
                                     data=None if body is None else json.dumps(body).encode(),
                                     headers={'Authorization': 'Bearer ' + os.environ.get('GARAGE_ADMIN_TOKEN', ''), 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            return json.loads(response.read() or b'null')
    except urllib.error.HTTPError as error:
        if error.code in (400, 404):
            return None
        raise


def prepare_storage():
    """The bundled Garage of a fresh installation: as `platform:storage` does, which runs only in the application's image."""
    if not os.environ.get('GARAGE_ADMIN_URL'):
        return  # Another S3 provider: its bucket exists already.
    for _ in range(30):
        try:
            node = garage('GET', '/v2/GetClusterStatus')['nodes'][0]['id']
            break
        except (urllib.error.URLError, OSError):
            time.sleep(1)
    else:
        raise RuntimeError('Garage did not answer within 30 seconds')
    if garage('GET', '/v2/GetClusterLayout')['version'] == 0:
        garage('POST', '/v2/UpdateClusterLayout', {'roles': [{'id': node, 'zone': 'local', 'tags': [],
                                                               'capacity': int(os.environ.get('GARAGE_CAPACITY_BYTES', 100 * 1024 ** 3))}]})
        garage('POST', '/v2/ApplyClusterLayout', {'version': 1})
    key, secret = os.environ['FILES_ACCESS_KEY_ID'], os.environ['FILES_SECRET_ACCESS_KEY']
    if garage('GET', '/v2/GetKeyInfo?id=' + key) is None:
        garage('POST', '/v2/ImportKey', {'accessKeyId': key, 'secretAccessKey': secret, 'name': 'madrassa'})
    found = garage('GET', '/v2/GetBucketInfo?globalAlias=' + BUCKET) or garage('POST', '/v2/CreateBucket', {'globalAlias': BUCKET})
    garage('POST', '/v2/AllowBucketKey', {'bucketId': found['id'], 'accessKeyId': key, 'permissions': {'read': True, 'write': True, 'owner': False}})


def restore(name):
    with opened(name) as (manifest, plain):
        wait_database()
        if counts():
            raise RuntimeError('Restore refused: the database is not empty. Restore into a new Compose project.')
        if STORAGE.exists() and any(STORAGE.iterdir()):
            raise RuntimeError('Restore refused: the storage volume is not empty.')
        prepare_storage()
        if bucket_size()['objects']:
            raise RuntimeError('Restore refused: the files bucket is not empty.')
        for role, rls in ROLES.items():
            sql(f"DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{role}') THEN "
                f"CREATE ROLE {role} NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE {rls}; END IF; END $$")
        # In one transaction: a failed restore leaves the database empty. Owners and privileges are
        # set again by the migrate service's `platform:roles`, with this installation's passwords.
        subprocess.run(['pg_restore', '--exit-on-error', '--single-transaction', '--no-owner', '--dbname=' + os.environ['PGDATABASE'],
                        str(plain['database.dump'])], check=True)
        if counts() != manifest['tables']:
            raise RuntimeError('The restored tables differ from the backup')
        with tempfile.TemporaryDirectory(dir=ROOT, prefix='.objects-') as work:
            with tarfile.open(plain['objects.tar.gz']) as archive:
                archive.extractall(work, filter='data')
            if (Path(work) / 'objects').exists():
                subprocess.run(['rclone', 'copy', '--quiet', str(Path(work) / 'objects'), 'files:' + BUCKET], check=True)
        if bucket_size() != manifest['files']:
            raise RuntimeError('The restored files differ from the backup')
        STORAGE.mkdir(exist_ok=True)
        with tarfile.open(plain['key.tar.gz']) as archive:
            archive.extractall(STORAGE, filter='data')
    print(json.dumps({'restored': name, 'source_project': manifest['project'], 'tables_verified': len(manifest['tables']),
                      'objects_verified': manifest['files']['objects'], 'checksums_verified': True}), flush=True)


def health():
    status = json.loads((ROOT / 'status.json').read_text())
    if status.get('last_error') or time.time() - status['last_success'] > 26 * 3600:
        raise RuntimeError('No successful recent backup: see the backup service logs')
    with opened(status['backup'], deep=False):  # Checksums only; `verify NAME` decrypts too.
        pass


def schedule():
    hour = int(os.environ.get('BACKUP_HOUR', '3'))
    if not 0 <= hour <= 23:
        raise RuntimeError('BACKUP_HOUR must be 0..23 (Algeria time)')
    # The first start and missed schedules catch up at once.
    while True:
        now = dt.datetime.now(ALGERIA)
        due = now.replace(hour=hour, minute=0, second=0, microsecond=0)
        if due > now:
            due -= dt.timedelta(days=1)
        try:
            status = json.loads((ROOT / 'status.json').read_text()) if (ROOT / 'status.json').exists() else {}
            if status.get('last_success', 0) < due.timestamp() or status.get('last_error'):
                backup()
        except Exception as error:
            # Never the credentials or the subprocesses' environment.
            print('Backup failed: ' + str(error), file=sys.stderr, flush=True)
            previous = json.loads((ROOT / 'status.json').read_text()) if (ROOT / 'status.json').exists() else {}
            previous['last_error'] = str(error)
            atomic_json(ROOT / 'status.json', previous)
            time.sleep(300)
            continue
        time.sleep(30)


if __name__ == '__main__':
    os.umask(0o077)
    ROOT.mkdir(exist_ok=True)
    # A stopped container still runs the maintenance cleanup.
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
    try:
        action = sys.argv[1] if len(sys.argv) > 1 else 'schedule'
        if action == 'schedule':
            schedule()
        elif action == 'backup':
            backup()
        elif action == 'restore':
            restore(sys.argv[2])
        elif action == 'verify':
            with opened(sys.argv[2]):
                print('Backup checksums and archives verified', flush=True)
        elif action == 'health':
            health()
        else:
            raise RuntimeError('Use schedule, backup, verify NAME, restore NAME or health')
    except Exception as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
