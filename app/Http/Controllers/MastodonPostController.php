<?php

namespace App\Http\Controllers;

use App\Models\MastodonUser;
use App\Models\MastodonPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MastodonPostController extends Controller
{
    /**
     * Maximum file sizes in bytes
     */
    private const MAX_IMAGE_SIZE = 10485760; // 10MB
    private const MAX_VIDEO_SIZE = 41943040; // 40MB

    /**
     * Maximum processing wait times in seconds
     */
    private const IMAGE_PROCESSING_TIMEOUT = 30;
    private const VIDEO_PROCESSING_TIMEOUT = 60;

    /**
     * Create a new post with support for text, images, and videos
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createPost(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:mastodon_users,id',
            'content' => 'required|string|max:500',
            'media_urls' => 'array|nullable|max:4',
            'media_urls.*' => 'url',
            'media_descriptions' => 'array|nullable',
            'media_descriptions.*' => 'nullable|string|max:420',
            'visibility' => 'nullable|string|in:public,unlisted,private,direct',
            'sensitive' => 'nullable|boolean',
            'spoiler_text' => 'nullable|string|max:100',
            'language' => 'nullable|string|size:2'
        ]);

        try {
            $user = MastodonUser::findOrFail($request->user_id);
            $mediaIds = [];

            // Handle media uploads if present
            if (!empty($request->media_urls)) {
                foreach ($request->media_urls as $index => $mediaUrl) {
                    try {
                        Log::info('Processing media upload', ['url' => $mediaUrl]);

                        $mediaResponse = $this->uploadMedia(
                            $mediaUrl,
                            $user->mastodon_access_token,
                            $user->instance_url,
                            $request->media_descriptions[$index] ?? null
                        );

                        if ($mediaResponse && isset($mediaResponse['id'])) {
                            $mediaIds[] = $mediaResponse['id'];
                            Log::info('Media upload successful', [
                                'media_id' => $mediaResponse['id'],
                                'type' => $mediaResponse['type']
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Media upload failed', [
                            'url' => $mediaUrl,
                            'error' => $e->getMessage()
                        ]);
                        // Continue with other media if one fails
                        continue;
                    }
                }
            }

            // Create the post
            $postData = array_filter([
                'status' => $request->content,
                'media_ids' => $mediaIds,
                'visibility' => $request->visibility ?? 'public',
                'sensitive' => $request->sensitive ?? false,
                'spoiler_text' => $request->spoiler_text,
                'language' => $request->language
            ]);

            $response = Http::withToken($user->mastodon_access_token)
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
            Log::error('Post creation failed', [
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
     * Upload media to Mastodon
     *
     * @param string $url
     * @param string $token
     * @param string $instanceUrl
     * @param string|null $description
     * @return array|null
     * @throws \Exception
     */
    private function uploadMedia($url, $token, $instanceUrl, $description = null)
    {
        try {
            // Download media file
            $tempFile = $this->downloadMedia($url);
            $mimeType = mime_content_type($tempFile);
            $fileSize = filesize($tempFile);

            // Validate file type and size
            $this->validateMedia($mimeType, $fileSize);

            // Upload to Mastodon using v2 API
            $response = Http::withToken($token)
                ->timeout(60)
                ->attach(
                    'file',
                    file_get_contents($tempFile),
                    'media.' . $this->getExtensionFromMimeType($mimeType),
                    ['Content-Type' => $mimeType]
                )
                ->post("{$instanceUrl}/api/v2/media");

            // Clean up temp file
            unlink($tempFile);

            if (!$response->successful()) {
                throw new \Exception("Media upload failed: " . $response->body());
            }

            $mediaData = $response->json();
            $mediaId = $mediaData['id'];

            // Update description if provided
            if ($description) {
                $updateResponse = Http::withToken($token)
                    ->put("{$instanceUrl}/api/v1/media/{$mediaId}", [
                        'description' => $description
                    ]);

                if ($updateResponse->successful()) {
                    $mediaData = $updateResponse->json();
                }
            }

            // Wait for processing if needed
            if ($mediaData['url'] === null) {
                $timeout = $this->isVideo($mimeType) ? 
                    self::VIDEO_PROCESSING_TIMEOUT : 
                    self::IMAGE_PROCESSING_TIMEOUT;

                $mediaData = $this->waitForProcessing($mediaId, $token, $instanceUrl, $timeout);
            }

            return $mediaData;

        } catch (\Exception $e) {
            Log::error('Media upload error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Download media file from URL
     *
     * @param string $url
     * @return string Path to temporary file
     * @throws \Exception
     */
    private function downloadMedia($url)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'mastodon_');
        
        $ch = curl_init($url);
        $fp = fopen($tempFile, 'wb');
        
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HEADER => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60
        ]);
        
        $success = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        fclose($fp);
        curl_close($ch);
        
        if (!$success || $httpCode !== 200) {
            unlink($tempFile);
            throw new \Exception("Failed to download media: {$error}");
        }
        
        return $tempFile;
    }

    /**
     * Validate media file type and size
     *
     * @param string $mimeType
     * @param int $fileSize
     * @throws \Exception
     */
    private function validateMedia($mimeType, $fileSize)
    {
        $supportedTypes = [
            // Images
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
            // Videos
            'video/mp4', 'video/quicktime', 'video/webm', 'video/x-ms-wmv'
        ];

        if (!in_array($mimeType, $supportedTypes)) {
            throw new \Exception("Unsupported media type: {$mimeType}");
        }

        $maxSize = $this->isVideo($mimeType) ? self::MAX_VIDEO_SIZE : self::MAX_IMAGE_SIZE;
        
        if ($fileSize > $maxSize) {
            throw new \Exception("File size exceeds maximum allowed size");
        }
    }

    /**
     * Wait for media processing to complete
     *
     * @param string $mediaId
     * @param string $token
     * @param string $instanceUrl
     * @param int $timeout
     * @return array
     * @throws \Exception
     */
    private function waitForProcessing($mediaId, $token, $instanceUrl, $timeout)
    {
        $start = time();
        while (time() - $start < $timeout) {
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
        
        throw new \Exception("Media processing timeout after {$timeout} seconds");
    }

    /**
     * Check if media type is video
     *
     * @param string $mimeType
     * @return bool
     */
    private function isVideo($mimeType)
    {
        return strpos($mimeType, 'video/') === 0;
    }

    /**
     * Get file extension from MIME type
     *
     * @param string $mimeType
     * @return string
     */
    private function getExtensionFromMimeType($mimeType)
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-ms-wmv' => 'wmv'
        ];

        return $map[$mimeType] ?? 'bin';
    }
}