<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Support\TokenAbility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        return $this->tokenResponse($user, $request->input('device_name', 'api'), Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return $this->tokenResponse($user, $request->input('device_name', 'api'));
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'abilities' => $user->currentAccessToken()?->abilities,
            'created_at' => $user->created_at?->toIso8601String(),
        ]]);
    }

    private function tokenResponse(User $user, string $deviceName, int $status = Response::HTTP_OK): JsonResponse
    {
        $token = $user->createToken($deviceName, [TokenAbility::FULL]);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], $status);
    }
}
