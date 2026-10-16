<?php

use App\Models\User;
use App\Support\Console;
use App\Support\DatabaseContext;
use App\Support\MultiFactor;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use App\Support\Outbox;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

// Provisioning commands. Each names the database role it needs: the migration owner only
// where the application roles deliberately cannot act (roles, accounts, memberships).

Artisan::command('platform:roles', function () {
    $owner = DB::connection('owner');
    $owner->unprepared(file_get_contents(base_path('deploy/roles.sql')));
    // Passwords come from the environment, never from a file in the repository.
    foreach (['school_runtime' => env('DB_PASSWORD'), 'platform_admin' => env('DB_CONSOLE_PASSWORD'), 'platform_auth' => env('DB_AUTH_PASSWORD')] as $role => $password) {
        if ($password) $owner->statement('ALTER ROLE '.$role.' PASSWORD '.$owner->getPdo()->quote($password));
    }
    // The key that signs the request's context (SECURITY-REVIEW.md, F7), from the application's.
    $owner->statement("CALL secret.set_context_key(decode(?, 'hex'))", [bin2hex(DatabaseContext::key())]);
    $this->info('Roles and privileges applied.');
})->purpose('Apply deploy/roles.sql, the role passwords and the context key (as the database owner)');

// Deployment (DEPLOY.md): the bundled Garage storage gets its layout, the school files' bucket
// and the application's key, allowed on that bucket only. Safe to run again; nothing to do when
// the deployment uses another S3 provider.
Artisan::command('platform:storage', function () {
    $admin = env('GARAGE_ADMIN_URL');
    if (!$admin) {
        $this->info('S3 storage provided outside this deployment: nothing to prepare.');
        return 0;
    }
    $http = Http::baseUrl($admin)->withToken((string) env('GARAGE_ADMIN_TOKEN'))->acceptJson()
        ->retry(30, 1000, fn ($e) => $e instanceof ConnectionException, throw: false); // Garage may still be starting
    $node = $http->get('/v2/GetClusterStatus')->throw()->json('nodes.0.id');
    if ($http->get('/v2/GetClusterLayout')->throw()->json('version') === 0) {
        $http->post('/v2/UpdateClusterLayout', ['roles' => [['id' => $node, 'zone' => 'local', 'tags' => [],
            'capacity' => (int) env('GARAGE_CAPACITY_BYTES', 100 * 1024 ** 3)]]])->throw();
        $http->post('/v2/ApplyClusterLayout', ['version' => 1])->throw();
    }
    [$key, $secret, $bucket] = [(string) env('AWS_ACCESS_KEY_ID'), (string) env('AWS_SECRET_ACCESS_KEY'), (string) env('AWS_BUCKET')];
    if (!preg_match('/^GK[0-9a-f]{24}$/', $key) || !preg_match('/^[0-9a-f]{64}$/', $secret)) {
        $this->error('AWS_ACCESS_KEY_ID must be GK and 24 hexadecimal digits, AWS_SECRET_ACCESS_KEY 64 hexadecimal digits (DEPLOY.md).');
        return 1;
    }
    if ($http->get('/v2/GetKeyInfo', ['id' => $key])->failed()) {
        $http->post('/v2/ImportKey', ['accessKeyId' => $key, 'secretAccessKey' => $secret, 'name' => 'madrassa'])->throw();
    }
    $found = $http->get('/v2/GetBucketInfo', ['globalAlias' => $bucket]);
    $id = $found->successful() ? $found->json('id') : $http->post('/v2/CreateBucket', ['globalAlias' => $bucket])->throw()->json('id');
    $http->post('/v2/AllowBucketKey', ['bucketId' => $id, 'accessKeyId' => $key,
        'permissions' => ['read' => true, 'write' => true, 'owner' => false]])->throw();
    $this->info('Storage ready: bucket '.$bucket.'.');
})->purpose('Prepare the bundled Garage storage: layout, bucket, application key (deployment)');

