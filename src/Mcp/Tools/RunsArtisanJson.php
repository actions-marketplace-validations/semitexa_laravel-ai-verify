<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Mcp\Tools;

use Illuminate\Contracts\Console\Kernel;
use Laravel\Mcp\Response;

/**
 * Runs one of this package's commands with --json and hands the agent its
 * JSON as is, so a tool answers exactly what the command answers.
 *
 * Boost runs every tool call in a fresh `artisan boost:execute-tool` process,
 * so calling the command in-process sees the agent's latest edits; no state
 * survives from one call to the next.
 */
trait RunsArtisanJson
{
    /** @param array<string, mixed> $parameters */
    protected function artisanJson(string $command, array $parameters): Response
    {
        $kernel = app(Kernel::class);
        $exit = $kernel->call($command, $parameters + ['--json' => true]);
        $output = trim($kernel->output());

        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            // A failure before the command could build its envelope (a bad
            // option, a crash in boot) is the one case that is an error.
            return Response::error($output !== '' ? $output : "{$command} exited with code {$exit} and printed nothing.");
        }

        return Response::json($decoded);
    }
}
