# Best Practices

This guide provides recommendations for organizing templates, naming conventions, security, testing, and debugging Clarity templates.

## Template Organization

### Directory Structure

Organize templates by purpose and hierarchy:

```
views/
├── layouts/              # Reusable page layouts
│   ├── base.clarity.html
│   ├── main.clarity.html
│   └── admin.clarity.html
├── pages/                # Full page templates
│   ├── home.clarity.html
│   ├── about.clarity.html
│   └── contact.clarity.html
├── partials/             # Reusable components
│   ├── header.clarity.html
│   ├── footer.clarity.html
│   ├── nav.clarity.html
│   └── user-card.clarity.html
├── components/           # UI components
│   ├── buttons/
│   │   ├── primary.clarity.html
│   │   └── secondary.clarity.html
│   └── forms/
│       ├── input.clarity.html
│       └── select.clarity.html
└── emails/               # Email templates
    ├── layouts/
    │   └── email-base.clarity.html
    ├── welcome.clarity.html
    └── reset-password.clarity.html
```

### Feature-Based Organization

For larger applications, organize by feature:

```
views/
├── layouts/
│   └── main.clarity.html
├── products/
│   ├── index.clarity.html
│   ├── show.clarity.html
│   └── partials/
│       └── product-card.clarity.html
├── users/
│   ├── profile.clarity.html
│   ├── settings.clarity.html
│   └── partials/
│       └── avatar.clarity.html
└── admin/
    ├── layouts/
    │   └── admin.clarity.html
    └── users/
        ├── index.clarity.html
        └── show.clarity.html
```

**Use namespaces for separation:**

```php
$engine->addNamespace('products', __DIR__ . '/views/products');
$engine->addNamespace('admin', __DIR__ . '/views/admin');
```

### Layouts vs. Partials

**Layouts** (use `{% extends %}`):

- Define page structure (header, footer, sidebar)
- Establish visual hierarchy
- One layout per page

**Partials** (use `{% include %}`):

- Reusable components (navigation, cards, forms)
- Can be used multiple times on a page
- No page structure, just fragments

## Naming Conventions

### Files

✅ **Good:**

- Use kebab-case: `user-profile.clarity.html`, `product-card.clarity.html`
- Descriptive names: `email-welcome.clarity.html`, not `email1.clarity.html`
- Match purpose: `layouts/admin.clarity.html`, `partials/nav.clarity.html`

❌ **Avoid:**

- CamelCase file names: `UserProfile.clarity.html`
- Underscores: `user_profile.clarity.html` (kebab-case preferred)
- Generic names: `template1.clarity.html`, `page.clarity.html`

### Blocks

✅ **Good:**

- Descriptive: `{% block pageTitle %}`, `{% block mainContent %}`
- CamelCase: `{% block sidebarWidgets %}`, `{% block metaTags %}`
- Hierarchical: `{% block head %}` → `{% block headStyles %}`, `{% block headScripts %}`

❌ **Avoid:**

- Generic: `{% block content1 %}`, `{% block block2 %}`
- Too short: `{% block c %}`, `{% block s %}`

### Variables

✅ **Good:**

- CamelCase: `{{ userName }}`, `{{ productList }}`
- Descriptive: `{{ articlePublishedAt }}`, `{{ userProfileImage }}`

❌ **Avoid:**

- Abbreviated: `{{ usrNm }}`, `{{ pubAt }}`
- Numeric suffixes: `{{ user1 }}`, `{{ user2 }}`

### Filters & Functions

✅ **Good:**

- Lowercase with underscores: `format_date`, `currency`, `sanitize_html`
- Verb for functions: `asset()`, `url()`, `include()`
- Adjective/noun for filters: `upper`, `currency`, `excerpt`

❌ **Avoid:**

- CamelCase: `formatDate` (use `format_date`)
- Abbreviated: `curr`, `fmt`

## Security Best Practices

### Always Rely on Auto-Escaping

✅ **Correct:**

```twig
<p>{{ user.bio }}</p>
<h1>{{ pageTitle }}</h1>
```

Auto-escaping protects against XSS by default.

### Use raw Sparingly

❌ **Dangerous:**

```twig
{{ userInput |> raw }} {{ $_GET['name'] |> raw }}
```

✅ **Safe:**

