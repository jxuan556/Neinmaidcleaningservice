<?php
// config.php - simple environment loader
$envFile = __DIR__ . '/.env';

if (file_exists($envFile)) {
  foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
    putenv("$k=$v");
  }
}
