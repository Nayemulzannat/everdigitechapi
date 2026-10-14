<?php

namespace App\Http\Controllers\Client\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\UpdateProfileRequest;
use App\Http\Resources\ClientMeResource;
use App\Services\AvatarProcessor;

class ProfileController extends Controller
{
    /** PUT /api/client/profile {name, phone, avatar?, remove_avatar?} */
    public function update(UpdateProfileRequest $request, AvatarProcessor $avatars): ClientMeResource
    {
        $user = $request->user('client');
        $phone = $request->phone();

        $user->name = $request->validated('name');
        if ($phone !== $user->phone) {
            // A new number is a number nobody has proven yet.
            $user->phone = $phone;
            $user->phone_verified_at = null;
        }

        if ($request->hasFile('avatar')) {
            $old = $user->avatar_path;
            $user->avatar_path = $avatars->store($request->file('avatar'), $user->getKey());
            $avatars->delete($old);
        } elseif ($request->boolean('remove_avatar')) {
            $avatars->delete($user->avatar_path);
            $user->avatar_path = null;
        }

        $user->save();

        return new ClientMeResource($user);
    }
}
