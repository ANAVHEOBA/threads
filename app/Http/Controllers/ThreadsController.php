<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ThreadsController extends Controller
{
    public function createPost(Request $request)
    {
        $request->validate([
            'text' => 'required_without_all:image_url,video_url',
            'image_url' => 'nullable|url',
            'video_url' => 'nullable|url',
        ]);

        $accessToken = session('threads_access_token');
        $userId = session('threads_user_id');

        // Step 1: Create media container
        $response = Http::post("https://graph.threads.net/v1.0/{$userId}/threads", [
            'access_token' => $accessToken,
            'media_type' => $this->determineMediaType($request),
            'text' => $request->text,
            'image_url' => $request->image_url,
            'video_url' => $request->video_url,
        ]);

        if ($response->failed()) {
            return response()->json(['error' => 'Failed to create media container'], 400);
        }

        $containerId = $response->json()['id'];

        // Step 2: Publish the container
        sleep(2); // Wait for processing
        
        $publishResponse = Http::post("https://graph.threads.net/v1.0/{$userId}/threads_publish", [
            'access_token' => $accessToken,
            'creation_id' => $containerId,
        ]);

        if ($publishResponse->failed()) {
            return response()->json(['error' => 'Failed to publish post'], 400);
        }

        return response()->json([
            'success' => true,
            'thread_id' => $publishResponse->json()['id']
        ]);
    }

    private function determineMediaType(Request $request)
    {
        if ($request->has('image_url')) {
            return 'IMAGE';
        }
        if ($request->has('video_url')) {
            return 'VIDEO';
        }
        return 'TEXT';
    }
}