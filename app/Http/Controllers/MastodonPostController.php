<?php

namespace App\Http\Controllers;

use App\Models\MastodonUser;
use App\Models\MastodonPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * MastodonPostController
 * 
 * Handles all Mastodon post-related operations including:
 * - Text posts
 * - Single image uploads
 * - Multiple image uploads (carousel)
 * - Video uploads
 * - Post deletion
 */
class MastodonPostController extends Controller
{
    /**
     * Configuration constants
     */
    private const MAX_FILE_SIZE = 41943040; // 40MB in bytes
    private const MAX_IMAGE_WAIT = 10; // seconds
    private const MAX_VIDEO_WAIT = 60; // seconds
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Supported media types configuration
     */
    private $mediaTypes = [
        // Images
        'image/jpeg' => ['ext' => 'jpg', 'type' => 'image'],
        'image/jpg' => ['ext' => 'jpg', 'type' => 'image'],
        'image/png' => ['ext' => 'png', 'type' => 'image'],
        'image/gif' => ['ext' => 'gif', 'type' => 'image'],
        'image/webp' => ['ext' => 'webp', 'type' => 'image'],
        // Videos
        'video/mp4' => ['ext' => 'mp4', 'type' => 'video'],
        'video/quicktime' => ['ext' => 'mov', 'type' => 'video'],
        'video/webm' => ['ext' => 'webm', 'type' => 'video'],
        'video/x-ms-wmv' => ['ext' => 'wmv', 'type' => 'video'],
        'video/mpeg' => ['ext' => 'mpeg', 'type' => 'video'],
    ];

    /**
     * Create a new post with media support
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createPost(Request $request)
    {
        // Validate the incoming request
        $request->validate([
            'user_id' => 'required|exists:mastodon_users,id',
            'content' => 'required|string|max:500',
            'media_urls' => 'array|nullable|max:4',
            'media_urls.*' => 'url',
            'visibility' => 'string|in:public,unlisted,private,direct',
            'sensitive' => 'boolean',
            'spoiler_text' => 'string|nullable|max:100',
            'language' => 'string|nullable|size:2'
        ]);

        try {
            $user = MastodonUser::findOrFail($request->user_id);
            $mediaIds = [];

            // Process media if present
            if (!empty($request->media_urls)) {
                foreach ($request->media_urls as $index => $mediaUrl) {
                    try {
                        Log::info('Processing media', [
                            'url' => $mediaUrl,
                            'index' => $index
                        ]);

                        $mediaResponse = $this->processMediaUpload(
                            $mediaUrl,
                            $user->mastodon_access_token,
                            $user->instance_url
                        );

                        if ($mediaResponse) {
                            $mediaIds[] = $mediaResponse['id'];
                            Log::info('Media processed successfully', [
                                'media_id' => $mediaResponse['id'],
                                'type' => $mediaResponse['type'] ?? 'unknown'
                            ]);
                        }
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

            // Prepare post data
            $postData = array_filter([
                'status' => $request->content,
                'media_ids' => $mediaIds,
                'visibility' => $request->visibility ?? 'public',
                'sensitive' => $request->sensitive ?? false,
                'spoiler_text' => $request->spoiler_text,
                'language' => $request->language
            ]);

            // Create post on Mastodon
            $response = Http::withToken($user->mastodon_access_token)
                ->timeout(30)
                ->post("{$user->instance_url}/api/v1/statuses", $postData);

            if (!$response->successful()) {
                throw new \Exception('Failed to create post: ' . $response->body());
            }

            $postResponse = $response->json();

            // Save to database
            $post = MastodonPost::create([
                'mastodon_user_id' => $user->id,
                'post_id' => $postResponse['id'],
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
                'mastodon_response' => $postResponse
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
     * Process media upload with retries and validation
     *
     * @param string $url
     * @param string $token
     * @param string $instanceUrl
     * @return array|null
     * @throws \Exception
     */
    private function processMediaUpload($url, $token, $instanceUrl)
    {
        $attempts = 0;
        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                return $this->uploadMediaFromUrl($url, $token, $instanceUrl);
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                    throw $e;
                }
                sleep(1); // Wait before retry
            }
        }
        return null;
    }

    /**
     * Upload media from URL to Mastodon
     *
     * @param string $url
     * @param string $token
     * @param string $instanceUrl
     * @return array
     * @throws \Exception
     */
    private function uploadMediaFromUrl($url, $token, $instanceUrl)
    {
        try {
            // Download media with progress tracking
            $tempFile = $this->downloadMediaFile($url);
            
            // Get and validate media type
            $mimeType = mime_content_type($tempFile);
            if (!isset($this->mediaTypes[$mimeType])) {
                unlink($tempFile);
                throw new \Exception("Unsupported media type: {$mimeType}");
            }

            $mediaInfo = $this->mediaTypes[$mimeType];
            $extension = $mediaInfo['ext'];
            
            // Upload to Mastodon
            $response = Http::withToken($token)
                ->timeout(120)
                ->attach(
                    'file',
                    file_get_contents($tempFile),
                    "media.{$extension}",
                    ['Content-Type' => $mimeType]
                )
                ->post("{$instanceUrl}/api/v1/media");

            unlink($tempFile);

            if (!$response->successful()) {
                throw new \Exception("Upload failed: " . $response->body());
            }

            // Wait for processing
            $mediaId = $response->json()['id'];
            $maxWait = ($mediaInfo['type'] === 'video') ? self::MAX_VIDEO_WAIT : self::MAX_IMAGE_WAIT;
            
            return $this->waitForMediaProcessing($mediaId, $token, $instanceUrl, $maxWait);

        } catch (\Exception $e) {
            Log::error('Media Upload Error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Download media file with progress tracking
     *
     * @param string $url
     * @return string Path to temporary file
     * @throws \Exception
     */
    private function downloadMediaFile($url)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mastodon_');
        
        $ch = curl_init($url);
        $fp = fopen($tempFile, 'wb');
        
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HEADER => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_MAXFILESIZE => self::MAX_FILE_SIZE
        ]);
        
        $success = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        fclose($fp);
        curl_close($ch);
        
        if (!$success || $httpCode !== 200) {
            unlink($tempFile);
            throw new \Exception("Download failed: {$error}");
        }
        
        return $tempFile;
    }

    /**
     * Wait for media processing completion
     *
     * @param string $mediaId
     * @param string $token
     * @param string $instanceUrl
     * @param int $maxWait
     * @return array
     * @throws \Exception
     */
    private function waitForMediaProcessing($mediaId, $token, $instanceUrl, $maxWait)
    {
        $start = time();
        while (time() - $start < $maxWait) {
            $response = Http::withToken($token)
                ->get("{$instanceUrl}/api/v1/media/{$mediaId}");

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['url']) && $data['url']) {
                    return $data;
                }
                
                if (isset($data['error'])) {
                    throw new \Exception("Processing failed: {$data['error']}");
                }
            }
            
            sleep(1);
        }
        
        throw new \Exception("Media processing timeout after {$maxWait} seconds");
    }

    /**
     * Delete a post
     *
     * @param Request $request
     * @param string $postId
     * @return \Illuminate\Http\JsonResponse
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