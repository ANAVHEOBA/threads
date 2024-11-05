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
                'auth_url' => $authUrl,
                'instructions' => [
                    'step1' => 'Open the auth_url in your browser',
                    'step2' => 'Login to Threads if needed',
                    'step3' => 'Authorize the application',
                    'step4' => 'You will be redirected back automatically'
                ]
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
                'error_description' => $request->error_description,
                'retry_url' => url('/api/threads/auth')
            ], 400);
        }

        if (!$request->has('code')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No authorization code provided',
                'help' => 'Start the auth flow at /api/threads/auth'
            ], 400);
        }

        try {
            $response = Http::post('https://graph.threads.net/oauth/access_token', [
                'client_id' => Config::get('services.threads.client_id'),
                'client_secret' => Config::get('services.threads.client_secret'),
                'redirect_uri' => Config::get('services.threads.redirect_uri'),
                'code' => $request->code,
                'grant_type' => 'authorization_code'
            ]);

            Log::info('Token Request Response', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            if ($response->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to get access token',
                    'details' => $response->json(),
                    'retry_url' => url('/api/threads/auth')
                ], 400);
            }

            $tokenData = $response->json();

            // Store the token
            $threadsUser = ThreadsUser::updateOrCreate(
                ['threads_user_id' => $tokenData['user_id'] ?? Str::uuid()],
                [
                    'threads_access_token' => $tokenData['access_token'],
                    'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                    'scope' => Config::get('services.threads.scope'),
                    'last_auth_at' => now(),
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
                'error' => $e->getMessage(),
                'retry_url' => url('/api/threads/auth')
            ], 500);
        }
    }
}