<?php

namespace App\Http\Controllers;

use App\Models\ThreadsUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreadsAuthController extends Controller
{
    public function redirect()
    {
        $state = Str::random(40);
        session(['threads_state' => $state]);

        $queryParams = http_build_query([
            'client_id' => config('services.threads.client_id'),
            'redirect_uri' => 'https://34bc-2c0f-f5c0-b08-13f6-c6e2-192f-39a3-ce33.ngrok-free.app/api/callback',
            'response_type' => 'code',
            'scope' => 'threads_basic threads_content_publish',
            'state' => $state
        ]);

        return response()->json([
            'auth_url' => 'https://www.threads.net/oauth/authorize?' . $queryParams
        ]);
    }

    public function callback(Request $request)
    {
        Log::info('Callback received', $request->all());

        // Check if there's an error parameter
        if ($request->has('error')) {
            return response()->json([
                'status' => 'error',
                'message' => 'OAuth error',
                'error' => $request->error,
                'error_description' => $request->error_description
            ], 400);
        }

        // Check for required code parameter
        if (!$request->has('code')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No authorization code provided'
            ], 400);
        }

        try {
            $response = Http::post('https://graph.threads.net/oauth/access_token', [
                'client_id' => '904272161298399',
                'client_secret' => '149a0dff06ef43ffcbbecd1bbf118fda',
                'redirect_uri' => 'https://34bc-2c0f-f5c0-b08-13f6-c6e2-192f-39a3-ce33.ngrok-free.app/api/callback',
                'code' => $request->code,
                'grant_type' => 'authorization_code'
            ]);

            Log::info('Threads API Response', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            if ($response->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to get access token',
                    'details' => $response->json(),
                    'status_code' => $response->status()
                ], 400);
            }

            $tokenData = $response->json();
            
            // Store the token in threads_users table
            $threadsUser = ThreadsUser::updateOrCreate(
                ['threads_user_id' => $tokenData['user_id'] ?? null],
                [
                    'threads_access_token' => $tokenData['access_token'],
                    'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Successfully connected to Threads',
                'user' => $threadsUser
            ]);

        } catch (\Exception $e) {
            Log::error('Threads callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process OAuth callback',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}