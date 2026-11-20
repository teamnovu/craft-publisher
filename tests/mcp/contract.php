<?php
/**
 * Contract checks for the Publisher X MCP tools. No database or Craft application is
 * needed, only a Craft 5.12+ project's Composer autoloader.
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
    $prefix = 'teamnovu\\publisher\\';

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
use teamnovu\publisher\mcp\PublisherTools;

$failures = 0;

$check = function(bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . PHP_EOL;

    if (!$ok) {
        $failures++;
    }
};

// Tool definitions, as Craft's MCP server builds them
// -----------------------------------------------------------------------------

$reflection = new ReflectionClass(PublisherTools::class);
$check(str_starts_with((string)$reflection->getFileName(), dirname(__DIR__, 2) . '/src/'), 'loads the tools from this checkout');

$state = (new CapabilityRegistry([PublisherTools::class]))->definitions();
$tools = [];

foreach ($state->getTools() as $reference) {
    $definition = json_decode(json_encode($reference->tool), true);
    $tools[$definition['name']] = $definition;
}

$expected = ['publisher_x_schedules_list', 'publisher_x_entry_drafts', 'publisher_x_schedule_draft', 'publisher_x_schedule_cancel'];
$check(count($tools) === 4 && !array_diff($expected, array_keys($tools)), 'registers exactly the 4 tools: ' . implode(', ', array_keys($tools)));

foreach ($tools as $name => $tool) {
    $check((bool)preg_match('/^publisher_x_[a-z0-9_]+$/', $name) && strlen($name) <= 64, "$name: snake_case, plugin prefix, at most 64 characters");
    $check(!isset($tool['outputSchema']), "$name: declares no outputSchema");
    $check(($tool['inputSchema']['type'] ?? null) === 'object', "$name: input schema is an object");

    foreach ($tool['inputSchema']['properties'] ?? [] as $property => $schema) {
        $types = (array)($schema['type'] ?? []);

        if (in_array('array', $types, true)) {
            $check(!empty($schema['items']), "$name.$property: array declares its items");
        }
    }
}

foreach (['publisher_x_schedules_list', 'publisher_x_entry_drafts'] as $name) {
    $check(($tools[$name]['annotations']['readOnlyHint'] ?? null) === true, "$name: read-only (mcp:read scope)");
}

foreach (['publisher_x_schedule_draft', 'publisher_x_schedule_cancel'] as $name) {
    $tool = $tools[$name] ?? [];
    $check(($tool['annotations']['readOnlyHint'] ?? null) === false, "$name: not read-only (mcp:write scope)");
    $check(($tool['annotations']['destructiveHint'] ?? null) === true, "$name: destructive");
    $check(($tool['_meta']['anthropic/requiresUserInteraction'] ?? null) === true, "$name: asks the client to confirm every call");
}

$list = $tools['publisher_x_schedules_list']['inputSchema'] ?? [];
$check(empty($list['required']), 'publisher_x_schedules_list: every argument is optional');
$check(($list['properties']['type']['enum'] ?? null) === ['draft', 'post_date', 'expiry', null], 'publisher_x_schedules_list.type: draft, post_date, expiry');
$check(($list['properties']['limit']['maximum'] ?? null) === PublisherTools::MAX_SCHEDULES, 'publisher_x_schedules_list.limit: at most ' . PublisherTools::MAX_SCHEDULES);
$check(in_array('string', (array)($list['properties']['site']['type'] ?? []), true), 'publisher_x_schedules_list.site: accepts handles');

$drafts = $tools['publisher_x_entry_drafts']['inputSchema'] ?? [];
$check(($drafts['required'] ?? []) === ['entryId'], 'publisher_x_entry_drafts: requires entryId');

$schedule = $tools['publisher_x_schedule_draft']['inputSchema'] ?? [];
$check(($schedule['required'] ?? []) === ['publishAt'], 'publisher_x_schedule_draft: requires publishAt only (draftElementId or scheduleId is checked by the tool)');
$check(isset($schedule['properties']['draftElementId'], $schedule['properties']['scheduleId']), 'publisher_x_schedule_draft: takes draftElementId and scheduleId');
$check(str_contains((string)($schedule['properties']['site']['description'] ?? ''), 'draftElementId'), 'publisher_x_schedule_draft.site: has its own description');

$cancel = $tools['publisher_x_schedule_cancel']['inputSchema'] ?? [];
$check(($cancel['required'] ?? []) === ['scheduleId'], 'publisher_x_schedule_cancel: requires scheduleId');
$check(($cancel['properties']['deleteDraft']['type'] ?? null) === 'boolean' && ($cancel['properties']['deleteDraft']['default'] ?? null) === false, 'publisher_x_schedule_cancel.deleteDraft: boolean, false by default');

foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if (!$method->getAttributes(McpTool::class)) {
        continue;
    }

    $permissions = array_map(fn($attribute) => $attribute->newInstance()->permission, $method->getAttributes(RequiresPermission::class));
    $check(in_array('accessCp', $permissions, true), "{$method->getName()}(): requires accessCp");
    $check(!$method->getAttributes('craft\mcp\attributes\PublicMcp'), "{$method->getName()}(): not public");
}

echo PHP_EOL . ($failures === 0 ? 'All checks passed.' : "$failures check(s) failed.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
