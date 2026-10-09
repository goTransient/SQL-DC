# SQL-DC — Sổ Quản Lý Dân Cư

SQL-DC is a small PHP/JavaScript application backed by SQLite by default, with
optional MySQL support selected explicitly through environment configuration.
The browser UI is served by `index.php`; `api/api.php` is the single
business-data endpoint.
Resident, household, association, activity, contribution, and Excel-import
behavior remains in that endpoint and its existing SQLite schema.

## Requirements

- PHP 8.1 or newer.
- PHP extensions: `pdo_sqlite`, `curl`, `openssl`, `zip`, and `SimpleXML`.
  `pdo_mysql` is also required when MySQL is selected.
- A writable `data/` directory for the SQLite database.
- HTTPS for deployed use.

## Google OAuth configuration

Set these environment variables in the PHP/web-server process environment or
in the optional project-root `.env` file. Existing process environment values
take precedence. SQL-DC loads only the documented OAuth and database settings
from `.env`.

| Variable | Purpose |
| --- | --- |
| `SQLDC_BASE_URL` | Canonical application URL, without a trailing slash. Use `https://host.example` at the domain root or `https://host.example/sql-dc` when installed in a subdirectory. Local development may use an `http://localhost` or loopback URL. |
| `SQLDC_GOOGLE_REDIRECT_URI` | Exact OAuth callback URL. It must equal `<SQLDC_BASE_URL>/api/google-callback.php`. |
| `SQLDC_GOOGLE_CLIENT_ID` | Google OAuth web client ID. |
| `SQLDC_GOOGLE_CLIENT_SECRET` | Google OAuth web client secret. Keep it in server-side environment configuration only. |

## Database configuration

Set `DEV_MODE=true` to use the local SQLite database at
`data/dancu.db`. Set `DEV_MODE=false` to select MySQL; all required MySQL
settings must then be provided. If `DEV_MODE` is unset, SQL-DC defaults to
`true` to preserve local SQLite behavior. Any other value is rejected.

| Variable | Purpose |
| --- | --- |
| `SQLDC_DB_HOST` | MySQL hostname or IP address. |
| `SQLDC_DB_PORT` | TCP port; defaults to `3306` when omitted. |
| `SQLDC_DB_NAME` | Existing database name. SQL-DC does not create databases. |
| `SQLDC_DB_USER` | MySQL application user. |
| `SQLDC_DB_PASSWORD` | MySQL application password; an explicitly empty password is accepted. |

In production mode, missing MySQL settings, an unavailable PDO driver, or a
connection failure is an error; SQL-DC never falls back to SQLite.
The application shares its existing PDO factory for authentication and
business-data access. MySQL schema creation is deliberately separate from
application startup and requires a separately administered schema setup.

The MySQL DDL targets MySQL 5.7+ or MariaDB 10.2+. The Alwaysdata server version
has not been verified; check its actual engine and version before applying the
schema. The application account should have only the DML privileges it needs
(`SELECT`, `INSERT`, `UPDATE`, and `DELETE`) on the seven SQL-DC tables. Do not
grant the web application `CREATE`, `ALTER`, or `DROP`.

The schema uses 64-byte maximum household and resident codes. Excel imports
reject longer codes before insertion and roll back the full import. MySQL stores
allowlist emails with case-insensitive uniqueness; application authorization
also accepts only active values `0` or `1`.

To prepare MySQL later, first confirm the target database is empty and has no
SQL-DC table-name collisions. Apply `database/mysql-schema.sql` to that database
using a database administrator; the script creates schema only and contains no
resident records. It intentionally fails on existing table names rather than
skipping or changing them. No SQLite records are migrated by this project
change. Keep local `data/dancu.db` and the SQLite schema untouched.

Register the exact redirect URI in Google Cloud Console under the OAuth client's
**Authorized redirect URIs**. For example, if the application base URL is
`https://host.example/sql-dc`, register
`https://host.example/sql-dc/api/google-callback.php`. Do not register a
wildcard or use a different callback path, scheme, host, port, or subdirectory.
Configure the OAuth consent screen and authorized test users as required by
Google for the deployment.

The callback uses the authorization-code flow with PKCE, random OAuth state and
nonce, HTTPS-only Google requests, and Google ID-token validation against the
configured client ID, issuer, expiry, nonce, and verified-email claim. SQL-DC
does not create user accounts automatically.

## Allowlisted accounts

Every active allowlisted account has the same SQL-DC permissions, including
Excel import. There are no EXORA roles or per-user import permissions.

With SQLite selected, the first run of the CLI provisioning tool initializes
the same application database at `data/dancu.db` and adds only the
authentication table. With MySQL selected, it uses that same configured PDO
connection and requires the separately created `auth_users` table. Run these
commands from the repository root:

```powershell
php api\provision-user.php add person@example.com
php api\provision-user.php deactivate person@example.com
php api\provision-user.php activate person@example.com
```

