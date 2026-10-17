<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** `php artisan ai:graph` as an MCP tool: the code graph and impact analysis. */
#[IsReadOnly]
final class AiGraph extends Tool
{
    use RunsArtisanJson;

    protected string $description = 'Ask the code graph of this Laravel app before editing, instead of grepping. Give a node (a class FQCN or basename, a file path, a route URI or name, a view name or a table) to get what it connects to: routes, controllers, form requests, policies, models, tables, views, events, listeners and the tests that cover them. Or give impact paths to get what a change to those files affects and which tests to run. With neither, returns a summary of the graph.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'node' => $schema->string()
                ->description('Class FQCN or basename, file path, route URI or name, view name, or table.'),
            'impact' => $schema->array()
                ->items($schema->string())
                ->description('Paths you plan to change or have changed; returns their blast radius and the tests that cover them.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $parameters = [];

        if (is_string($node = $request->get('node')) && $node !== '') {
            $parameters['node'] = $node;
        }
        $impact = array_values(array_filter((array) $request->get('impact', []), 'is_string'));
        if ($impact !== []) {
            $parameters['--impact'] = $impact;
        }

        return $this->artisanJson('ai:graph', $parameters);
    }
}
