<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp;

use Laravel\Mcp\Server;
use Selli\Ticketing\Mcp\Tools\AddInternalNoteTool;
use Selli\Ticketing\Mcp\Tools\AssignTicketTool;
use Selli\Ticketing\Mcp\Tools\GetArticleTool;
use Selli\Ticketing\Mcp\Tools\GetTicketTool;
use Selli\Ticketing\Mcp\Tools\ListTicketsTool;
use Selli\Ticketing\Mcp\Tools\ReplyToTicketTool;
use Selli\Ticketing\Mcp\Tools\SaveArticleTool;
use Selli\Ticketing\Mcp\Tools\SearchKnowledgeTool;
use Selli\Ticketing\Mcp\Tools\TakeOverTicketTool;
use Selli\Ticketing\Mcp\Tools\TicketMetricsTool;
use Selli\Ticketing\Mcp\Tools\TransitionTicketTool;

/**
 * An MCP server over the help desk (needs laravel/mcp). Mount it behind your
 * authentication — every tool acts as the authenticated user and goes
 * through the package policies:
 *
 *     Mcp::web('/mcp/ticketing', TicketingServer::class)->middleware('auth:sanctum');
 *
 * Or register the tools in a server of your own: `TicketingServer::TOOLS`.
 */
class TicketingServer extends Server
{
    /**
     * @var list<class-string<Server\Tool>>
     */
    public const TOOLS = [
        ListTicketsTool::class,
        GetTicketTool::class,
        ReplyToTicketTool::class,
        AddInternalNoteTool::class,
        AssignTicketTool::class,
        TransitionTicketTool::class,
        TakeOverTicketTool::class,
        TicketMetricsTool::class,
        SearchKnowledgeTool::class,
        GetArticleTool::class,
        SaveArticleTool::class,
    ];

    protected string $name = 'Ticketing';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Help-desk tools: list and read support tickets, reply to customers, leave internal notes,
        assign, move tickets through their workflow, take tickets over from the AI assistant,
        read support KPIs, and search, read and draft help-center articles.
        Tickets are named by their reference (e.g. SUP-2026-00042). Public replies reach the
        customer; internal notes never do. Workflow transitions need "confirm": true.
        Articles you save stay drafts until a person publishes them.
    MARKDOWN;

    /**
     * @var array<int|string, Server\Tool|class-string<Server\Tool>|array<int, Server\Tool|class-string<Server\Tool>>>
     */
    protected array $tools = self::TOOLS;
}
