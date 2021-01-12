<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Auth\LoginRequest;

class AgentLoginController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Login
     *
     * @param \App\Http\Requests\Auth\LoginRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credentails = [
            'username' => $request->username,
            'password' => $request->password,
        ];

        if (!Auth::attempt($credentails)) {
            return response()->json(
                ['error' => 'The provided credentials are incorrect.'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $user = $request->user();

        if (!$user->isActive() || !$user->isAgent()) {
            Auth::logout();

            return response()->json(
                ['error' => 'User cannot access. Contact system administrator.'],
                JsonResponse::HTTP_FORBIDDEN
            );
        }

        $user->token = $user->createToken("{$user->username}-access-token")->plainTextToken;

        return response()->json($user, JsonResponse::HTTP_OK);
    }
}
