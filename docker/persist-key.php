<?php
// Keep the encryption key with the school's persistent storage, including on restore.
$file = '/var/www/html/storage/app/.app-key';
$envFile = '/var/www/html/.env';
$contents = file_get_contents($envFile);
preg_match('/^APP_KEY=(.*)$/m', $contents, $matches);
$configured = trim(getenv('APP_KEY') ?: ($matches[1] ?? ''), "\"' \r\n");
$saved = is_file($file) ? trim(file_get_contents($file)) : '';
if ($saved !== '' && getenv('APP_KEY') && !hash_equals($saved, $configured)) {
    throw new RuntimeException('APP_KEY conflicts with the persistent school key.');
}
$key = $saved ?: ($configured ?: 'base64:'.base64_encode(random_bytes(32)));
if (!str_starts_with($key, 'base64:') || strlen(base64_decode(substr($key, 7), true) ?: '') !== 32) {
    throw new RuntimeException('Invalid school encryption key.');
}
if ($saved === '') {
    file_put_contents($file, $key."\n", LOCK_EX);
    chmod($file, 0600);
    chown($file, 'www-data');
}
$replacement = 'APP_KEY='.$key;
$contents = preg_match('/^APP_KEY=.*$/m', $contents)
    ? preg_replace('/^APP_KEY=.*$/m', $replacement, $contents)
    : $contents."\n".$replacement."\n";
file_put_contents($envFile, $contents, LOCK_EX);
chmod($envFile, 0600);
chown($envFile, 'www-data');
