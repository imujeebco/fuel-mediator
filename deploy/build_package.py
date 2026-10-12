"""Build an upload-ready zip for hosts without SSH (CWP / shared hosting).

The zip holds the committed HEAD with production dependencies (vendor/) already installed, the Apache .htaccess,
a REVISION file and core-engine/.env.cwp (a filled-in template with a fresh MEDIATOR_SECRET_KEY; rename it to .env
on the server). Tests and dev tooling are left out.

Usage (repository root):  python deploy/build_package.py [--php PATH] [--composer PATH]
Output: dist/fuel-mediator-<revision>.zip
"""
import argparse
import base64
import io
import os
import shutil
import subprocess
import sys
import tarfile
import tempfile
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EXCLUDE_PREFIXES = ('core-engine/tests/', 'core-engine/docker/', 'core-engine/.github/')
EXCLUDE_FILES = {'core-engine/codeception.yml', 'core-engine/psalm.xml', 'core-engine/rector.php', 'core-engine/c3.php',
                 'core-engine/composer-dependency-analyser.php', 'core-engine/Makefile'}

ENV_TEMPLATE = """# FuelPay Mediator, production settings for the CWP host. Rename this file to .env and fill in the blanks.
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://mediator.fuel.net.sa

# MariaDB database created in the panel
DB_HOST=localhost
DB_PORT=3306
DB_NAME=
DB_USER=
DB_PASSWORD=

# Odoo: relay target for devices, and read-only user for stations / sales / sessions
ODOO_URL=https://fuelksa.odoo.com
ODOO_DB=iwesabe-fuel-main-11731627
ODOO_USER=
ODOO_PASSWORD=

RETENTION_DAYS=90

# Leave EMPTY until the device switch-over (docs/deployment-production.md). Then: https://mediator.fuel.net.sa
MEDIATOR_PUBLIC_URL=

# D21: report fills already paid by another sale as paid ("0" = off)
SETTLED_FILL_FILTER=1

# D23: encrypts controller passwords read from Odoo (generated for this package; keep it private)
MEDIATOR_SECRET_KEY={key}

# Dashboard login. Generate the hash at https://<host>/login/password-hash and paste it here.
ADMIN_USER=admin
ADMIN_PASSWORD_HASH=
"""


def run(cmd, cwd):
    print('>', ' '.join(cmd))
    subprocess.run(cmd, cwd=cwd, check=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--php', default=r'C:\Programs\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe')
    parser.add_argument('--composer', default=r'C:\Programs\laragon\bin\composer\composer.phar')
    args = parser.parse_args()

    status = subprocess.run(['git', 'status', '--porcelain'], cwd=ROOT, capture_output=True, text=True, check=True).stdout
    if status.strip():
        print('Uncommitted changes are not packaged (the zip is built from HEAD):\n' + status)
    rev = subprocess.run(['git', 'rev-parse', '--short', 'HEAD'], cwd=ROOT, capture_output=True, text=True, check=True).stdout.strip()

    work = tempfile.mkdtemp(prefix='fuel-mediator-pkg-')
    try:
        archive = subprocess.run(['git', 'archive', '--format=tar', 'HEAD'], cwd=ROOT, capture_output=True, check=True).stdout
        with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
            tar.extractall(work, filter='data')
        core = os.path.join(work, 'core-engine')

        run([args.php, args.composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress',
             '--ignore-platform-req=ext-intl'], cwd=core)
        shutil.copyfile(os.path.join(work, 'deploy', 'apache', 'htaccess'), os.path.join(core, 'public', '.htaccess'))
        with open(os.path.join(work, 'REVISION'), 'w', encoding='utf-8') as f:
            f.write(rev + '\n')
        key = base64.b64encode(os.urandom(32)).decode()
        with open(os.path.join(core, '.env.cwp'), 'w', encoding='utf-8', newline='\n') as f:
            f.write(ENV_TEMPLATE.format(key=key))
        os.makedirs(os.path.join(work, 'logs'), exist_ok=True)
        with open(os.path.join(work, 'logs', '.keep'), 'w') as f:
            f.write('')
        os.makedirs(os.path.join(core, 'runtime'), exist_ok=True)

        out_dir = os.path.join(ROOT, 'dist')
        os.makedirs(out_dir, exist_ok=True)
        out = os.path.join(out_dir, f'fuel-mediator-{rev}.zip')
        count = 0
        with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=6) as z:
            for base, dirs, files in os.walk(work):
                for name in dirs + files:
                    full = os.path.join(base, name)
                    rel = os.path.relpath(full, work).replace(os.sep, '/')
                    if rel.startswith(EXCLUDE_PREFIXES) or rel in EXCLUDE_FILES:
                        continue
                    arc = 'fuel-mediator/' + rel + ('/' if os.path.isdir(full) else '')
                    if os.path.isdir(full):
                        z.writestr(zipfile.ZipInfo(arc), '')
                    else:
                        z.write(full, arc)
                        count += 1
        print(f'\nWrote {out} ({count} files, {os.path.getsize(out) / 1e6:.1f} MB), revision {rev}.')
        print('core-engine/.env.cwp inside the zip holds a fresh MEDIATOR_SECRET_KEY: keep the zip private.')
        return 0
    finally:
        shutil.rmtree(work, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
