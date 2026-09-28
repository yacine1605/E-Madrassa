#!/usr/bin/env python3
"""Add backend files to a copy of the existing Laravel project; refuse conflicting files."""
from pathlib import Path
import argparse, shutil
p=argparse.ArgumentParser()
p.add_argument('laravel_project', type=Path)
a=p.parse_args()
source=Path(__file__).resolve().parents[1]
target=a.laravel_project.resolve()
providers=target/'bootstrap/providers.php'
if not (target/'artisan').is_file() or not providers.is_file():
    raise SystemExit('Expected Laravel project with artisan and bootstrap/providers.php')
relative=[]
for folder in ['app','routes','config','database']:
    relative += [x.relative_to(source) for x in (source/folder).rglob('*') if x.is_file()]
for path in relative:
    dest=target/path
    if dest.exists() and dest.read_bytes() != (source/path).read_bytes():
        raise SystemExit(f'Conflicting file; merge manually: {dest}')
text=providers.read_text()
provider='App\\Providers\\SchoolBackendServiceProvider::class'
if provider not in text:
    idx=text.rfind('];')
    if idx<0: raise SystemExit('Unsupported provider list; register manually.')
    prefix=text[:idx].rstrip()
    # Existing last entry may already have a trailing comma.
    if not prefix.endswith(('[',',')): prefix+=','
    text=prefix+'\n    '+provider+',\n'+text[idx:]
for path in relative:
    (target/path).parent.mkdir(parents=True,exist_ok=True)
    shutil.copy2(source/path,target/path)
providers.write_text(text)
print('Backend installed. Configure PostgreSQL, migrate as owner, provision tenant and grant runtime role. No migration executed.')
