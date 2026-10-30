<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

            return $this->success('Login successful.', [
                'user' => $this->accessProfile(Auth::user()),
            ]);
        }

        return $this->error('The provided credentials do not match our records.', [], 401);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success('Logout successful.');
    }

    public function user(Request $request): JsonResponse
    {
        return $this->success('User retrieved successfully.', [
            'user' => $this->accessProfile($request->user()),
        ]);
    }

    /** @return array<string, mixed> */
    private function accessProfile(User $user): array
    {
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $roles = $user->getRoleNames()->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $roles->first(),
            'roles' => $roles,
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
        ];
    }
}
