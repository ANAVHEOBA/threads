<?php

namespace App\Http\Controllers;

use App\Models\ThreadsUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class ThreadsAuthController extends Controller
{
    public function redirect()
    {
        try {
            $state = Str::random(40);
            session(['threads_state' => $state]);

            $queryParams = http_build_query([
                'client_id' => Config::get('services.threads.client_id'),
                'redirect_uri' => Config::get('services.threads.redirect_uri'),
                'response_type' => 'code',
                'scope' => Config::get('services.threads.scope'),
                'state' => $state
            ]);

            $authUrl = 'https://www.threads.net/oauth/authorize?' . $queryParams;

            Log::info('Generated Auth URL', [
                'url' => $authUrl,
                'scope' => Config::get('services.threads.scope')
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Please open this URL in your browser to authorize',
                'auth_url' => $authUrl
            ]);

        } catch (\Exception $e) {
            Log::error('Auth URL Generation Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate authorization URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function callback(Request $request)
    {
        Log::info('Callback Received', ['params' => $request->all()]);

        if ($request->has('error')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Authorization failed',
                'error' => $request->error,
                'error_description' => $request->error_description
            ], 400);
        }

        if (!$request->has('code')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No authorization code provided'
            ], 400);
        }

        try {
            $tokenResponse = Http::post('https://graph.threads.net/oauth/access_token', [
                'client_id' => Config::get('services.threads.client_id'),
                'client_secret' => Config::get('services.threads.client_secret'),
                'redirect_uri' => Config::get('services.threads.redirect_uri'),
                'code' => $request->code,
                'grant_type' => 'authorization_code'
            ]);

            if ($tokenResponse->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to get access token',
                    'details' => $tokenResponse->json()
                ], 400);
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'];

            // Fetch user details
            $userResponse = Http::get('https://graph.threads.net/v1/me', [
                'access_token' => $accessToken,
                'fields' => 'id,username,email,profile_pic_url,biography,followers_count,following_count'
            ]);

            $userData = $userResponse->successful() ? $userResponse->json() : [];

            // Store user data
            $threadsUser = ThreadsUser::updateOrCreate(
                ['threads_user_id' => $userData['id'] ?? $tokenData['user_id']],
                [
                    'threads_access_token' => $accessToken,
                    'threads_refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                    'username' => $userData['username'] ?? null,
                    'email' => $userData['email'] ?? null,
                    'profile_pic_url' => $userData['profile_pic_url'] ?? null,
                    'biography' => $userData['biography'] ?? null,
                    'followers_count' => $userData['followers_count'] ?? 0,
                    'following_count' => $userData['following_count'] ?? 0,
                    'scope' => Config::get('services.threads.scope'),
                    'last_auth_at' => now(),
                    'state' => $request->state
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Successfully connected to Threads',
                'user_data' => $threadsUser
            ]);

        } catch (\Exception $e) {
            Log::error('Callback Processing Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process callback',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUserDetails($threads_user_id)
    {
        try {
            $user = ThreadsUser::where('threads_user_id', $threads_user_id)->firstOrFail();

            // Refresh user details from API
            $response = Http::get('https://graph.threads.net/v1/me', [
                'access_token' => $user->threads_access_token,
                'fields' => 'id,username,email,profile_pic_url,biography,followers_count,following_count'
            ]);

            if ($response->successful()) {
                $userData = $response->json();
                
                $user->update([
                    'username' => $userData['username'] ?? $user->username,
                    'email' => $userData['email'] ?? $user->email,
                    'profile_pic_url' => $userData['profile_pic_url'] ?? $user->profile_pic_url,
                    'biography' => $userData['biography'] ?? $user->biography,
                    'followers_count' => $userData['followers_count'] ?? $user->followers_count,
                    'following_count' => $userData['following_count'] ?? $user->following_count,
                ]);
            }

            return response()->json([
                'status' => 'success',
                'user_data' => $user
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get user details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}