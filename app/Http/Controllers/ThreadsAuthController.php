<?php

namespace App\Http\Controllers;

use App\Models\ThreadsUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreadsAuthController extends Controller
{
    private $ngrokUrl = 'https://your-ngrok-url.ngrok-free.app'; // Update with your ngrok URL

    public function redirect()
    {
        try {
            $state = Str::random(40);
            session(['threads_state' => $state]);

            // Basic scope first - minimal permissions
            $queryParams = http_build_query([
                'client_id' => '904272161298399',
                'redirect_uri' => $this->ngrokUrl . '/api/callback',
                'response_type' => 'code',
                'scope' => 'public_profile', // Start with basic scope
                'state' => $state,
                'auth_type' => 'rerequest'
            ]);

            $authUrl = 'https://www.threads.net/oauth/authorize?' . $queryParams;

            Log::info('Generated Auth URL', [
                'url' => $authUrl,
                'scope' => 'public_profile'
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Authorization URL generated',
                'auth_url' => $authUrl,
                'auth_url_with_permissions' => $this->getAuthUrlWithPermissions($state), // Additional URL with full permissions
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
            'client_id' => '904272161298399',
            'redirect_uri' => $this->ngrokUrl . '/api/callback',
            'response_type' => 'code',
            'scope' => 'public_profile threads_basic threads_content_publish threads_read_write',
            'state' => $state,
            'auth_type' => 'rerequest'
        ]);

        return 'https://www.threads.net/oauth/authorize?' . $queryParams;
    }

    public function callback(Request $request)
    {
        Log::info('Callback Received', ['params' => $request->all()]);

        if ($request->has('error')) {
            // Handle specific error cases
            switch ($request->error) {
                case 'access_denied':
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Access was denied',
                        'suggestion' => 'Try using the basic authentication URL',
                        'retry_url' => $this->ngrokUrl . '/api/threads/auth'
                    ], 400);
                    
                default:
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Authorization failed',
                        'error' => $request->error,
                        'error_description' => $request->error_description,
                        'retry_url' => $this->ngrokUrl . '/api/threads/auth'
                    ], 400);
            }
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
                'client_id' => '904272161298399',
                'client_secret' => '149a0dff06ef43ffcbbecd1bbf118fda',
                'redirect_uri' => $this->ngrokUrl . '/api/callback',
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
                    'suggestion' => 'Try the alternative authentication URL',
                    'retry_url' => $this->ngrokUrl . '/api/threads/auth'
                ], 400);
            }

            $tokenData = $response->json();

            // Store the token with additional error handling
            try {
                $threadsUser = ThreadsUser::updateOrCreate(
                    ['threads_user_id' => $tokenData['user_id'] ?? Str::uuid()],
                    [
                        'threads_access_token' => $tokenData['access_token'],
                        'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                        'scope' => $tokenData['scope'] ?? 'public_profile',
                        'last_auth_at' => now(),
                    ]
                );

                return response()->json([
                    'status' => 'success',
                    'message' => 'Successfully connected to Threads',
                    'permissions' => $tokenData['scope'] ?? 'public_profile',
                    'user_data' => $threadsUser
                ]);

            } catch (\Exception $e) {
                Log::error('Token Storage Error', [
                    'error' => $e->getMessage(),
                    'token_data' => $tokenData
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to store token',
                    'error' => $e->getMessage()
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Callback Processing Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process callback',
                'error' => $e->getMessage(),
                'retry_url' => $this->ngrokUrl . '/api/threads/auth'
            ], 500);
        }
    }
}