`add` fails if the normalized email already exists. `activate` and `deactivate`
only change an existing allowlist entry. Account email matching is trimmed and
case-insensitive. The provisioning tool is CLI-only and is not an account
management web page. Restrict shell and database-file access to administrators.
Deactivation takes effect on the user's next authenticated request.

Back up the SQLite database before deployment. SQLite's authentication schema
is created additively and idempotently by the existing `db()` connection. It
does not delete, rebuild, or change the business tables or their records. MySQL
schema creation is never run automatically by the application. **Do not execute
`data/dumpl.sql`**: it is an old destructive EXORA dump and is not part of
SQL-DC setup.

## Local development

Example PowerShell environment for a Google OAuth client configured with a
localhost callback:

```powershell
$env:SQLDC_BASE_URL = "http://localhost:8000"
$env:SQLDC_GOOGLE_REDIRECT_URI = "http://localhost:8000/api/google-callback.php"
$env:SQLDC_GOOGLE_CLIENT_ID = "<Google web client ID>"
$env:SQLDC_GOOGLE_CLIENT_SECRET = "<Google web client secret>"
php -S localhost:8000
```

Use credentials stored in your local shell or development secret manager; do
not put actual credentials in source files. The first allowlisted account must
be provisioned using the CLI commands above. OAuth needs a real Google client
and the exact localhost callback registered in its configuration.

The local integration smoke tests need no Google credentials and make no
requests to Google. They copy only application source into a temporary
subdirectory, use a disposable SQLite database and local-only dummy OAuth
configuration, and remove their temporary files when complete:

```powershell
php tests\auth-integration.php
```

The SQLite suite explicitly selects SQLite regardless of MySQL environment
settings. It does not simulate or claim a successful Google sign-in.

### Optional local MySQL integration tests

The optional suite exercises the same auth/API/CRUD/import/provisioning
workflows against MySQL. It is restricted to loopback MySQL hosts and refuses
to proceed unless the database name begins with `sqldc_test_`, the disposable
database confirmation is set, and the selected database has no tables or
views. The suite creates the schema and test records but never drops or resets
tables; use a new empty disposable database for each run. It does not connect
to Alwaysdata.

For a local MySQL server, create a fresh empty database and test-only account
with schema-creation rights, then set the following in PowerShell (use a
locally managed password, not a source-controlled value):

```powershell
$env:DEV_MODE = "false"
$env:SQLDC_DB_HOST = "127.0.0.1"
$env:SQLDC_DB_PORT = "3306"
$env:SQLDC_DB_NAME = "sqldc_test_local"
$env:SQLDC_DB_USER = "sqldc_test_user"
$env:SQLDC_DB_PASSWORD = "<local disposable database password>"
$env:SQLDC_MYSQL_TEST_DISPOSABLE = "YES"
$env:SQLDC_RUN_MYSQL_INTEGRATION = "1"
php tests\mysql-integration.php
```

The MySQL suite skips unless explicitly enabled. It refuses remote hosts,
non-test database names, missing disposable confirmation, or any pre-existing
tables/views. Since it leaves its test schema and synthetic records in place,
create a new empty dedicated test database before a subsequent run.

## Security and deployment notes

- Serve the application over HTTPS. Set `SQLDC_BASE_URL` and the callback URI to
  the canonical HTTPS URLs; the session cookie is Secure for HTTPS configuration
  and uses HttpOnly, a base-path-scoped path, and SameSite=Lax for the OAuth
  callback.
- Keep `data/`, the SQLite database, environment configuration, and PHP logs
  inaccessible as static web content. The supplied `data/.htaccess` denies
  Apache access to `data/`; apply equivalent controls in other web servers.
- Use a least-privilege service account: the PHP process needs access to the
  selected database, while administrative shell access should be limited.
- The initial public login and OAuth callback are intentionally unauthenticated.
  `api/api.php` checks the allowlisted session before dispatching every business
  action; all POST requests, including import, also require a session-bound
  CSRF token and same-origin `Origin`.
- Idle sessions expire after three hours. Active allowlist status is checked
  against SQLite on authenticated requests.
- An authenticated account can replace all household and resident records with
  an Excel import. Grant allowlist access only to trusted operators and retain
  tested, restorable database backups.
- The application uses the existing `db()` PDO connection for both business
  data and authentication; it does not create a second database connection.

## Main application files

- `index.php`, `css/style.css` — application shell and styling.
- `js/` — dashboard, resident, association, activity, import, and API-client
  behavior.
- `api/api.php` — central business API and Excel import.
- `api/db.php` — shared PDO factory, SQLite schema initialization, and common
  database helpers.
- `database/mysql-schema.sql` — separately applied MySQL schema; never run
  automatically by the application.
- `api/google-login.php`, `api/google-callback.php`,
  `api/google-logout.php`, `api/session.php`, `api/auth-helpers.php`,
  `api/auth-config.php`, `api/current-user.php` — OAuth and session handling.
- `api/provision-user.php` — CLI-only allowlist provisioning.
