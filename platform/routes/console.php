<?php

use App\Support\AccountLinks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

// Provisioning commands. Each names the database role it needs: the migration owner only
// where the application roles deliberately cannot act (roles, accounts, memberships).

Artisan::command('platform:roles', function () {
    $owner = DB::connection('owner');
    $owner->unprepared(file_get_contents(base_path('deploy/roles.sql')));
    // Passwords come from the environment, never from a file in the repository.
    foreach (['school_runtime' => env('DB_PASSWORD'), 'platform_admin' => env('DB_CONSOLE_PASSWORD')] as $role => $password) {
        if ($password) $owner->statement('ALTER ROLE '.$role.' PASSWORD '.$owner->getPdo()->quote($password));
    }
    $this->info('Roles and privileges applied.');
})->purpose('Apply deploy/roles.sql and the role passwords (as the database owner)');

Artisan::command('platform:user {email} {--name=} {--password=}', function (string $email) {
    $password = $this->option('password') ?: Str::password(16);
    DB::connection('owner')->table('users')->updateOrInsert(['email' => mb_strtolower($email)],
        ['name' => $this->option('name') ?: $email, 'password' => Hash::make($password), 'updated_at' => now(), 'created_at' => now()]);
    $this->info('Account ready: '.mb_strtolower($email));
    if (!$this->option('password')) $this->warn('Temporary password, shown once: '.$password);
})->purpose('Create or reset an account (as the database owner)');

Artisan::command('platform:create-school {slug} {name} {--plan=essential} {--locale=fr} {--admin=} {--site-code=MAIN}
    {--site-name=Site principal} {--students=500} {--storage=1024} {--until=}', function (string $slug, string $name) {
    $admin = $this->option('admin')
        ? DB::connection('owner')->table('users')->where('email', mb_strtolower($this->option('admin')))->value('id')
        : null;
    if ($this->option('admin') && !$admin) { $this->error('Unknown account: '.$this->option('admin')); return 1; }
    // The slug decides the creation key: running the command again returns the same school.
    $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'madrassa:school:'.$slug)->toString();
    $id = DB::connection('console')->selectOne('SELECT platform.create_tenant(?, ?, ?, ?, ?, ?::date, ?::date, ?, ?, ?, ?, ?) AS id', [
        $key, $slug, $name, $this->option('locale'), $this->option('plan'), now()->toDateString(), $this->option('until') ?: null,
        (int) $this->option('students'), (int) $this->option('storage'), $this->option('site-code'), $this->option('site-name'), $admin,
    ])->id;
    $this->info('School ready: '.$id);
    if ($admin) return $this->call('platform:invite-admin', ['slug' => $slug]);
})->purpose('Create a school with its plan, default site and invited administrator (as platform_admin)');

Artisan::command('platform:invite-admin {slug}', function (string $slug) {
    $console = DB::connection('console');
    $tenant = $console->table('platform.tenant')->where('slug', $slug)->first(['id', 'name']);
    if (!$tenant) { $this->error('Unknown school.'); return 1; }
    [$token, $hash] = AccountLinks::token();
    try {
        $admin = $console->selectOne('SELECT email, name FROM platform.invite_admin(?, ?)', [$tenant->id, $hash]);
    } catch (QueryException $e) {
        $this->warn('No administrator awaiting activation in this school.');
        return 0;
    }
    if (AccountLinks::send('activation', $admin->email, $admin->name, $token, $tenant->name)) {
        $this->info('Invitation sent to '.$admin->email.' (valid 72 hours).');
        return 0;
    }
    // FR-IAM-01: sending failed; the link goes to the administrator another way. Never logged.
    $this->warn('Sending failed. Activation link, valid 72 hours, to hand over securely:');
    $this->line(AccountLinks::url('activation', $token));
    return 0;
})->purpose('Send the activation link to the administrator named when the school was created (as platform_admin)');

Artisan::command('platform:membership {slug} {email} {--status=active}', function (string $slug, string $email) {
    $owner = DB::connection('owner');
    $tenant = $owner->table('platform.tenant')->where('slug', $slug)->value('id');
    $user = $owner->table('users')->where('email', mb_strtolower($email))->value('id');
    if (!$tenant || !$user) { $this->error('Unknown school or account.'); return 1; }
    $owner->statement('INSERT INTO school.membership (tenant_id, user_id, status) VALUES (?, ?, ?)
        ON CONFLICT (tenant_id, user_id) DO UPDATE SET status = EXCLUDED.status', [$tenant, $user, $this->option('status')]);
    $this->info('Membership '.$this->option('status').'.');
})->purpose('Set an account\'s membership in a school (as the database owner)');
