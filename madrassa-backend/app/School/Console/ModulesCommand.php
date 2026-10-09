<?php
namespace App\School\Console;

use App\School\Modules\ModuleRegistry;
use Illuminate\Console\Command;

final class ModulesCommand extends Command
{
    protected $signature = 'school:modules {--profile= : Preview a deployment profile} {--modules= : Preview a custom comma-separated selection}';
    protected $description = 'Show school modules and dependencies; preview configuration without changing school data';
    public function handle(): int
    {
        $profiles = config('school_modules.profiles');
        $profile = $this->option('profile') ?? config('school_modules.profile');
        if (!isset($profiles[$profile])) { $this->error('Unknown profile: '.$profile); return self::FAILURE; }
        try {
            $selection = $this->option('modules') ?? ($this->option('profile') ? $profiles[$profile] : config('school_modules.enabled'));
            $registry = new ModuleRegistry(config('school_modules.definitions'), (string) $selection);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage()); return self::FAILURE;
        }
        $this->info('Profile: '.$profile.' | Selection: '.$selection);
        $rows = [];
        foreach ($registry->definitions() as $name => $definition) {
            $rows[] = [$name, $definition['label'], $registry->enabled($name) ? 'enabled' : 'disabled', implode(', ', $definition['requires'])];
        }
        $this->table(['Module', 'Feature', 'Status', 'Requires'], $rows);
        $this->line('Profiles: '.implode(', ', array_keys($profiles)));
        $this->line('Configure SCHOOL_PROFILE / SCHOOL_MODULES in this school’s environment file, then recreate its app container.');
        return self::SUCCESS;
    }
}
