<?php
declare(strict_types=1);

return [
    'articles' => [
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'max_size' => 5 * 1024 * 1024,
        'image' => [
            'max_width' => 1600,
            'max_height' => 1600,
            'webp_quality' => 82,
        ],
    ],
    'avatars' => [
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'max_size' => 5 * 1024 * 1024,
        'image' => [
            'max_width' => 1024,
            'max_height' => 1024,
            'webp_quality' => 86,
        ],
    ],
    'campaigns' => [
        'image_max_size' => 8 * 1024 * 1024,
        'video_max_size' => 120 * 1024 * 1024,
        'video_extensions' => ['mp4', 'webm'],
        'video_mime_types' => ['video/mp4', 'video/webm'],
    ],
    'bug_reports' => [
        'max_size' => 8 * 1024 * 1024,
    ],
];