// The security review's access matrix (SECURITY-REVIEW.md): every route of the API and every
// screen, with who may reach it. PermissionCoverageTest keeps the review's copy up to date.
Artisan::command('platform:access-matrix', function () {
    $rows = [];
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (!preg_match('#^(api|school|console)(/|$)#', $uri) || str_contains($uri, 'livewire')) continue;
        $middleware = array_filter($route->gatherMiddleware(), 'is_string');
        $declared = fn (string $prefix) => implode(', ', array_map(fn ($m) => str_replace(',', ', ', substr($m, strlen($prefix))),
            array_filter($middleware, fn ($m) => str_starts_with($m, $prefix))));
        $access = match (true) {
            str_starts_with($uri, 'console') => in_array(\Filament\Http\Middleware\Authenticate::class, $middleware, true) ? 'operator, MFA' : 'public',
            in_array($route->getName(), \App\Http\Middleware\SchoolPanelContext::PERSONAL, true) => 'signed in',
            in_array('school', $middleware, true) || in_array(\App\Http\Middleware\SchoolPanelContext::class, $middleware, true) => 'member of the school',
            in_array('signed', $middleware, true) => 'signed link',
            in_array('auth', $middleware, true) || in_array(\Filament\Http\Middleware\Authenticate::class, $middleware, true) => 'signed in',
            default => 'public',
        };
        $permission = $declared('permission:');
        if ($access === 'member of the school' && $permission === '') $permission = '(own records)';
        $rows[] = sprintf('| %s | `/%s` | %s | %s | %s |', implode(' ', array_diff($route->methods(), ['HEAD'])), $uri, $access,
            $permission === '' ? '' : ($permission === '(own records)' ? $permission : '`'.$permission.'`'), $declared('module:'));
    }
    sort($rows);
    $this->line("| Method | Path | Access | Permission (one of) | Module |\n|---|---|---|---|---|\n".implode("\n", array_unique($rows)));
})->purpose('Print the access matrix of the API and the screens (SECURITY-REVIEW.md)');

Artisan::command('platform:user {email} {--name=} {--password=} {--mfa}', function (string $email, MultiFactor $authenticator) {
    $email = mb_strtolower($email);
    $password = $this->option('password') ?: Str::password(16);
    DB::connection('owner')->table('users')->updateOrInsert(['email' => $email],
        ['name' => $this->option('name') ?: $email, 'password' => Hash::make($password), 'updated_at' => now(), 'created_at' => now()]);
    $this->info('Account ready: '.$email);
    if (!$this->option('password')) $this->warn('Temporary password, shown once: '.$password);
    // FR-IAM-02: an account with MFA from the start, e.g. an operator of the console.
    if ($this->option('mfa')) {
        $secret = $authenticator->newSecret();
        // Read as the owner: outside any school, the application's role reads nobody (SECURITY-REVIEW.md, F2).
        $codes = $authenticator->enable(User::on('owner')->where('email', $email)->firstOrFail(), $secret, 0);
        $this->warn('MFA secret, shown once, for an authenticator application: '.$secret);
        $this->line($authenticator->uri($secret, $email));
        $this->warn('Recovery codes, shown once: '.implode(' ', $codes));
    }
})->purpose('Create or reset an account, with MFA if asked (as the database owner)');

