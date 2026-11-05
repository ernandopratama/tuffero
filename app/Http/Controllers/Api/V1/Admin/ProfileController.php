<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangePasswordRequest;
use App\Http\Requests\Admin\UpdateProfileRequest;
use App\Services\ProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ApiResponse;

    public function __construct(private ProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);

        return $this->success('Profile retrieved.', $this->profiles->show($request->user()));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profiles->update($request->user(), $request->safe()->only(['name', 'email']));

        return $this->success('Profile updated.', [...$this->profiles->show($user), 'user' => $user->accessProfile()]);
    }

    public function password(ChangePasswordRequest $request): JsonResponse
    {
        $this->profiles->changePassword($request->user(), $request->validated('password'), $request->session()->getId());

        return $this->success('Password changed.');
    }
}
