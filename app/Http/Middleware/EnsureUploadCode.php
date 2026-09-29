<?php

namespace App\Http\Middleware;

use App\Models\AccessCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUploadCode
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accessCodeId = $request->session()->get('upload_access_code_id');
        $accessCode = is_string($accessCodeId) ? AccessCode::query()->find($accessCodeId) : null;

        if (! $accessCode instanceof AccessCode || ! $accessCode->isUsable()) {
            abort(403, 'Bitte geben Sie zuerst einen gültigen Zugangscode ein.');
        }

        $request->attributes->set('accessCode', $accessCode);

        return $next($request);
    }
}
