# Layout Inheritance

Template inheritance is one of Clarity's most powerful features. It allows you to define reusable page structures (layouts) and override specific sections (blocks) in child templates.

## Core Concepts

### Extends

A child template **extends** a parent layout using the `{% extends %}` directive:

```twig
{% extends "layouts/main" %}
```

### Blocks

**Blocks** are named sections in a layout that can be overridden by child templates:

```twig
{% block blockName %} Default content {% endblock %}
```

### How It Works

1. **Parent** defines the structure with named blocks
2. **Child** extends the parent and overrides specific blocks
3. **Compiler** merges them at compile time (zero runtime overhead)

## Basic Example

### Parent Layout

**File: `views/layouts/main.clarity.html`**

```twig
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{% block title %}My Website{% endblock %}</title>
    {% block styles %}
    <link rel="stylesheet" href="/css/main.css" />
    {% endblock %}
  </head>
  <body>
    <header>
      {% block header %}
      <h1>My Website</h1>
      <nav>
        <a href="/">Home</a>
        <a href="/about">About</a>
      </nav>
      {% endblock %}
    </header>

    <main>
      {% block content %}
      <p>Default content</p>
      {% endblock %}
    </main>

    <aside>{% block sidebar %}{% endblock %}</aside>

    <footer>
      {% block footer %}
      <p>&copy; 2026 My Website</p>
      {% endblock %}
    </footer>

    {% block scripts %}
    <script src="/js/main.js"></script>
    {% endblock %}
  </body>
</html>
```

### Child Template

**File: `views/pages/about.clarity.html`**

```twig
{% extends "layouts/main" %} {% block title %}About Us - My Website{% endblock
%} {% block content %}
<h2>About Our Company</h2>
<p>We build amazing products.</p>

<h3>Our Mission</h3>
<p>Making the web a better place.</p>
{% endblock %} {% block sidebar %}
<h3>Quick Links</h3>
<ul>
  <li><a href="/team">Our Team</a></li>
  <li><a href="/history">Our History</a></li>
</ul>
{% endblock %}
```

**Result:** The child's `content` and `sidebar` blocks replace the parent's defaults, while `header`, `footer`, and `scripts` use the parent's content.

## Block Behavior

### Default Content

Blocks with content in the parent serve as defaults:

```twig
{% block sidebar %}
<p>Default sidebar content</p>
{% endblock %}
```

If the child doesn't override this block, the default is used.

### Empty Blocks

Empty blocks serve as placeholders:

```twig
{% block extraScripts %}{% endblock %}
```

Child templates can optionally fill them.

### Multiple Blocks

You can have as many blocks as needed:

```twig
{% block meta %}{% endblock %} {% block title %}{% endblock %} {% block styles
%}{% endblock %} {% block content %}{% endblock %} {% block sidebar %}{%
endblock %} {% block footer %}{% endblock %} {% block scripts %}{% endblock %}
```

## Multi-Level Inheritance

Layouts can extend other layouts, creating a hierarchy.

### Base Layout

**File: `views/layouts/base.clarity.html`**

```twig
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <title>{% block title %}{% endblock %}</title>
    {% block head %}{% endblock %}
  </head>
  <body>
    {% block body %}{% endblock %}
  </body>
</html>
```

### Section Layout (extends Base)

**File: `views/layouts/admin.clarity.html`**

```twig
{% extends "layouts/base" %} {% block head %}
<link rel="stylesheet" href="/css/admin.css" />
{% block extraStyles %}{% endblock %} {% endblock %} {% block body %}
<div class="admin-layout">
  <aside class="admin-sidebar">
    {% block sidebar %}
    <nav>
      <a href="/admin">Dashboard</a>
      <a href="/admin/users">Users</a>
      <a href="/admin/settings">Settings</a>
    </nav>
    {% endblock %}
  </aside>

  <main class="admin-content">{% block content %}{% endblock %}</main>
</div>

{% block scripts %}
<script src="/js/admin.js"></script>
{% endblock %} {% endblock %}
```

### Page Template (extends Section Layout)

**File: `views/admin/users.clarity.html`**

```twig
{% extends "layouts/admin" %} {% block title %}User Management - Admin{%
endblock %} {% block content %}
<h1>Users</h1>
<table>
  {% for user in users %}
  <tr>
    <td>{{ user.name }}</td>
    <td>{{ user.email }}</td>
  </tr>
  {% endfor %}
</table>
{% endblock %} {% block scripts %}
<script src="/js/admin.js"></script>
<script src="/js/user-management.js"></script>
{% endblock %}
```

**Inheritance chain:** `users.clarity.html` → `admin.clarity.html` → `base.clarity.html`

## Common Patterns

### Page-Specific Styles

**Layout:**

```twig
<head>
  <link rel="stylesheet" href="/css/main.css" />
  {% block pageStyles %}{% endblock %}
</head>
```

**Page:**

```twig
{% block pageStyles %}
<link rel="stylesheet" href="/css/product-gallery.css" />
{% endblock %}
```

