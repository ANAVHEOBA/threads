<?php

namespace App\Http\Controllers;

use App\Models\MastodonUser;
use App\Models\MastodonPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MastodonPostController extends Controller
{
    /**
     * Upload media from URL to Mastodon
     */
    private function uploadMediaFromUrl($url, $token, $instanceUrl)
    {
        try {
            // Download the media file
            $mediaResponse = Http::get($url);
            if (!$mediaResponse->successful()) {
                throw new \Exception("Failed to download media from URL: {$url}");
            }

            // Get file info
            $contentType = $mediaResponse->header('Content-Type');
            $extension = $this->getExtensionFromMimeType($contentType);
            $tempPath = sys_get_temp_dir() . '/' . uniqid() . '.' . $extension;

            // Save temporarily
            file_put_contents($tempPath, $mediaResponse->body());

            // Upload to Mastodon
            $response = Http::withToken($token)
                ->attach('file', file_get_contents($tempPath), 'media.' . $extension, ['Content-Type' => $contentType])
                ->post("{$instanceUrl}/api/v1/media");

            // Clean up temp file
            unlink($tempPath);

            if (!$response->successful()) {
                throw new \Exception("Failed to upload media to Mastodon: " . $response->body());
            }

            // Wait for media processing
            $mediaId = $response->json()['id'];
            $attempts = 0;
            $maxAttempts = 10;

            while ($attempts < $maxAttempts) {
                $mediaStatus = Http::withToken($token)
                    ->get("{$instanceUrl}/api/v1/media/{$mediaId}");

                if ($mediaStatus->successful()) {
                    $mediaData = $mediaStatus->json();
                    if (isset($mediaData['url']) && $mediaData['url']) {
                        return $mediaData;
                    }
                }

                $attempts++;
                sleep(1); // Wait 1 second before checking again
            }

            throw new \Exception("Media processing timeout");

        } catch (\Exception $e) {
            Log::error('Media Upload Error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMimeType($mimeType)
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
        ];

        return $map[$mimeType] ?? 'tmp';
    }

    /**
     * Create a new post with optional media
     */
    public function createPost(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:mastodon_users,id',
            'content' => 'required|string',
            'media_urls' => 'array|nullable|max:4', // Mastodon typically limits to 4 media attachments
            'media_urls.*' => 'url',
            'visibility' => 'string|in:public,unlisted,private,direct',
            'sensitive' => 'boolean',
            'spoiler_text' => 'string|nullable',
            'language' => 'string|nullable'
        ]);

        try {
            $user = MastodonUser::findOrFail($request->user_id);
            $mediaIds = [];

            // Handle media uploads if present
            if (!empty($request->media_urls)) {
                foreach ($request->media_urls as $mediaUrl) {
                    try {
                        $mediaResponse = $this->uploadMediaFromUrl(
                            $mediaUrl, 
                            $user->mastodon_access_token,
                            $user->instance_url
                        );
                        $mediaIds[] = $mediaResponse['id'];
                    } catch (\Exception $e) {
                        Log::error('Media Upload Failed', [
                            'url' => $mediaUrl,
                            'error' => $e->getMessage()
                        ]);
                        // Continue with other media if one fails
                        continue;
                    }
                }
            }

            // Create the post
            $response = Http::withToken($user->mastodon_access_token)
                ->post("{$user->instance_url}/api/v1/statuses", array_filter([
                    'status' => $request->content,
                    'media_ids' => $mediaIds,
                    'visibility' => $request->visibility ?? 'public',
                    'sensitive' => $request->sensitive ?? false,
                    'spoiler_text' => $request->spoiler_text,
                    'language' => $request->language
                ]));

            if (!$response->successful()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to create post',
                    'error' => $response->json()
                ], 400);
            }

            $postData = $response->json();

            // Save to database
            $post = MastodonPost::create([
                'mastodon_user_id' => $user->id,
                'post_id' => $postData['id'],
                'content' => $request->content,
                'visibility' => $request->visibility ?? 'public',
                'sensitive' => $request->sensitive ?? false,
                'spoiler_text' => $request->spoiler_text,
                'media_ids' => $mediaIds,
                'language' => $request->language,
                'status' => 'published'
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Post created successfully',
                'post' => $post,
                'mastodon_response' => $postData
            ]);

        } catch (\Exception $e) {
            Log::error('Mastodon Post Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create post',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload a single media file
     */
    public function uploadMedia(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:mastodon_users,id',
            'media_url' => 'required|url',
            'description' => 'nullable|string',
            'focus' => 'nullable|string' // Format: "x,y" between -1 and 1
        ]);

        try {
            $user = MastodonUser::findOrFail($request->user_id);

            $mediaResponse = $this->uploadMediaFromUrl(
                $request->media_url,
                $user->mastodon_access_token,
                $user->instance_url
            );

            // Update media description if provided
            if ($request->filled('description') || $request->filled('focus')) {
                $updateResponse = Http::withToken($user->mastodon_access_token)
                    ->put("{$user->instance_url}/api/v1/media/{$mediaResponse['id']}", array_filter([
                        'description' => $request->description,
                        'focus' => $request->focus
                    ]));

                if ($updateResponse->successful()) {
                    $mediaResponse = $updateResponse->json();
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Media uploaded successfully',
                'media' => $mediaResponse
            ]);

        } catch (\Exception $e) {
            Log::error('Mastodon Media Upload Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to upload media',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a post
     */
    public function deletePost(Request $request, $postId)
    {
        try {
            $post = MastodonPost::where('post_id', $postId)->firstOrFail();
            $user = $post->user;

            $response = Http::withToken($user->mastodon_access_token)
                ->delete("{$user->instance_url}/api/v1/statuses/{$postId}");

            if (!$response->successful()) {
                throw new \Exception("Failed to delete post on Mastodon");
            }

            $post->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Post deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Mastodon Delete Post Error', [
                'post_id' => $postId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete post',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}