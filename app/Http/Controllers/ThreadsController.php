<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ThreadsController extends Controller
{
    public function createPost(Request $request)
    {
        $request->validate([
            'text' => 'required_without_all:image,video',
            'image' => 'nullable|image|max:10240', // 10MB max
            'video' => 'nullable|mimetypes:video/mp4,video/quicktime|max:102400', // 100MB max
        ]);

        try {
            $accessToken = session('threads_access_token');
            $userId = session('threads_user_id');
            
            // First, upload media to Threads if present
            $mediaType = 'TEXT';
            $mediaUrl = null;

            if ($request->hasFile('image')) {
                $mediaType = 'IMAGE';
                // Upload image to Threads
                $response = Http::attach(
                    'image', 
                    file_get_contents($request->file('image')->path()), 
                    $request->file('image')->getClientOriginalName()
                )->post("https://graph.threads.net/v1.0/{$userId}/media", [
                    'access_token' => $accessToken,
                    'media_type' => 'IMAGE',
                ]);

                if ($response->failed()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Failed to upload image',
                        'details' => $response->json()
                    ], 400);
                }

                $mediaUrl = $response->json()['id']; // Get the media ID from Threads
            }
            elseif ($request->hasFile('video')) {
                $mediaType = 'VIDEO';
                // Upload video to Threads
                $response = Http::attach(
                    'video', 
                    file_get_contents($request->file('video')->path()), 
                    $request->file('video')->getClientOriginalName()
                )->post("https://graph.threads.net/v1.0/{$userId}/media", [
                    'access_token' => $accessToken,
                    'media_type' => 'VIDEO',
                ]);

                if ($response->failed()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Failed to upload video',
                        'details' => $response->json()
                    ], 400);
                }

                $mediaUrl = $response->json()['id']; // Get the media ID from Threads
            }

            // Create the post with the uploaded media
            $postData = [
                'access_token' => $accessToken,
                'media_type' => $mediaType,
                'text' => $request->text,
            ];

            if ($mediaUrl) {
                $postData[$mediaType === 'IMAGE' ? 'image_id' : 'video_id'] = $mediaUrl;
            }

            $response = Http::post("https://graph.threads.net/v1.0/{$userId}/threads", $postData);

            if ($response->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to create post',
                    'details' => $response->json()
                ], 400);
            }

            $containerId = $response->json()['id'];

            // Publish the post
            $publishResponse = Http::post("https://graph.threads.net/v1.0/{$userId}/threads_publish", [
                'access_token' => $accessToken,
                'creation_id' => $containerId,
            ]);

            if ($publishResponse->failed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to publish post',
                    'details' => $publishResponse->json()
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Post published successfully',
                'thread_id' => $publishResponse->json()['id']
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process post',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}