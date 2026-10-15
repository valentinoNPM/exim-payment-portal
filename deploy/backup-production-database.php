<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

if (count($argv) !== 3) {
    fwrite(STDERR, "Usage: php backup-production-database.php APP_ROOT BACKUP_FILE\n");
    exit(2);
}

$appRoot = realpath($argv[1]);
$backupDirectory = realpath(dirname($argv[2]));

if ($appRoot === false || $backupDirectory === false || str_starts_with($backupDirectory.DIRECTORY_SEPARATOR, $appRoot.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "The application root must exist and the backup must be outside it.\n");
    exit(2);
}

if (file_exists($argv[2])) {
    fwrite(STDERR, "The backup destination already exists.\n");
    exit(2);
}

require $appRoot.'/vendor/autoload.php';
$app = require $appRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connectionName = config('database.default');
$database = config("database.connections.{$connectionName}");

if (! is_array($database) || ($database['driver'] ?? null) !== 'mysql') {
    fwrite(STDERR, "The production database connection is not MySQL.\n");
    exit(2);
}

$command = [
    '/usr/bin/mysqldump',
    '--single-transaction',
    '--quick',
    '--routines',
    '--triggers',
    '--hex-blob',
    '--no-tablespaces',
    '--default-character-set=utf8mb4',
    '--user='.(string) $database['username'],
];

if (filled($database['unix_socket'] ?? null)) {
    $command[] = '--socket='.(string) $database['unix_socket'];
} else {
    $command[] = '--host='.(string) ($database['host'] ?? '127.0.0.1');
    $command[] = '--port='.(string) ($database['port'] ?? '3306');
}

$command[] = '--';
$command[] = (string) $database['database'];

$output = fopen($argv[2], 'x');

if ($output === false) {
    fwrite(STDERR, "Could not create the backup destination.\n");
    exit(2);
}

$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => $output,
    2 => ['pipe', 'w'],
], $pipes, $appRoot, [
    'PATH' => (string) getenv('PATH'),
    'HOME' => (string) getenv('HOME'),
    'MYSQL_PWD' => (string) ($database['password'] ?? ''),
]);

if (! is_resource($process)) {
    fclose($output);
    unlink($argv[2]);
    fwrite(STDERR, "Could not start mysqldump.\n");
    exit(1);
}

fclose($pipes[0]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exitCode = proc_close($process);
fclose($output);

if ($exitCode !== 0 || filesize($argv[2]) < 100) {
    unlink($argv[2]);
    fwrite(STDERR, 'Database backup failed: '.trim($error)."\n");
    exit(1);
}

echo 'Database backup bytes: '.filesize($argv[2]).PHP_EOL;
echo 'Database backup SHA-256: '.hash_file('sha256', $argv[2]).PHP_EOL;