```twig
{# Only with trusted, sanitized content #} {{ sanitizedArticleBody |> raw }} {#
Or content you control #} {{ renderedWidget |> raw }} {{ data |> json |> raw }}
```

### Sanitize in PHP, Not Templates

❌ **Bad:**

```twig
{# Don't sanitize in templates #} {{ userBio |> strip_tags |> raw }}
```

✅ **Good:**

```php
// Sanitize in PHP
$sanitized = strip_tags($user->bio, '<p><br><strong><em>');

$engine->render('profile', ['userBio' => $sanitized]);
```

```twig
{# Template assumes clean data #} {{ userBio |> raw }}
```

### Validate File Paths

If allowing dynamic includes, validate paths:

❌ **Dangerous:**

```php
$template = $_GET['template'];  // User input
$engine->render($template, $data);  // DANGER: Path traversal
```

✅ **Safe:**

```php
$allowedTemplates = ['home', 'about', 'contact'];
$template = $_GET['template'] ?? 'home';

if (!in_array($template, $allowedTemplates, true)) {
    $template = 'home';
}

$engine->render($template, $data);
```

### Never Trust User Data

```php
// ❌ Bad: Passing unfiltered user input
$engine->render('search', [
    'query' => $_GET['q'],  // Unfiltered
]);

// ✅ Good: Validate/sanitize first
$engine->render('search', [
    'query' => htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8'),
]);
```

But remember: **Clarity auto-escapes by default**, so even unsanitized input is safe in most cases. Just be cautious with `|> raw`.

## Performance Best Practices

### Keep Templates Simple

❌ **Bad: Complex logic in templates**

```twig
{% set filteredUsers = users |> filter(u => u.age >= 18 and u.active and u.role
== 'member') |> map(u => { name: u.firstName ~ ' ' ~ u.lastName, email: u.email
|> lower, joined: u.createdAt |> date('Y-m-d') }) %}
```

✅ **Good: Logic in PHP**

```php
$filteredUsers = array_map(function($u) {
    return [
        'name' => $u->firstName . ' ' . $u->lastName,
        'email' => strtolower($u->email),
        'joined' => date('Y-m-d', $u->createdAt),
    ];
}, array_filter($users, fn($u) => $u->age >= 18 && $u->active && $u->role === 'member'));

$engine->render('users', ['users' => $filteredUsers]);
```

```twig
{% for user in users %}
<li>{{ user.name }} ({{ user.email }})</li>
{% endfor %}
```

### Pre-Compute Data

❌ **Bad: Repeated computation**

```twig
{% for item, idx in items %}
{{ items.length - idx }} items remaining
{% endfor %}
```

✅ **Good: Compute once**

```twig
{% set total = items.length %}
{% for item in items %}
{{ total - loop.index }} items remaining
{% endfor %}
```

Or better, in PHP:

```php
$data['itemCount'] = count($items);
```

### Avoid Deep Nesting

❌ **Bad:**

```twig
{% if user %}
{% if user.isActive %}
{% if user.hasPermission %}
{% for item in user.items %}
{% if item.isPublished %} {# Deeply nested #} {% endif %}
{% endfor %}
{% endif %}
{% endif %}
{% endif %}
```

✅ **Good: Flatten in PHP**

```php
$publishedItems = $user && $user->isActive && $user->hasPermission
    ? array_filter($user->items, fn($i) => $i->isPublished)
    : [];

$engine->render('items', ['items' => $publishedItems]);
```

```twig
{% for item in items %}
{# Simple, flat loop #}
{% endfor %}
```

### Persistent Cache Directory

Development:

```php
$engine->setCachePath(__DIR__ . '/cache/clarity');
```

Production:

```php
$engine->setCachePath('/var/cache/clarity');  // Survives restarts
```

## Code Style

### Indentation

Use consistent indentation (2 or 4 spaces):

```twig
{% if condition %}
<div>
  {% for item in items %}
  <p>{{ item.name }}</p>
  {% endfor %}
</div>
{% endif %}
```

### Whitespace Around Delimiters

✅ **Preferred:**

```twig
{{ variable }} {% for item in items %}
```

❌ **Avoid:**

```twig
{{variable}} {%for item in items%}
```

### Line Length

Keep lines under 120 characters. Break long chains:

```twig
{# ✅ Good #} {{ productDescription |> trim |> truncate(100) |> nl2br |> raw }}
{# ❌ Too long #} {{ productDescription |> trim |> truncate(100) |> nl2br |> raw
}}
```

