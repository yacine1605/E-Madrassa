<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_starts_with((string)getenv('SESSION_COOKIE'), 'madrassa-integration-backup-')) throw new RuntimeException('Isolated backup test only');
config(['app.key'=>trim(file_get_contents(storage_path('app/.app-key')))]);
$file = storage_path('app/private/recovery-test.json');
if (($argv[1] ?? '') === 'create') {
    file_put_contents($file, json_encode(['encrypted'=>Illuminate\Support\Facades\Crypt::encryptString('recovery-proof'), 'upload'=>'École الجزائر']));
    chmod($file, 0600);
} else {
    $data = json_decode(file_get_contents($file), true);
    if ($data['upload'] !== 'École الجزائر' || Illuminate\Support\Facades\Crypt::decryptString($data['encrypted']) !== 'recovery-proof') throw new RuntimeException('Uploaded file or encryption key lost');
    if (!Illuminate\Support\Facades\DB::table('users')->where('email', 'browser-integration@example.test')->exists()) throw new RuntimeException('Restored account missing');
}
echo json_encode(['verified'=>true]);
