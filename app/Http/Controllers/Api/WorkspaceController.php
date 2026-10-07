<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\Api\WorkspaceResource;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function show(Request $request): WorkspaceResource
    {
        return (new WorkspaceResource($request->user()->currentWorkspace))->for($request->user());
    }
}
