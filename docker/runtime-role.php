<?php
// Creates or updates the restricted role that serves HTTP requests and applies its
// grants. Runs as the database owner after migrations, on every start, so new tables
// are covered. The role owns nothing and cannot bypass row-level security.
$password = getenv('DB_RUNTIME_PASSWORD');
if (!$password) {
    fwrite(STDERR, "DB_RUNTIME_PASSWORD is required: HTTP requests never use the database owner.\n");
    exit(1);
}
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432',
    getenv('DB_DATABASE'), getenv('DB_SSLMODE') ?: 'prefer');
$pdo = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents('/usr/local/share/madrassa/runtime-grants.sql'));
$pdo->exec('ALTER ROLE school_runtime PASSWORD '.$pdo->quote($password));
echo "Runtime role school_runtime ready.\n";