Artisan::command('platform:create-school {slug} {name} {--plan=essential} {--profile=private_school_dz} {--locale=fr} {--admin=} {--site-code=MAIN}
    {--site-name=} {--students=500} {--storage=1024} {--until=}', function (string $slug, string $name) {
    $admin = $this->option('admin')
        ? DB::connection('owner')->table('users')->where('email', mb_strtolower($this->option('admin')))->value('id')
        : null;
    if ($this->option('admin') && !$admin) { $this->error('Unknown account: '.$this->option('admin')); return 1; }
    // The slug decides the creation key: running the command again returns the same school.
    $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'madrassa:school:'.$slug)->toString();
    $id = DB::connection('console')->selectOne('SELECT platform.create_tenant(?, ?, ?, ?, ?, ?::date, ?::date, ?, ?, ?, ?, ?, ?) AS id', [
        $key, $slug, $name, $this->option('locale'), $this->option('plan'), now()->toDateString(), $this->option('until') ?: null,
        (int) $this->option('students'), (int) $this->option('storage'), $this->option('site-code'), $this->option('site-name') ?: null, $admin,
        $this->option('profile') ?: null,
    ])->id;
    $this->info('School ready: '.$id);
    if ($admin) return $this->call('platform:invite-admin', ['slug' => $slug]);
})->purpose('Create a school with its plan, profile, default site and invited administrator (as platform_admin)');

Artisan::command('platform:apply-profile {slug} {profile} {--profile-version=}', function (string $slug, string $profile) {
    $console = DB::connection('console');
    $tenant = $console->table('platform.tenant')->where('slug', $slug)->value('id');
    if (!$tenant) { $this->error('Unknown school.'); return 1; }
    // FR-PLT-03: only on request; adds what the school lacks, changes nothing it has.
    try {
        $version = $console->selectOne('SELECT platform.apply_profile(?, ?, ?) AS v', [$tenant, $profile, $this->option('profile-version') ?: null])->v;
    } catch (QueryException $e) {
        $this->error(DatabaseErrors::classify($e)[2] ?? throw $e);
        return 1;
    }
    $this->info("Profile $profile version $version applied.");
})->purpose('Apply a profile version to a school, adding what it lacks (as platform_admin)');

Artisan::command('platform:invite-admin {slug}', function (string $slug, Console $console) {
    $tenant = DB::connection('console')->table('platform.tenant')->where('slug', $slug)->value('id');
    if (!$tenant) { $this->error('Unknown school.'); return 1; }
    // As the console does: in the school's language, the reason in its journal.
    try {
        $sent = $console->inviteAdmin($tenant);
    } catch (QueryException $e) {
        $this->warn('No administrator awaiting activation in this school.');
        return 0;
    }
    if ($sent['delivery'] === 'sent') {
        $this->info('Invitation sent (valid 72 hours).');
        return 0;
    }
    // FR-IAM-01: sending failed; the link goes to the administrator another way. Never logged.
    $this->warn('Sending failed. Activation link, valid 72 hours, to hand over securely:');
    $this->line($sent['activation_url']);
    return 0;
})->purpose('Send the activation link to the administrator named when the school was created (as platform_admin)');

