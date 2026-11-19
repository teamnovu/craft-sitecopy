<?php
/**
 * Contract checks for the Site Copy X MCP tools and the service helpers. No database
 * or Craft application is needed, only a Craft 5.12+ project's Composer autoloader.
 *
 * Usage: php tests/mcp/contract.php /path/to/craft-project/vendor/autoload.php
 *
 * This checkout of the plugin is loaded instead of the version installed in the project.
 */

declare(strict_types=1);

$autoload = $argv[1] ?? null;

if (!$autoload || !is_file($autoload)) {
    fwrite(STDERR, "Usage: php tests/mcp/contract.php <craft-project>/vendor/autoload.php\n");
    exit(2);
}

require $autoload;

spl_autoload_register(function(string $class): void {
    $prefix = 'teamnovu\\sitecopy\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
}, true, true);

use craft\mcp\attributes\RequiresPermission;
use craft\mcp\CapabilityRegistry;
use Mcp\Capability\Attribute\McpTool;
use teamnovu\sitecopy\mcp\SiteCopyTools;
use teamnovu\sitecopy\services\SiteCopy as SiteCopyService;

$failures = 0;

$check = function(bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;

    if (!$ok) {
        $failures++;
    }
};

// Tool definitions, as Craft's MCP server builds them
// -----------------------------------------------------------------------------

$reflection = new ReflectionClass(SiteCopyTools::class);
$check(str_starts_with((string)$reflection->getFileName(), dirname(__DIR__, 2) . '/src/'), 'loads the tools from this checkout');

$state = (new CapabilityRegistry([SiteCopyTools::class]))->definitions();
$tools = [];

foreach ($state->getTools() as $reference) {
    $definition = json_decode(json_encode($reference->tool), true);
    $tools[$definition['name']] = $definition;
}

$expected = ['site_copy_x_targets', 'site_copy_x_copy', 'site_copy_x_job_status', 'site_copy_x_rules_list'];
$check(array_keys($tools) == $expected || (count($tools) === 4 && !array_diff($expected, array_keys($tools))), 'registers exactly the 4 tools: ' . implode(', ', array_keys($tools)));

foreach ($tools as $name => $tool) {
    $check((bool)preg_match('/^site_copy_x_[a-z0-9_]+$/', $name) && strlen($name) <= 64, "$name: snake_case, plugin prefix, at most 64 characters");
    $check(!isset($tool['outputSchema']), "$name: declares no outputSchema");
    $check(($tool['inputSchema']['type'] ?? null) === 'object', "$name: input schema is an object");

    foreach ($tool['inputSchema']['properties'] ?? [] as $property => $schema) {
        $types = (array)($schema['type'] ?? []);

        if (in_array('array', $types, true)) {
            $check(!empty($schema['items']), "$name.$property: array declares its items");
        }
    }
}

foreach (['site_copy_x_targets', 'site_copy_x_job_status', 'site_copy_x_rules_list'] as $name) {
    $check(($tools[$name]['annotations']['readOnlyHint'] ?? null) === true, "$name: read-only (mcp:read scope)");
}

$copy = $tools['site_copy_x_copy'] ?? [];
$check(($copy['annotations']['readOnlyHint'] ?? null) === false, 'site_copy_x_copy: not read-only (mcp:write scope)');
$check(($copy['annotations']['destructiveHint'] ?? null) === true, 'site_copy_x_copy: destructive');
$check(($copy['_meta']['anthropic/requiresUserInteraction'] ?? null) === true, 'site_copy_x_copy: asks the client to confirm every call');

$required = $copy['inputSchema']['required'] ?? [];
sort($required);
$check($required === ['elementIds', 'sourceSite', 'targetSites'], 'site_copy_x_copy: requires elementIds, sourceSite, targetSites (got ' . implode(', ', $required) . ')');

$elementIds = $copy['inputSchema']['properties']['elementIds'] ?? [];
$check(($elementIds['items']['type'] ?? null) === 'integer', 'site_copy_x_copy.elementIds: integers');
$check(($elementIds['maxItems'] ?? null) === SiteCopyTools::MAX_ELEMENTS, 'site_copy_x_copy.elementIds: at most ' . SiteCopyTools::MAX_ELEMENTS);
$check(in_array('string', (array)($copy['inputSchema']['properties']['targetSites']['items']['type'] ?? []), true), 'site_copy_x_copy.targetSites: accepts handles');
$check(($copy['inputSchema']['properties']['dryRun']['type'] ?? null) === 'boolean', 'site_copy_x_copy.dryRun: boolean');
$check(($copy['inputSchema']['properties']['attributes']['items']['enum'] ?? null) === ['fields', 'title', 'slug'], 'site_copy_x_copy.attributes: no Commerce variants');

foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if (!$method->getAttributes(McpTool::class)) {
        continue;
    }

    $permissions = array_map(fn($attribute) => $attribute->newInstance()->permission, $method->getAttributes(RequiresPermission::class));
    $check(in_array('accessCp', $permissions, true), "{$method->getName()}(): requires accessCp");
    $check(!$method->getAttributes('craft\mcp\attributes\PublicMcp'), "{$method->getName()}(): not public");
}

// Service helpers (no Craft application needed)
// -----------------------------------------------------------------------------

$service = (new ReflectionClass(SiteCopyService::class))->newInstanceWithoutConstructor();

$check($service->normalizeIds('12,34, 56,,abc,0,12') === [12, 34, 56], 'normalizeIds: comma-separated string from the bulk action');
$check($service->normalizeIds(['3', 4, '', null, [5], -1]) === [3, 4], 'normalizeIds: array with junk');
$check($service->normalizeIds(null) === [] && $service->normalizeIds('') === [], 'normalizeIds: empty input');
$check($service->normalizeFieldsToSync('') === [], 'normalizeFieldsToSync: "" (nothing checked) means no fields');
$check($service->normalizeFieldsToSync(['body', '', 'body', 'seo']) === ['body', 'seo'], 'normalizeFieldsToSync: drops blanks and duplicates');
$check($service->filterAttributesToCopy(['fields', 'title'], []) === ['title'], 'filterAttributesToCopy: empty field selection drops fields');
$check($service->filterAttributesToCopy(['fields'], null) === ['fields'], 'filterAttributesToCopy: no selection keeps fields');
$check($service->filterAttributesToCopy(['fields'], []) === [], 'filterAttributesToCopy: nothing left to copy');

echo PHP_EOL . ($failures === 0 ? 'All checks passed.' : "$failures check(s) failed.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
