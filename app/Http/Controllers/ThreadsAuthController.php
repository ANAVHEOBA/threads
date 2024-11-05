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

            // Basic scope first - minimal permissions
            $queryParams = http_build_query([
                'client_id' => Config::get('services.threads.client_id'),
                'redirect_uri' => Config::get('services.threads.redirect_uri'),
                'response_type' => 'code',
                'scope' => Config::get('services.threads.basic_scope'),
                'state' => $state,
                'auth_type' => 'rerequest'
            ]);

            $authUrl = 'https://www.threads.net/oauth/authorize?' . $queryParams;

            Log::info('Generated Auth URL', [
                'url' => $authUrl,
                'scope' => Config::get('services.threads.basic_scope')
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Authorization URL generated',
                'auth_url' => $authUrl,
                'auth_url_with_permissions' => $this->getAuthUrlWithPermissions($state),
                'instructions' => [
                    'basic_auth' => 'Use auth_url for basic authentication',
                    'full_auth' => 'Use auth_url_with_permissions for full access (if needed)',
                    'note' => 'If basic auth fails, try the URL with permissions'
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

    private function getAuthUrlWithPermissions($state)
    {
        $queryParams = http_build_query([
            'client_id' => Config::get('services.threads.client_id'),
            'redirect_uri' => Config::get('services.threads.redirect_uri'),
            'response_type' => 'code',
            'scope' => Config::get('services.threads.full_scope'),
            'state' => $state,
            'auth_type' => 'rerequest'
        ]);

        return 'https://www.threads.net/oauth/authorize?' . $queryParams;
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

            $threadsUser = ThreadsUser::updateOrCreate(
                ['threads_user_id' => $tokenData['user_id'] ?? Str::uuid()],
                [
                    'threads_access_token' => $tokenData['access_token'],
                    'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                    'scope' => $tokenData['scope'] ?? Config::get('services.threads.basic_scope'),
                    'last_auth_at' => now(),
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Successfully connected to Threads',
                'permissions' => $tokenData['scope'] ?? Config::get('services.threads.basic_scope'),
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