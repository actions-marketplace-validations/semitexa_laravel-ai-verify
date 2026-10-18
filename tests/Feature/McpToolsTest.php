<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Tests\Feature;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Orchestra\Testbench\TestCase;
use Semitexa\LaravelAiVerify\AiVerifyServiceProvider;
use Semitexa\LaravelAiVerify\Mcp\Tools\AiGraph;
use Semitexa\LaravelAiVerify\Mcp\Tools\AiVerify;
use Semitexa\LaravelAiVerify\Mcp\Tools\AiVerifyReceipt;

final class McpToolsTest extends TestCase
{
    private string $dir;

    private string $originalBase;

    protected function getPackageProviders($app): array
    {
        return [AiVerifyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // An app that already lists a tool of its own in Boost's config.
        $app['config']->set('boost.mcp.tools.include', ['App\\Mcp\\Tools\\Mine']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/ai-verify-mcp-'.uniqid();
        mkdir($this->dir.'/app/Models', 0o777, true);
        mkdir($this->dir.'/tests/Unit', 0o777, true);
        file_put_contents($this->dir.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($this->dir.'/app/Models/Invoice.php', "<?php\n\nnamespace App\\Models;\n\nclass Invoice\n{\n}\n");
        file_put_contents($this->dir.'/app/Broken.php', "<?php\n\nclass Broken {\n");
        file_put_contents($this->dir.'/tests/Unit/InvoiceTest.php', "<?php\n\nuse App\\Models\\Invoice;\n");

        $this->originalBase = $this->app->basePath();
        $this->app->setBasePath($this->dir);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        exec('rm -rf '.escapeshellarg($this->dir));

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $this->assertFalse($response->isError(), (string) $response->content());

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_tools_join_boosts_include_list_after_the_apps_own(): void
    {
        $this->assertSame(
            ['App\\Mcp\\Tools\\Mine', AiVerify::class, AiGraph::class, AiVerifyReceipt::class],
            config('boost.mcp.tools.include'),
        );
    }

    public function test_tool_names_are_the_ones_the_guideline_talks_about(): void
    {
        $this->assertSame('ai-verify', (new AiVerify)->name());
        $this->assertSame('ai-graph', (new AiGraph)->name());
        $this->assertSame('ai-verify-receipt', (new AiVerifyReceipt)->name());
    }

    public function test_schemas_offer_the_options_agents_need(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $this->assertSame(['files', 'git_ref', 'scope', 'timeout'], array_keys((new AiVerify)->schema($schema)));
        $this->assertSame(['node', 'impact'], array_keys((new AiGraph)->schema($schema)));
        $this->assertSame(['id', 'unread'], array_keys((new AiVerifyReceipt)->schema($schema)));
    }

    public function test_a_failing_change_is_a_verdict_not_a_tool_error(): void
    {
        $report = $this->decode((new AiVerify)->handle(new Request(['files' => ['app/Broken.php'], 'scope' => 'minimal'])));

        $this->assertSame('semitexa.laravel-ai-verify/v1', $report['schema']);
        $this->assertSame('fail', $report['verdict']);
    }

    public function test_a_clean_change_passes(): void
    {
        $report = $this->decode((new AiVerify)->handle(new Request(['files' => ['app/Models/Invoice.php'], 'scope' => 'minimal'])));

        $this->assertSame('pass', $report['verdict']);
    }

    public function test_graph_answers_impact_with_the_covering_tests(): void
    {
        unlink($this->dir.'/app/Broken.php');

        $report = $this->decode((new AiGraph)->handle(new Request(['impact' => ['app/Models/Invoice.php']])));

        $this->assertSame(['tests/Unit/InvoiceTest.php' => ['app/Models/Invoice.php']], $report['tests']);
    }

    public function test_receipt_reports_the_latest_run(): void
    {
        (new AiVerify)->handle(new Request(['files' => ['app/Models/Invoice.php'], 'scope' => 'minimal']));

        $receipt = $this->decode((new AiVerifyReceipt)->handle(new Request));

        $this->assertTrue($receipt['found']);
        $this->assertSame('pass', $receipt['verdict']);
    }

    public function test_a_change_set_it_cannot_build_comes_back_as_a_failed_verdict_with_the_reason(): void
    {
        // Outside a git repository, with no files given, there is no change
        // set; the agent still gets the envelope, with the reason in `error`.
        $report = $this->decode((new AiVerify)->handle(new Request));

        $this->assertSame('fail', $report['verdict']);
        $this->assertStringContainsString('not a git repository', $report['error']);
    }
}
