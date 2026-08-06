<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\ToggleSocialAccount;
use App\Http\Resources\Api\SocialAccountResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Toggle a social account active/inactive. When inactive, the account is skipped during scheduled publishing. Returns the updated account.')]
class ToggleSocialAccountTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'account_id' => ['required', 'string', 'uuid'],
        ]);

        $account = SocialAccount::where('workspace_id', $request->user()->current_workspace_id)
            ->find(data_get($validated, 'account_id'));

        if (! $account) {
            return Response::error('Social account not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'manageAccounts', $request->user()->currentWorkspace, 'Not authorized to manage social accounts.')) {
            return $denied;
        }

        $account = ToggleSocialAccount::execute($account);

        return Response::structured((new SocialAccountResource($account))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the social account to toggle.'),
        ];
    }
}