### Comments

Use comments to explain complex logic:

```twig
{# Calculate discounted price: 10% off for members #} {% set finalPrice =
user.isMember ? (price * 0.9) : price %} {# Loop through published articles only
#} {% for article in articles |> filter(a => a.isPublished) %} ... {% endfor %}
```

## Data Handling

### Pass Only Required Data

❌ **Bad: Passing entire objects**

```php
$engine->render('profile', [
    'user' => $user,  // Entire User object
    'app' => $app,    // Entire App object
]);
```

✅ **Good: Pass specific fields**

```php
$engine->render('profile', [
    'userName' => $user->getName(),
    'userEmail' => $user->getEmail(),
    'userAvatar' => $user->getAvatarUrl(),
]);
```

### Normalize Data Structure

Provide consistent structures:

```php
// ✅ Good: Consistent array structure
$products = array_map(function($p) {
    return [
        'id' => $p->id,
        'name' => $p->name,
        'price' => $p->price,
        'inStock' => $p->stock > 0,
    ];
}, $productList);
```

Templates can rely on this structure:

```twig
{% for product in products %}
<div>
  {{ product.name }} - {% if product.inStock %}In Stock{% else %}Out of Stock{%
  endif %}
</div>
{% endfor %}
```

### Handle Nulls Gracefully

Use `default` filter:

```twig
{{ user.nickname |> default(user.name) }} {{ customMessage |> default('No
message provided') }}
```

Or ternary:

```twig
{{ user.avatar ? user.avatar : '/images/default-avatar.png' }}
```

## Testing Templates

### Unit Testing (PHP)

Test rendering with various data:

```php
use PHPUnit\Framework\TestCase;
use Clarity\ClarityEngine;

class TemplateTest extends TestCase
{
    private ClarityEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new ClarityEngine();
        $this->engine->setViewPath(__DIR__ . '/views');
        $this->engine->setCachePath(__DIR__ . '/cache');
    }

    public function testUserProfile(): void
    {
        $output = $this->engine->render('user-profile', [
            'userName' => 'John Doe',
            'userEmail' => 'john@example.com',
        ]);

        $this->assertStringContainsString('John Doe', $output);
        $this->assertStringContainsString('john@example.com', $output);
    }

    public function testAutoEscaping(): void
    {
        $output = $this->engine->render('test', [
            'html' => '<script>alert("xss")</script>',
        ]);

        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>', $output);
    }
}
```

### Integration Testing

Test with real data:

```php
public function testProductListing(): void
{
    $products = Product::all()->toArray();

    $output = $this->engine->render('products/index', [
        'products' => $products,
    ]);

    foreach ($products as $product) {
        $this->assertStringContainsString($product['name'], $output);
    }
}
```

### Visual Regression Testing

For complex UIs, consider snapshot testing:

```php
public function testProductCard(): void
{
    $output = $this->engine->render('components/product-card', [
        'product' => ['name' => 'Widget', 'price' => 19.99],
    ]);

    $this->assertMatchesSnapshot($output);
}
```

## Debugging

### Dump Variables

Use the `dump()` function for debugging:

```twig
<pre>{{ dump(user) }}</pre>
<pre>{{ dump(products, settings) }}</pre>
```

### Display All Context

```twig
<pre>{{ context() |> json |> raw }}</pre>
```

### Check Variable Existence

```twig
{% if user %}
<p>User exists: {{ user.name }}</p>
{% else %}
<p>No user provided</p>
{% endif %}
```

### Inspect Filter Output

Chain `dump` in filter pipeline:

```twig
{# See intermediate result #} {{ items |> filter(i => i.active) |> dump |>
slice(0, 5) }}
```

### Enable Error Display (Development)

```php
if ($_ENV['APP_ENV'] === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
```

### Clear Cache When Stuck

```php
$engine->flushCache();
```

## Reusability Patterns

### Partial Templates

Extract reusable components:

**File: `partials/user-avatar.clarity.html`**

```twig
<div class="avatar">
  <img
    src="{{ user.avatar |> default('/images/default-avatar.png') }}"
    alt="{{ user.name }}"
  />
</div>
```

**Usage:**

```twig
{% include "partials/user-avatar" %}
```

### Parameterized Partials

Pass variables to includes using `include()` function:

