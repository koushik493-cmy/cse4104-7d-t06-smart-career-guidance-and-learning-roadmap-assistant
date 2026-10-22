<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\UserProfileService;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserProfileController extends Controller
{
    protected UserProfileService $profileService;

    public function __construct(UserProfileService $profileService)
    {
        $this->profileService = $profileService;
    }

    public function index()
    {
        $userId = Auth::id() ?? 1;
        $profile = UserProfile::where('user_id', $userId)->first();
        return response()->json([
            'success' => true,
            'data' => $profile,
            'user' => Auth::user(),
        ]);
    }

    public function show($id)
    {
        $userId = ($id === 'me') ? Auth::id() : (int) $id;
        $user = User::with('profile')->find($userId);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $user->profile,
            'user' => $user,
        ]);
    }

    public function store(Request $request)
    {
        return $this->update($request, 'me');
    }

    public function update(Request $request, $id = null)
    {
        $user = Auth::user();
        if (!$user) {
            $userId = ($id && $id !== 'me') ? (int) $id : 1;
            $user = User::find($userId);
        }

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|min:2|max:255',
            'password' => 'sometimes|nullable|string|min:8',
            'phone' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:50',
            'university' => 'nullable|string|max:255',
            'degree' => 'nullable|string|max:255',
            'graduation_year' => 'nullable|integer',
            'bio' => 'nullable|string|max:2000',
            'headline' => 'nullable|string|max:255',
        ]);

        if (!empty($validated['name'])) {
            $user->name = $validated['name'];
        }

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        $profileFields = [
            'phone', 'date_of_birth', 'gender', 'university', 'degree', 'graduation_year', 'bio'
        ];
        $profileData = [];
        foreach ($profileFields as $field) {
            if ($request->has($field)) {
                $profileData[$field] = $request->input($field);
            }
        }

        if ($request->has('headline') && !$request->has('bio') && $request->filled('headline')) {
            $profileData['bio'] = $request->input('headline');
        }

        $profile = !empty($profileData)
            ? $this->profileService->createOrUpdateProfile($user->id, $profileData)
            : UserProfile::where('user_id', $user->id)->first();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(),
            'data' => $profile,
        ]);
    }
}
