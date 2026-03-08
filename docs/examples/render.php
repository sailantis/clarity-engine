<?php
/**
 * Clarity Examples Renderer
 * 
 * Run from command line:
 *   php render.php 01-hello
 *   php render.php --all
 */

require __DIR__ . '/../../vendor/autoload.php';

use Clarity\ClarityEngine;

// Initialize Clarity Engine
$engine = new ClarityEngine();
$engine->setViewPath(__DIR__);
$engine->setCachePath(__DIR__ . '/../../cache/examples');

// Custom filter for demonstration
$engine->addFilter('currency', function ($value, string $symbol = '€') {
    return $symbol . ' ' . number_format($value, 2);
});

// Get example name from command line
$example = $argv[1] ?? '01-hello';
$renderAll = $example === '--all';

// Example data sets
$exampleData = [
    '01-hello' => [
        'message' => 'Hello, Clarity Template Engine!',
        'userName' => 'Developer',
        'user' => [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'role' => 'Administrator'
        ],
        'unsafeHtml' => '<script>alert("This is safely escaped!")</script>',
        'colors' => ['Red', 'Green', 'Blue']
    ],

    '02-filters' => [
        'text' => 'hello world',
        'messyText' => '   spaces everywhere   ',
        'longText' => 'This is a very long text that will be truncated to demonstrate the truncate filter in action. It contains more than 50 characters.',
        'price' => 1234.5678,
        'negativeNumber' => -42,
        'timestamp' => time(),
        'tags' => ['PHP', 'Clarity', 'Templates', 'Examples'],
        'numbers' => [5, 2, 8, 1, 9, 3],
        'description' => '  some text with spaces and more content  ',
        'users' => [
            ['name' => 'Alice', 'active' => true],
            ['name' => 'Bob', 'active' => false],
            ['name' => 'Charlie', 'active' => true],
        ],
        'emptyValue' => '',
        'data' => ['name' => 'John', 'age' => 30]
    ],

    '03-conditionals' => [
        'user' => [
            'isLoggedIn' => true,
            'name' => 'John Doe',
            'isActive' => true,
            'role' => 'admin',
            'isBanned' => false,
            'hasNotifications' => true,
            'notificationCount' => 5,
            'avatar' => '/images/avatar.jpg',
            'nickname' => null,
            'hasParentalConsent' => false
        ],
        'stock' => 15,
        'score' => 85,
        'age' => 21,
        'discount' => 0.1,
        'price' => 99.99,
        'customMessage' => null,
        'products' => ['Product 1', 'Product 2', 'Product 3']
    ],

    '04-loops' => [
        'items' => ['Apple', 'Banana', 'Cherry', 'Date', 'Elderberry'],
        'users' => [
            ['name' => 'Alice Johnson'],
            ['name' => 'Bob Smith'],
            ['name' => 'Charlie Brown'],
            ['name' => 'Diana Prince']
        ],
        'tags' => ['php', 'clarity', 'templates', 'examples', 'documentation'],
        'categories' => [
            [
                'name' => 'Electronics',
                'products' => [
                    ['name' => 'Laptop', 'price' => 999.99],
                    ['name' => 'Mouse', 'price' => 29.99]
                ]
            ],
            [
                'name' => 'Books',
                'products' => [
                    ['name' => 'PHP Guide', 'price' => 39.99],
                    ['name' => 'Web Design', 'price' => 49.99]
                ]
            ]
        ],
        'products' => [
            ['name' => 'Widget A', 'price' => 19.99, 'active' => true, 'stock' => 100],
            ['name' => 'Widget B', 'price' => 29.99, 'active' => false, 'stock' => 0],
            ['name' => 'Widget C', 'price' => 39.99, 'active' => true, 'stock' => 5],
            ['name' => 'Widget D', 'price' => 49.99, 'active' => true, 'stock' => 50]
        ],
        'emptyArray' => []
    ],

    '05-page' => [
        'pageTitle' => 'Template Inheritance Example',
        'pageDescription' => 'Demonstrating layout inheritance with blocks and sections',
        'features' => [
            [
                'icon' => '🚀',
                'title' => 'Fast Compilation',
                'description' => 'Templates are compiled to PHP and cached for maximum performance.'
            ],
            [
                'icon' => '🔒',
                'title' => 'Secure Sandbox',
                'description' => 'No arbitrary PHP execution - templates are strictly sandboxed.'
            ],
            [
                'icon' => '🎨',
                'title' => 'Expressive Syntax',
                'description' => 'Clean, readable template syntax inspired by modern engines.'
            ],
            [
                'icon' => '📦',
                'title' => 'Template Inheritance',
                'description' => 'Reusable layouts with extends and blocks.'
            ]
        ],
        'stats' => [
            ['value' => '500+', 'label' => 'Projects'],
            ['value' => '50K+', 'label' => 'Templates'],
            ['value' => '99.9%', 'label' => 'Uptime']
        ],
        'quickLinks' => [
            ['title' => 'Getting Started', 'url' => '/docs/getting-started'],
            ['title' => 'API Reference', 'url' => '/docs/api'],
            ['title' => 'Examples', 'url' => '/examples'],
            ['title' => 'GitHub', 'url' => 'https://github.com/clarity/engine']
        ],
        'lastUpdated' => time()
    ],

    '06-complex' => [
        'articles' => [
            [
                'title' => 'Getting Started with Clarity Template Engine',
                'slug' => 'getting-started-clarity',
                'excerpt' => 'Learn how to install and configure Clarity, a fast and secure PHP template engine. This comprehensive guide covers everything from basic setup to advanced features.',
                'publishedAt' => strtotime('-5 days'),
                'readTime' => 8,
                'views' => 1250,
                'tags' => ['tutorial', 'beginner', 'setup'],
                'authorId' => 1,
                'published' => true
            ],
            [
                'title' => 'Advanced Template Inheritance Patterns',
                'slug' => 'advanced-inheritance',
                'excerpt' => 'Discover powerful patterns for building scalable template architectures using Clarity\'s inheritance system. Learn about multi-level layouts and block strategies.',
                'publishedAt' => strtotime('-3 days'),
                'readTime' => 12,
                'views' => 890,
                'tags' => ['advanced', 'patterns', 'architecture'],
                'authorId' => 2,
                'published' => true
            ],
            [
                'title' => 'Building Reusable Components',
                'slug' => 'reusable-components',
                'excerpt' => 'Create a library of reusable template components that can be shared across your application. Best practices for component design and organization.',
                'publishedAt' => strtotime('-1 day'),
                'readTime' => 10,
                'views' => 654,
                'tags' => ['components', 'best-practices', 'tips'],
                'authorId' => 1,
                'published' => true
            ],
            [
                'title' => 'Performance Optimization Guide',
                'slug' => 'performance-optimization',
                'excerpt' => 'Optimize your Clarity templates for maximum performance. Caching strategies, compilation tips, and benchmarking techniques.',
                'publishedAt' => strtotime('-7 days'),
                'readTime' => 15,
                'views' => 2100,
                'tags' => ['performance', 'optimization', 'caching'],
                'authorId' => 3,
                'published' => true
            ],
            [
                'title' => 'Security Best Practices',
                'slug' => 'security-best-practices',
                'excerpt' => 'Learn how to keep your templates secure. Understanding auto-escaping, the raw filter, and preventing XSS attacks.',
                'publishedAt' => strtotime('-10 days'),
                'readTime' => 7,
                'views' => 1567,
                'tags' => ['security', 'best-practices', 'xss'],
                'authorId' => 2,
                'published' => true
            ],
            [
                'title' => 'Draft: Upcoming Features',
                'slug' => 'upcoming-features',
                'excerpt' => 'Preview of features coming in the next major release.',
                'publishedAt' => null,
                'readTime' => 5,
                'views' => 0,
                'tags' => ['roadmap', 'future'],
                'authorId' => 1,
                'published' => false
            ]
        ],
        'authors' => [
            [
                'id' => 1,
                'name' => 'Sarah Johnson',
                'bio' => 'Lead developer and creator of Clarity Template Engine',
                'articlesCount' => 15
            ],
            [
                'id' => 2,
                'name' => 'Michael Chen',
                'bio' => 'Security specialist and documentation maintainer',
                'articlesCount' => 12
            ],
            [
                'id' => 3,
                'name' => 'Emma Williams',
                'bio' => 'Performance engineer focusing on optimization',
                'articlesCount' => 8
            ]
        ],
        'allTags' => ['tutorial', 'beginner', 'advanced', 'patterns', 'components', 'best-practices', 'performance', 'optimization', 'security', 'xss', 'caching', 'architecture', 'setup', 'tips', 'roadmap']
    ]
];

