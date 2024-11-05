<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            
            // Handle media uploads
            $mediaUrl = null;
            $mediaType = 'TEXT';

            if ($request->hasFile('image')) {
                $mediaUrl = $this->handleImageUpload($request->file('image'));
                $mediaType = 'IMAGE';
            } elseif ($request->hasFile('video')) {
                $mediaUrl = $this->handleVideoUpload($request->file('video'));
                $mediaType = 'VIDEO';
            }

            // Step 1: Create media container
            $response = Http::post("https://graph.threads.net/v1.0/{$userId}/threads", [
                'access_token' => $accessToken,
                'media_type' => $mediaType,
                'text' => $request->text,
                'image_url' => $mediaType === 'IMAGE' ? $mediaUrl : null,
                'video_url' => $mediaType === 'VIDEO' ? $mediaUrl : null,
            ]);

            if ($response->failed()) {
                // Clean up uploaded file if request fails
                if ($mediaUrl) {
                    Storage::delete($this->getStoragePath($mediaUrl));
                }
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to create media container',
                    'details' => $response->json()
                ], 400);
            }

            $containerId = $response->json()['id'];

            // Step 2: Publish the container
            sleep(2); // Wait for processing
            
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
                'thread_id' => $publishResponse->json()['id'],
                'media_url' => $mediaUrl
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process post',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    private function handleImageUpload($file)
    {
        $filename = $this->generateUniqueFilename($file);
        $path = $file->storeAs('public/threads/images', $filename);
        return $this->getPublicUrl($path);
    }

    private function handleVideoUpload($file)
    {
        $filename = $this->generateUniqueFilename($file);
        $path = $file->storeAs('public/threads/videos', $filename);
        return $this->getPublicUrl($path);
    }

    private function generateUniqueFilename($file)
    {
        return Str::uuid() . '.' . $file->getClientOriginalExtension();
    }

    private function getPublicUrl($path)
    {
        // Replace 'public' with 'storage' in the path
        $publicPath = str_replace('public/', '', $path);
        return url('storage/' . $publicPath);
    }

    private function getStoragePath($url)
    {
        // Convert public URL back to storage path
        return str_replace(url('storage/'), 'public/', $url);
    }
}