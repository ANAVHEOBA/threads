<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MastodonController extends Controller
{
    public function registerApplication(Request $request)
    {
        $request->validate([
            'domain' => 'required|url',
            'client_name' => 'required|string',
            'redirect_uris' => 'required|string',
            'website' => 'nullable|url'
        ]);

        try {
            $response = Http::post("{$request->domain}/api/v1/apps", [
                'client_name' => $request->client_name,
                'redirect_uris' => $request->redirect_uris,
                'scopes' => 'read write push',
                'website' => $request->website
            ]);

            if ($response->successful()) {
                // Store the credentials securely
                $credentials = $response->json();
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Application registered successfully',
                    'data' => [
                        'client_id' => $credentials['client_id'],
                        'client_secret' => $credentials['client_secret']
                    ]
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to register application',
                'error' => $response->json()
            ], 400);

        } catch (\Exception $e) {
            Log::error('Mastodon Registration Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to register application',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAccessToken(Request $request)
    {
        $request->validate([
            'domain' => 'required|url',
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string'
        ]);

        try {
            $response = Http::post("{$request->domain}/oauth/token", [
                'grant_type' => 'password',
                'client_id' => $request->client_id,
                'client_secret' => $request->client_secret,
                'username' => $request->username,
                'password' => $request->password,
                'scope' => 'read write push'
            ]);

            if ($response->successful()) {
                $tokenData = $response->json();
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Access token obtained successfully',
                    'access_token' => $tokenData['access_token']
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get access token',
                'error' => $response->json()
            ], 400);

        } catch (\Exception $e) {
            Log::error('Mastodon Token Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get access token',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}