**File: `components/button.clarity.html`**

```twig
<button
  class="btn btn-{{ type |> default('primary') }}"
  type="{{ buttonType |> default('button') }}"
>
  {{ label }}
</button>
```

**Usage:**

```twig
{{ include("components/button", { label: "Submit", type: "success", buttonType:
"submit" }) }}
{{ include("components/button", { label: "Cancel", type: "secondary" }) }}
```

### Layout Slots

Create flexible layouts with multiple slots:

**File: `layouts/two-column.clarity.html`**

```twig
<div class="two-column-layout">
  <aside class="sidebar">{% block sidebar %}{% endblock %}</aside>

  <main class="content">{% block content %}{% endblock %}</main>
</div>
```

**Usage:**

```twig
{% extends "layouts/two-column" %} {% block sidebar %}
<h3>Categories</h3>
<ul>
  ...
</ul>
{% endblock %} {% block content %}
<h1>Article Title</h1>
<p>Article content...</p>
{% endblock %}
```

## Version Control

### Git Ignore

Ignore cache files:

```gitignore
# .gitignore
cache/clarity/
*.php.cache
```

### Template Versioning

Include version/timestamp in asset URLs:

```php
$engine->addFunction('asset', function($path) {
    static $version = null;
    $version ??= filemtime(__DIR__ . '/public/assets');
    return "/assets/$path?v=$version";
});
```

```twig
<link rel="stylesheet" href="{{ asset('css/main.css') }}" />
{# Outputs: /assets/css/main.css?v=1234567890 #}
```

## Documentation

### Comment Complex Logic

```twig
{# Calculate total price with discount: - Base price from product - 10% discount
for members - Sales tax applied after discount #} {% set basePrice =
product.price %} {% set discount = user.isMember ? (basePrice * 0.1) : 0 %} {%
set subtotal = basePrice - discount %} {% set total = subtotal * 1.08 %}
```

### Document Custom Filters

```php
/**
 * Format currency with symbol and decimal places.
 *
 * Usage in templates:
 *   {{ price |> currency }}        → € 12.50
 *   {{ price |> currency('$') }}   → $ 12.50
 */
$engine->addFilter('currency', function($value, string $symbol = '€') {
    return $symbol . ' ' . number_format($value, 2);
});
```

### README for Template Directory

Create `views/README.md`:

```markdown
# Template Structure

## Layouts

- `layouts/main.clarity.html` - Default public layout
- `layouts/admin.clarity.html` - Admin panel layout

## Pages

- `pages/home.clarity.html` - Homepage
- `pages/about.clarity.html` - About page

## Components

- `components/button.clarity.html` - Reusable button component
  Usage: `{{ include("components/button", { label: "Click" }) }}`

## Filters

- `currency` - Format numbers as currency
- `excerpt` - Truncate text with ellipsis
```

## Common Pitfalls

### Don't Mix PHP and Templates

❌ **Bad:**

```twig
{% set users = <?php echo json_encode($users); ?> %}
```

✅ **Good:**

Pass all data via `render()`:

```php
$engine->render('page', ['users' => $users]);
```

### Don't Overuse Filters

❌ **Bad: Excessive chaining**

```twig
{{ text |> trim |> lower |> capitalize |> truncate(50) |> replace('old', 'new')
}}
```

✅ **Good: Pre-process in PHP**

```php
$processedText = str_replace('old', 'new',
    mb_substr(ucfirst(strtolower(trim($text))), 0, 50)
);
```

### Don't Abuse Global State

❌ **Bad:**

```php
global $currentUser;
$engine->render('page', ['user' => $currentUser]);
```

✅ **Good:**

```php
$engine->render('page', ['user' => $request->getUser()]);
```

## Checklist

**Before deploying:**

- [ ] All templates use auto-escaping (no unnecessary `raw`)
- [ ] Cache directory is persistent and writable
- [ ] No sensitive data passed to templates
- [ ] Complex logic moved to PHP
- [ ] Templates tested with edge cases (null, empty arrays, etc.)
- [ ] Error handling configured for production
- [ ] OPcache enabled on production server

## Next Steps

- **[Troubleshooting](06-troubleshooting.md)** — Common errors and solutions
- **[Examples](../examples/README.md)** — See best practices in action
- **[Advanced Topics](04-advanced-topics.md)** — Deep dives into features
