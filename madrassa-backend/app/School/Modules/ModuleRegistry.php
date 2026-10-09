<?php
namespace App\School\Modules;

use InvalidArgumentException;

/** Deployment features and user grants are deliberately independent. */
final class ModuleRegistry
{
    private array $enabled = [];
    public function __construct(private array $definitions, string $selection = '*')
    {
        $selected = trim($selection) === '*' ? array_keys($definitions) : array_filter(array_map('trim', explode(',', $selection)));
        $visit = function (string $name, array $stack = []) use (&$visit): void {
            if (!isset($this->definitions[$name])) throw new InvalidArgumentException('Unknown school module: '.$name);
            if (in_array($name, $stack, true)) throw new InvalidArgumentException('School module dependency cycle: '.$name);
            if (isset($this->enabled[$name])) return;
            foreach ($this->definitions[$name]['requires'] ?? [] as $dependency) $visit($dependency, [...$stack, $name]);
            $this->enabled[$name] = true;
        };
        foreach (['core', ...$selected] as $name) $visit($name);
    }
    public function enabled(string $module): bool { return isset($this->enabled[$module]); }
    public function enabledNames(): array { return array_keys($this->enabled); }
    public function definitions(): array { return $this->definitions; }
    public function routeFiles(): array
    {
        // Register a stable route topology for Laravel route caching. Middleware
        // checks the current deployment selection on every request.
        return array_merge([], ...array_values(array_map(fn($definition) => $definition['routes'] ?? [], $this->definitions)));
    }
    public function permissions(): array
    {
        return array_values(array_unique(array_merge([], ...array_values(array_map(fn($definition) => $definition['permissions'] ?? [], $this->definitions)))));
    }
    public function effectivePermissions(array $grants): array
    {
        $allowed = [];
        foreach ($this->enabledNames() as $name) $allowed = [...$allowed, ...($this->definitions[$name]['permissions'] ?? [])];
        return array_values(array_intersect($grants, $allowed));
    }
    public function resourceModule(string $resource): ?string
    {
        foreach ($this->definitions as $name => $definition) if (in_array($resource, $definition['resources'] ?? [], true)) return $name;
        return null;
    }
    public function resourceEnabled(string $resource): bool
    {
        $module = $this->resourceModule($resource);
        return $module !== null && $this->enabled($module);
    }
    public function apiActionEnabled(string $action): bool
    {
        foreach ($this->definitions as $name => $definition) {
            if (in_array($action, $definition['api_actions'] ?? [], true)) return $this->enabled($name);
        }
        return false;
    }
    public function page(string $page): ?array
    {
        foreach ($this->definitions as $name => $definition) {
            if (isset($definition['pages'][$page])) return ['owner' => $name, ...$definition['pages'][$page]];
        }
        return null;
    }
    public function canAccessPage(string $page, array $grants): bool
    {
        $definition = $this->page($page);
        if (!$definition || !$this->enabled($definition['owner'])) return false;
        return array_diff($definition['all'] ?? [], $this->effectivePermissions($grants)) === [];
    }
    public function pageResources(string $page): array
    {
        return array_values(array_filter($this->page($page)['resources'] ?? [], fn($resource) => $this->resourceEnabled($resource)));
    }
}