### Page-Specific Scripts

**Layout:**

```twig
    {% block scripts %}
        <script src="/js/main.js"></script>
    {% endblock %}
</body>
```

**Page:**

```twig
{% block scripts %}
<script src="/js/main.js"></script>
<script src="/js/maps.js"></script>
<script>
  initializeMap({{ coordinates |> json |> raw }});
</script>
{% endblock %}
```

### Conditional Sidebar

**Layout:**

```twig
<div class="container">
  <main class="{% block mainClass %}{% endblock %}">
    {% block content %}{% endblock %}
  </main>

  {% block sidebar %}{% endblock %}
</div>
```

**Page with sidebar:**

```twig
{% block mainClass %}with-sidebar{% endblock %} {% block sidebar %}
<aside>
  <h3>Related Content</h3>
  <!-- sidebar content -->
</aside>
{% endblock %}
```

**Page without sidebar:** (don't override `sidebar` block; it remains empty)

### Breadcrumbs Block

**Layout:**

```twig
<header>
  <nav>{% include "partials/nav" %}</nav>

  {% block breadcrumbs %}{% endblock %}
</header>
```

**Page:**

```twig
{% block breadcrumbs %}
<ol class="breadcrumb">
  <li><a href="/">Home</a></li>
  <li><a href="/products">Products</a></li>
  <li>{{ product.name }}</li>
</ol>
{% endblock %}
```

## Advanced Techniques

### Dynamic Layout Selection

**In PHP:**

```php
$layout = $user->isAdmin() ? 'layouts/admin' : 'layouts/main';

$engine->setLayout($layout);
$engine->render('dashboard', ['user' => $user]);
```

**Or disable layout for specific pages:**

```php
$engine->setLayout(null);
$engine->render('api/json-response', $data);
```

### Nested Block Definitions

Blocks can be defined within blocks for fine-grained control:

**Parent:**

```twig
{% block content %}
<article>
  {% block articleHeader %}
  <h1>{% block articleTitle %}{% endblock %}</h1>
  {% endblock %} {% block articleBody %}{% endblock %}
</article>
{% endblock %}
```

**Child:**

```twig
{% block articleTitle %}My Article{% endblock %} {% block articleBody %}
<p>Article content here.</p>
{% endblock %}
```

### Blocks with Dynamic Content

Blocks can contain variables and expressions:

```twig
{% block title %}{{ pageTitle ?? 'My Website' }}{% endblock %} {% block content
%}
<h1>{{ heading }}</h1>
{% if showIntro %}
<p>{{ intro }}</p>
{% endif %} {{ mainContent |> raw }} {% endblock %}
```

## Extends Rules and Limitations

### Extends Must Be First

`{% extends %}` must be the **first directive** in the template:

✅ **Correct:**

```twig
{% extends "layouts/main" %} {% block content %} ... {% endblock %}
```

❌ **Incorrect:**

```twig
<p>Some content</p>
{% extends "layouts/main" %} {# ERROR: extends must be first #}
```

### Content Outside Blocks Is Ignored

In a child template, only content **inside blocks** is rendered:

```twig
{% extends "layouts/main" %}

<p>This will be IGNORED</p>
{# Not inside a block #} {% block content %}
<p>This will be rendered</p>
{# Inside a block #} {% endblock %}
```

### Block Names Must Match

Block names must match exactly (case-sensitive):

```twig
{# Parent #} {% block Content %}...{% endblock %} {# Child #} {% block content
%}...{% endblock %} {# Won't override (different case) #}
```

### Single Extends Only

A template can extend only **one** parent:

❌ **Not allowed:**

```twig
{% extends "layouts/base" %} {% extends "layouts/admin" %} {# ERROR: multiple
extends #}
```

✅ **Instead:** Create a chain (admin extends base, page extends admin)

## Inheritance vs. Includes

### When to Use Extends

Use when child templates share a **common structure**:

- Pages with the same header/footer
- Admin vs. public layouts
- Different sections of a website

### When to Use Includes

Use for **reusable components** that appear multiple times:

- Navigation menus
- User avatars
- Form fields
- Cards/widgets

### Combining Both

**Layout with includes:**

```twig
<!DOCTYPE html>
<html>
  <head>
    <title>{% block title %}{% endblock %}</title>
  </head>
  <body>
    {% include "partials/header" %}

    <main>{% block content %}{% endblock %}</main>

    {% include "partials/footer" %}
  </body>
</html>
```

**Child template:**

```twig
{% extends "layouts/main" %} {% block content %} {% include "partials/user-card"
%}

<h2>Recent Posts</h2>
{% for post in posts %} {% include "partials/post-preview" %} {% endfor %} {%
endblock %}
```

## Real-World Example

### E-commerce Site Structure

**Base Layout: `layouts/base.clarity.html`**

```twig
<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8" />
    <title>{% block title %}{% endblock %} - MyShop</title>
    <link rel="stylesheet" href="/css/base.css" />
    {% block styles %}{% endblock %}
  </head>
  <body class="{% block bodyClass %}{% endblock %}">
    {% block body %}{% endblock %}

    <script src="/js/base.js"></script>
    {% block scripts %}{% endblock %}
  </body>
</html>
```

**Shop Layout: `layouts/shop.clarity.html`**

```twig
{% extends "layouts/base" %} {% block styles %}
<link rel="stylesheet" href="/css/shop.css" />
{% endblock %} {% block body %} {% include "partials/shop-header" %}

<div class="shop-container">
  <aside class="filters">
    {% block filters %} {% include "partials/product-filters" %} {% endblock %}
  </aside>

  <main class="products">{% block products %}{% endblock %}</main>
</div>

{% include "partials/footer" %} {% endblock %}
```

**Product Listing Page: `products/index.clarity.html`**

```twig
{% extends "layouts/shop" %} {% block title %}All Products{% endblock %} {%
block bodyClass %}products-page{% endblock %} {% block products %}
<h1>All Products</h1>

<div class="product-grid">
  {% for product in products %}
  <div class="product-card">
    <img src="{{ product.image }}" alt="{{ product.name }}" />
    <h3>{{ product.name }}</h3>
    <p class="price">{{ product.price |> number(2) }}</p>
    <a href="/products/{{ product.id }}" class="btn">View Details</a>
  </div>
  {% endfor %}
</div>
{% endblock %} {% block scripts %}
<script src="/js/product-filters.js"></script>
{% endblock %}
```

**Product Detail Page: `products/show.clarity.html`**

```twig
{% extends "layouts/base" %} {% block title %}{{ product.name }}{% endblock %}
{% block bodyClass %}product-detail{% endblock %} {% block styles %}
<link rel="stylesheet" href="/css/product-detail.css" />
{% endblock %} {% block body %} {% include "partials/shop-header" %}

<div class="product-detail">
  <div class="product-images">
    {% for image in product.images %}
    <img src="{{ image }}" alt="{{ product.name }}" />
    {% endfor %}
  </div>

  <div class="product-info">
    <h1>{{ product.name }}</h1>
    <p class="price">{{ product.price |> currency }}</p>
    <p>{{ product.description }}</p>

    <button class="btn-add-cart">Add to Cart</button>
  </div>
</div>

{% include "partials/footer" %} {% endblock %} {% block scripts %}
<script src="/js/product-gallery.js"></script>
{% endblock %}
```

## Best Practices

### 1. Define Clear Block Hierarchy

Organize blocks logically:

```twig
{% block head %} {% block meta %}{% endblock %} {% block title %}{% endblock %}
{% block styles %}{% endblock %} {% endblock %} {% block body %} {% block header
%}{% endblock %} {% block content %}{% endblock %} {% block footer %}{% endblock
%} {% block scripts %}{% endblock %} {% endblock %}
```

### 2. Use Descriptive Block Names

✅ Good: `{% block pageTitle %}`, `{% block mainContent %}`, `{% block sidebarWidgets %}`

❌ Bad: `{% block b1 %}`, `{% block content2 %}`, `{% block stuff %}`

### 3. Provide Sensible Defaults

Make blocks work out-of-the-box:

```twig
{% block header %}
<h1>{{ siteName }}</h1>
{% include "partials/nav" %} {% endblock %}
```

### 4. Keep Layouts Focused

Don't overcomplicate layouts with business logic:

```twig
{# ❌ Bad: Logic in layout #} {% if user.isPremium and user.notifications > 0 %}
... {% endif %} {# ✅ Good: Logic in PHP, simple variables in layout #} {% if
showNotificationBadge %}
<span class="badge">{{ notificationCount }}</span>
{% endif %}
```

### 5. Leverage Multi-Level Inheritance

Create a hierarchy for flexibility:

```
base.clarity.html           → Everything shares this
├── public.clarity.html     → Public pages
│   ├── home.clarity.html
│   └── about.clarity.html
└── admin.clarity.html      → Admin pages
    ├── dashboard.clarity.html
    └── users.clarity.html
```

## Troubleshooting

### Block Not Overriding

**Problem:** Child block doesn't replace parent block.

**Causes:**

1. Block names don't match exactly (case-sensitive)
2. Typo in block name
3. `{% extends %}` is not the first directive

### Content Not Appearing

**Problem:** Content in child template doesn't appear.

**Solution:** Ensure content is **inside a block**. Content outside blocks in child templates is ignored.

### Layout Not Applied

**Problem:** Template renders without layout.

**Causes:**

1. `setLayout(null)` was called
2. `{% extends %}` overrides `setLayout()`

**Solution:** Use either `setLayout()` in PHP or `{% extends %}` in template, not both.

## Next Steps

- **[Advanced Topics](04-advanced-topics.md)** — Namespaces, caching, error handling
- **[Best Practices](05-best-practices.md)** — Organization and patterns
- **[Examples](examples/README.md)** — See complete layout examples