Artisan::command('platform:membership {slug} {email} {--status=active} {--role=*}', function (string $slug, string $email) {
    $owner = DB::connection('owner');
    $tenant = $owner->table('platform.tenant')->where('slug', $slug)->value('id');
    $user = $owner->table('users')->where('email', mb_strtolower($email))->value('id');
    if (!$tenant || !$user) { $this->error('Unknown school or account.'); return 1; }
    $roles = $this->option('role');
    $known = $owner->table('school.role')->where('tenant_id', $tenant)->whereIn('code', $roles)->pluck('id', 'code');
    if ($unknown = array_diff($roles, $known->keys()->all())) { $this->error('Unknown role: '.implode(', ', $unknown)); return 1; }
    $owner->transaction(function () use ($owner, $tenant, $user, $known) {
        $owner->statement('INSERT INTO school.membership (tenant_id, user_id, status) VALUES (?, ?, ?)
            ON CONFLICT (tenant_id, user_id) DO UPDATE SET status = EXCLUDED.status', [$tenant, $user, $this->option('status')]);
        // A role for the whole school, once: running the command again adds nothing.
        foreach ($known as $role) {
            $owner->statement("INSERT INTO school.role_assignment (tenant_id, user_id, role_id) SELECT ?, ?, ? WHERE NOT EXISTS (
                SELECT 1 FROM school.role_assignment WHERE tenant_id = ? AND user_id = ? AND role_id = ? AND scope_type = 'tenant'
                AND archived_at IS NULL AND (valid_until IS NULL OR valid_until >= current_date))", [$tenant, $user, $role, $tenant, $user, $role]);
        }
    });
    $this->info('Membership '.$this->option('status').($roles ? ', roles '.implode(', ', $roles) : '').'.');
})->purpose('Set an account\'s membership in a school, with whole-school roles if asked (as the database owner)');

// FR-NOT-01, ARC-05: the email outbox, worked every minute by the scheduler (`php artisan schedule:work`).
Artisan::command('platform:outbox {--limit=50}', function (Outbox $outbox) {
    $done = $outbox->work((int) $this->option('limit'));
    $this->info("Emails sent: {$done['sent']}, failed: {$done['failed']}.");
})->purpose('Send the notification emails that are due (as school_runtime)');

Schedule::command('platform:outbox')->everyMinute()->withoutOverlapping();

// FR-CRM-01 AC1: the prospects' reminders that are due notify their assignee, once.
Artisan::command('platform:lead-reminders {--limit=500}', function () {
    $sent = DB::selectOne('SELECT platform.notify_due_reminders(?) AS n', [(int) $this->option('limit')])->n;
    $this->info("Reminders notified: $sent.");
})->purpose('Notify the assignees of the reminders of prospects that are due (as school_runtime)');

Schedule::command('platform:lead-reminders')->everyMinute()->withoutOverlapping();

// FR-IMP-02: temporary files (exports) leave storage once expired.
Artisan::command('platform:purge-files {--limit=500}', function () {
    $purged = 0;
    foreach (DB::select('SELECT id, storage_key FROM platform.expired_files(?)', [(int) $this->option('limit')]) as $file) {
        Storage::disk('files')->delete($file->storage_key);
        DB::select('SELECT platform.mark_purged(?)', [$file->id]);
        $purged++;
    }
    $this->info("Files purged: $purged.");
})->purpose('Remove expired temporary files from storage (as school_runtime)');

Schedule::command('platform:purge-files')->daily()->withoutOverlapping();

// NFR-DATA-03, FR-ELV-03: each school's retention policy, applied once a day.
Artisan::command('platform:retention {--limit=1000}', function () {
    $schools = 0;
    foreach (DB::select('SELECT * FROM platform.apply_retention(?)', [(int) $this->option('limit')]) as $run) {
        $schools++;
        $this->info("School $run->tenant_id: $run->archived archived, $run->anonymised pupils and $run->guardians guardians anonymised, "
            ."$run->journal journal entries purged, $run->kept kept by a rule.");
    }
    // The prospects lost long enough (SQL 042).
    foreach (DB::select('SELECT * FROM platform.apply_lead_retention(?)', [(int) $this->option('limit')]) as $run) {
        $this->info("School $run->tenant_id: $run->leads lost prospects anonymised.");
    }
    $this->info("Retention applied: $schools school(s) changed.");
})->purpose("Archive, anonymise and purge what each school's retention policy says is due (as school_runtime)");

Schedule::command('platform:retention')->daily()->withoutOverlapping();

Artisan::command('platform:operator {email} {--revoke}', function (string $email) {
    $owner = DB::connection('owner');
    $user = $owner->table('users')->where('email', mb_strtolower($email))->value('id');
    if (!$user) { $this->error('Unknown account: create it first with platform:user.'); return 1; }
    if ($this->option('revoke')) {
        $owner->update('UPDATE platform.operator SET revoked_at = now() WHERE user_id = ? AND revoked_at IS NULL', [$user]);
        $this->info('Console access revoked.');
        return 0;
    }
    $owner->statement('INSERT INTO platform.operator (user_id) VALUES (?) ON CONFLICT (user_id) DO UPDATE SET revoked_at = NULL', [$user]);
    $this->info('Console operator: '.mb_strtolower($email).'. MFA is set up at the first sign-in, on /console.');
})->purpose('Give or revoke access to the publisher console (as the database owner)');
