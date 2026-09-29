<?php

namespace App\Http\Controllers;

use App\Models\AccessCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccessCodeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'unlocked' => $this->currentCode($request) instanceof AccessCode,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ], [
            'code.required' => 'Bitte geben Sie den Zugangscode ein.',
        ]);

        $accessCode = AccessCode::query()
            ->where('code', AccessCode::normalize($data['code']))
            ->first();

        if (! $accessCode instanceof AccessCode || ! $accessCode->isUsable()) {
            throw ValidationException::withMessages([
                'code' => 'Der Zugangscode ist ungültig.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('upload_access_code_id', $accessCode->id);
        $accessCode->forceFill(['last_used_at' => now()])->save();

        return response()->json([
            'unlocked' => true,
            'csrf_token' => csrf_token(),
        ]);
    }

    private function currentCode(Request $request): ?AccessCode
    {
        $accessCodeId = $request->session()->get('upload_access_code_id');

        if (! is_string($accessCodeId)) {
            return null;
        }

        $accessCode = AccessCode::query()->find($accessCodeId);

        return $accessCode instanceof AccessCode && $accessCode->isUsable() ? $accessCode : null;
    }
}
