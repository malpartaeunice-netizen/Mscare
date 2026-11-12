<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP version:        " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
echo "PHP binary:         " . PHP_BINARY . "\n";
echo "php.ini in use:     " . (php_ini_loaded_file() ?: 'NONE LOADED') . "\n";
echo "Extra ini files:    " . (php_ini_scanned_files() ?: 'none') . "\n";
$dir = ini_get('extension_dir');
echo "extension_dir:      $dir\n\n";
echo "pdo loaded:         " . (extension_loaded('pdo') ? 'yes' : 'NO') . "\n";
echo "pdo_pgsql loaded:   " . (extension_loaded('pdo_pgsql') ? 'yes' : 'NO') . "\n";
echo "pgsql loaded:       " . (extension_loaded('pgsql') ? 'yes' : 'NO') . "\n";
echo "PDO drivers:        " . implode(', ', PDO::getAvailableDrivers()) . "\n\n";
foreach (['php_pdo_pgsql.dll', 'php_pgsql.dll'] as $f) {
    $p = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $f;
    echo "$f: " . (file_exists($p) ? "found" : "NOT FOUND at $p") . "\n";
}
echo "\nIf pdo_pgsql is NO: edit the php.ini shown above (not any other), make sure the lines read\n";
echo "extension=pdo_pgsql and extension=pgsql with no ; in front, then STOP and START Apache in XAMPP.\n";
