<?php

namespace App\Http\Controllers;

use App\Models\MastodonUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MastodonAuthController extends Controller
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $instanceUrl;
    private $scopes;

    public function __construct()
    {
        $this->clientId = config('services.mastodon.client_id');
        $this->clientSecret = config('services.mastodon.client_secret');
        $this->redirectUri = config('services.mastodon.redirect_uri');
        $this->instanceUrl = config('services.mastodon.instance_url');
        $this->scopes = 'read write push';
    }

    public function redirect()
    {
        try {
            $state = Str::random(40);
            session(['mastodon_state' => $state]);

            $queryParams = http_build_query([
                'client_id' => $this->clientId,
                'redirect_uri' => $this->redirectUri,
                'response_type' => 'code',
                'scope' => $this->scopes,
                'state' => $state
            ]);

            $authUrl = "{$this->instanceUrl}/oauth/authorize?{$queryParams}";

            return response()->json([
                'status' => 'success',
                'message' => 'Please open this URL in your browser to authorize',
                'auth_url' => $authUrl
            ]);

        } catch (\Exception $e) {
            Log::error('Mastodon Auth Error', [
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
        try {
            if ($request->error) {
                throw new \Exception($request->error_description ?? $request->error);
            }

            if (!$request->code) {
                throw new \Exception('No authorization code provided');
            }

            // Exchange code for token
            $tokenData = $this->getTokenFromCode($request->code);

            // Get user details
            $userData = $this->getUserDetails($tokenData['access_token']);

            // Store or update user
            $user = MastodonUser::updateOrCreate(
                ['mastodon_user_id' => $userData['id']],
                [
                    'mastodon_access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'username' => $userData['username'],
                    'display_name' => $userData['display_name'],
                    'avatar_url' => $userData['avatar'],
                    'bio' => $userData['note'],
                    'instance_url' => $this->instanceUrl,
                    'scope' => $tokenData['scope'],
                    'token_expires_at' => isset($tokenData['expires_in']) 
                        ? now()->addSeconds($tokenData['expires_in']) 
                        : null
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Successfully authenticated with Mastodon',
                'user' => $user
            ]);

        } catch (\Exception $e) {
            Log::error('Mastodon Callback Error', [
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

    public function refreshToken(MastodonUser $user)
    {
        try {
            if (!$user->refresh_token) {
                throw new \Exception('No refresh token available for user');
            }

            $response = Http::post("{$user->instance_url}/oauth/token", [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $user->refresh_token,
                'grant_type' => 'refresh_token',
                'scope' => $this->scopes
            ]);

            if (!$response->successful()) {
                throw new \Exception('Failed to refresh token: ' . $response->body());
            }

            $tokenData = $response->json();

            $user->update([
                'mastodon_access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? $user->refresh_token,
                'token_expires_at' => isset($tokenData['expires_in']) 
                    ? now()->addSeconds($tokenData['expires_in']) 
                    : null,
                'scope' => $tokenData['scope'] ?? $user->scope
            ]);

            return $user->mastodon_access_token;

        } catch (\Exception $e) {
            Log::error('Mastodon Token Refresh Error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getValidToken(MastodonUser $user)
    {
        try {
            if ($user->needsTokenRefresh()) {
                return $this->refreshToken($user);
            }
            return $user->mastodon_access_token;
        } catch (\Exception $e) {
            Log::error('Token Validation Error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function getTokenFromCode($code)
    {
        $response = Http::post("{$this->instanceUrl}/oauth/token", [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'scope' => $this->scopes
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to obtain access token: ' . $response->body());
        }

        return $response->json();
    }

    private function getUserDetails($accessToken)
    {
        $response = Http::withToken($accessToken)
            ->get("{$this->instanceUrl}/api/v1/accounts/verify_credentials");

        if (!$response->successful()) {
            throw new \Exception('Failed to get user details: ' . $response->body());
        }

        return $response->json();
    }
}