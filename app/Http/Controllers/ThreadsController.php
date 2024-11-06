<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ThreadsController extends Controller
{
    /**
     * Create a post with support for text, images, videos, and carousels
     */
    public function createPost(Request $request)
    {
        $request->validate([
            'text' => 'required_without_all:media_items',
            'media_items' => 'nullable|array|max:20',
            'media_items.*.type' => 'required_with:media_items|in:IMAGE,VIDEO',
            'media_items.*.url' => 'required_with:media_items|url',
            'link_attachment' => 'nullable|url',
        ]);

        try {
            $accessToken = session('threads_access_token');
            $userId = session('threads_user_id');

            // If there are no media items, create a simple text post
            if (empty($request->media_items)) {
                return $this->createSimplePost($userId, $accessToken, $request);
            }

            // If there's only one media item, create a single media post
            if (count($request->media_items) === 1) {
                return $this->createSingleMediaPost(
                    $userId, 
                    $accessToken, 
                    $request->text, 
                    $request->media_items[0]
                );
            }

            // For multiple media items, create a carousel
            return $this->createCarouselPost($userId, $accessToken, $request);

        } catch (\Exception $e) {
            Log::error('Threads Post Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to create post',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a text-only post
     */
    private function createSimplePost($userId, $accessToken, Request $request)
    {
        $response = Http::post("https://graph.threads.net/v1.0/{$userId}/threads", [
            'access_token' => $accessToken,
            'media_type' => 'TEXT',
            'text' => $request->text,
            'link_attachment' => $request->link_attachment
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to create text post: ' . $response->body());
        }

        $containerId = $response->json()['id'];

        return $this->publishPost($userId, $accessToken, $containerId);
    }

    /**
     * Create a single media post (image or video)
     */
    private function createSingleMediaPost($userId, $accessToken, $text, $mediaItem)
    {
        $response = Http::post("https://graph.threads.net/v1.0/{$userId}/threads", [
            'access_token' => $accessToken,
            'media_type' => $mediaItem['type'],
            'text' => $text,
            'image_url' => $mediaItem['type'] === 'IMAGE' ? $mediaItem['url'] : null,
            'video_url' => $mediaItem['type'] === 'VIDEO' ? $mediaItem['url'] : null,
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to create media post: ' . $response->body());
        }

        $containerId = $response->json()['id'];

        return $this->publishPost($userId, $accessToken, $containerId);
    }

    /**
     * Create a carousel post with multiple media items
     */
    private function createCarouselPost($userId, $accessToken, Request $request)
    {
        // Step 1: Create individual containers for each media item
        $mediaContainerIds = [];

        foreach ($request->media_items as $mediaItem) {
            $response = Http::post("https://graph.threads.net/v1.0/{$userId}/threads", [
                'access_token' => $accessToken,
                'media_type' => $mediaItem['type'],
                'image_url' => $mediaItem['type'] === 'IMAGE' ? $mediaItem['url'] : null,
                'video_url' => $mediaItem['type'] === 'VIDEO' ? $mediaItem['url'] : null,
                'is_carousel_item' => true
            ]);

            if ($response->failed()) {
                throw new \Exception('Failed to create carousel item: ' . $response->body());
            }

            $mediaContainerIds[] = $response->json()['id'];
            
            // Wait briefly between uploads
            usleep(500000); // 0.5 seconds
        }

        // Step 2: Create the carousel container
        $response = Http::post("https://graph.threads.net/v1.0/{$userId}/threads", [
            'access_token' => $accessToken,
            'media_type' => 'CAROUSEL',
            'text' => $request->text,
            'children' => implode(',', $mediaContainerIds)
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to create carousel container: ' . $response->body());
        }

        $carouselContainerId = $response->json()['id'];

        // Step 3: Publish the carousel
        return $this->publishPost($userId, $accessToken, $carouselContainerId);
    }

    /**
     * Publish a post container
     */
    private function publishPost($userId, $accessToken, $containerId)
    {
        // Wait for processing
        sleep(2);

        $response = Http::post("https://graph.threads.net/v1.0/{$userId}/threads_publish", [
            'access_token' => $accessToken,
            'creation_id' => $containerId,
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to publish post: ' . $response->body());
        }

        return response()->json([
            'success' => true,
            'thread_id' => $response->json()['id']
        ]);
    }
}