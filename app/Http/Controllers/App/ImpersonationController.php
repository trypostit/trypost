<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Admin\StopImpersonation;
use App\Support\Admin\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationController extends Controller
{
    /**
     * Stop viewing the app as another user. Lives outside the backoffice
     * routes: the signed-in user here is the impersonated one.
     */
    public function destroy(Request $request, StopImpersonation $stopImpersonation): RedirectResponse
    {
        abort_unless(Impersonation::isActive(), Response::HTTP_NOT_FOUND);

        $impersonated = $request->user();
        $admin = $stopImpersonation->execute($request, $impersonated);

        if ($admin?->isBackofficeAdmin() === true) {
            return redirect()->route('admin.users.show', $impersonated);
        }

        return redirect()->route('app.calendar');
    }
}