// Render function
function renderExample(ClarityEngine $engine, string $name, array $data): void
{
    echo "\n=== Rendering: {$name} ===\n\n";

    try {
        $output = $engine->render($name, $data);

        // Save output to file
        $outputFile = __DIR__ . "/output/{$name}.html";
        @mkdir(dirname($outputFile), 0755, true);
        file_put_contents($outputFile, $output);

        echo "✓ Successfully rendered to: output/{$name}.html\n";
        echo "  Preview in browser: file://" . realpath($outputFile) . "\n";

    } catch (\Exception $e) {
        echo "✗ Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . "\n";
        echo "  Line: " . $e->getLine() . "\n";
    }
}

// Main execution
if ($renderAll) {
    echo "Rendering all examples...\n";
    foreach ($exampleData as $name => $data) {
        renderExample($engine, $name, $data);
    }
    echo "\nDone! All examples rendered to output/ directory.\n";
} else {
    if (!isset($exampleData[$example])) {
        echo "Error: Example '{$example}' not found.\n\n";
        echo "Available examples:\n";
        foreach (array_keys($exampleData) as $name) {
            echo "  - {$name}\n";
        }
        echo "\nUsage: php render.php <example-name>\n";
        echo "       php render.php --all\n";
        exit(1);
    }

    renderExample($engine, $example, $exampleData[$example]);
}
