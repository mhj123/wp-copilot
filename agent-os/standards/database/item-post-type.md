# ItemPost Uses Native WP post Type

All item types (task, info, learning, spec) use the native `post` post type.
Type is differentiated solely by the `item_type` taxonomy term.

- Never register a custom post type for a new item variant
- Use `item_type` taxonomy to add a new kind; add its status taxonomy if needed
- Native `post` gives REST API, revisions, search, and third-party compatibility for free

```php
// Correct: query by item_type term
$tasks = get_posts([
    'post_type' => 'post',
    'tax_query' => [['taxonomy' => 'item_type', 'field' => 'slug', 'terms' => 'task']],
]);

// Wrong: never do this
register_post_type('wcp_task', ...);
```

**Exceptions:** A future heavyweight document type may justify a CPT, but only with an explicit architectural decision — not by default.
