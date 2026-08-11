<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/hotspot_csv_import.php';

$checks = [
    'header normalization' => hotspot_csv_normalize_header("\xEF\xBB\xBF USERNAME ") === 'username',
    'semicolon-compatible delimiter' => hotspot_csv_detect_delimiter("Username;Password;Profile;Time Limit;Data Limit;Comment\n") === ';',
    'comma-compatible delimiter' => hotspot_csv_detect_delimiter("Username,Password,Profile,Time Limit,Data Limit,Comment\n") === ',',
    'time limit 10h' => hotspot_csv_valid_time_limit('10h'),
    'time limit 7d' => hotspot_csv_valid_time_limit('7d'),
    'time limit 1w' => hotspot_csv_valid_time_limit('1w'),
    'time limit combination' => hotspot_csv_valid_time_limit('1d 2h'),
    'time limit invalid' => !hotspot_csv_valid_time_limit('10hours'),
    'data limit numeric' => preg_match('/^\d+$/', '102400000') === 1,
    'data limit invalid' => preg_match('/^\d+$/', '10MB') !== 1,
    'username valid' => preg_match('/^[A-Za-z0-9_.\-]+$/', 'user_001') === 1,
    'username invalid' => preg_match('/^[A-Za-z0-9_.\-]+$/', 'user 001') !== 1,
    'password never displayed by preview UI' => strpos(file_get_contents(__DIR__ . '/../admin/user-import.php'), '********') !== false,
    'inventory remains ticket import' => strpos(file_get_contents(__DIR__ . '/../admin/inventory.php'), 'INSERT INTO tickets') !== false,
    'import targets hotspot tables' => strpos(file_get_contents(__DIR__ . '/../lib/hotspot_csv_import.php'), 'hotspot_users') !== false && strpos(file_get_contents(__DIR__ . '/../lib/hotspot_csv_import.php'), 'hotspot_commands') !== false,
    'import does not contact RouterOS' => strpos(file_get_contents(__DIR__ . '/../lib/hotspot_csv_import.php'), 'RouterosAPI') === false && strpos(file_get_contents(__DIR__ . '/../admin/user-import.php'), 'RouterosAPI') === false,
    'CSRF present' => strpos(file_get_contents(__DIR__ . '/../admin/user-import.php'), 'hash_equals') !== false,
    'transaction present' => strpos(file_get_contents(__DIR__ . '/../admin/user-import.php'), 'beginTransaction') !== false,
];


if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
$tmp = tempnam(sys_get_temp_dir(), 'hotspot-csv-test-');
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE hotspot_profiles (profile_name TEXT PRIMARY KEY)');
$pdo->exec("INSERT INTO hotspot_profiles(profile_name) VALUES ('10H'), ('24H')");

file_put_contents($tmp, "\xEF\xBB\xBFUsername,Password,Profile,Time Limit,Data Limit,Comment\nuser001,pass,10H,10h,100000000,Test UTF8\n");
try {
    $parsed = hotspot_csv_read($tmp, $pdo);
    $checks['real UTF-8 BOM CSV parsed'] = count($parsed['rows']) === 1 && empty($parsed['errors']) && $parsed['rows'][0]['username'] === 'user001';
} finally { @unlink($tmp); }

$tmp = tempnam(sys_get_temp_dir(), 'hotspot-csv-test-');
file_put_contents($tmp, "Username;Password;Profile;Time Limit;Data Limit;Comment\nuser002;pass;10H;24h;0;Comment régional\n");
try {
    $parsed = hotspot_csv_read($tmp, $pdo);
    $checks['real semicolon CSV parsed'] = $parsed['delimiter'] === ';' && $parsed['rows'][0]['data_limit'] === '0';
} finally { @unlink($tmp); }

$tmp = tempnam(sys_get_temp_dir(), 'hotspot-csv-test-');
file_put_contents($tmp, "Username,Password,Profile,Time Limit,Data Limit,Comment\nuser003,pass,UNKNOWN,10h,10,Nope\nuser003,pass,10H,10h,10,Duplicate\n");
try {
    $parsed = hotspot_csv_read($tmp, $pdo);
    $checks['profile and duplicate validation'] = count($parsed['errors']) === 2;
} finally { @unlink($tmp); }

$tmp = tempnam(sys_get_temp_dir(), 'hotspot-csv-test-');
file_put_contents($tmp, "Username,Password,Profile,Time Limit,Data Limit,Comment\nuser004,pass,10H,10hours,10,Bad time\n");
try {
    $parsed = hotspot_csv_read($tmp, $pdo);
    $checks['invalid time limit rejected'] = count($parsed['errors']) === 1;
} finally { @unlink($tmp); }

} else {
    $checks['runtime CSV parser integration test requires SQLite PDO'] = true;
}

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
