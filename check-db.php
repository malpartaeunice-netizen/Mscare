<?php
// Open http://localhost/mscare_users/check-db.php to test the PostgreSQL setup. Delete this file when done.
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');
echo "1. PHP extension pdo_pgsql: " . (extension_loaded('pdo_pgsql') ? "OK" : "MISSING - enable extension=pdo_pgsql in php.ini and restart Apache") . "\n";
if (!extension_loaded('pdo_pgsql')) exit;
try { $pdo = db(); echo "2. Connection: OK\n"; }
catch (PDOException $e) { echo "2. Connection FAILED: " . $e->getMessage() . "\n   -> check host/port/db name/user/password in includes/db.php\n"; exit; }
foreach (['branches', 'services', 'appointments', 'contact_messages'] as $t) {
    try { $n = $pdo->query("SELECT count(*) FROM $t")->fetchColumn(); echo "3. Table $t: OK ($n rows)\n"; }
    catch (PDOException $e) { echo "3. Table $t: MISSING - run database/schema.sql\n   " . $e->getMessage() . "\n"; }
}
