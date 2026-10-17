<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * `php artisan ai:verify:receipt` as an MCP tool: does a "verified" still hold?
 *
 * Not marked read-only: checking a receipt records that someone read it, which
 * is what the unread list is built from.
 */
final class AiVerifyReceipt extends Tool
{
    use RunsArtisanJson;

    protected string $description = 'Check an ai:verify receipt: whether it is intact, whether it was a pass, and whether any verified file changed since. Use it before claiming earlier work is verified. With unread, lists runs whose outcome nobody has looked at yet.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Receipt id, e.g. rcpt-20261017-073944-d58314. Defaults to the latest.'),
            'unread' => $schema->boolean()
                ->description('List receipts nobody has checked yet instead.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $parameters = [];

        if (is_string($id = $request->get('id')) && $id !== '') {
            $parameters['id'] = $id;
        }
        if ($request->get('unread') === true) {
            $parameters['--unread'] = true;
        }

        return $this->artisanJson('ai:verify:receipt', $parameters);
    }
}
