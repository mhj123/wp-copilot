# REST permission audit

Static audit of every `register_rest_route` call in the repo. No runtime
verification — this environment cannot run WordPress — so each finding below
cites the file and line that supports it, and every claim is checkable by
reading. Findings marked **VERIFY** need a running install to confirm.

Scope: 49 routes in `wp-copilot`, 12 in `wcp-delegation`, plus the dynamic
route tables in `wcp-wiretap` and `wcp-openbiografy`.

---

## The model as it stands

| Plugin | Gate | Routes |
|---|---|---|
| `wp-copilot` | `current_user_can('edit_posts')` | 47 |
| `wp-copilot` | `__return_true` | 1 (`/version`) |
| `wp-copilot` | `current_user_can('manage_options')` | 1 (`/taxonomy/sync-all`) |
| `wcp-delegation` | `is_enabled() && edit_posts` | 12 |
| `wcp-wiretap` | `manage_options` | all |
| `wcp-openbiografy` | `manage_options` | all |

`check_permission()` — `class-rest-api.php:371` — is `return
current_user_can('edit_posts');` and nothing else. It guards 47 of 49 core
routes, from reading a page to permanently deleting content to spending the
site owner's API credit.

**`edit_posts` is held by Contributor**, WordPress's lowest content-creating
role, and by every role above it. So the effective floor for almost the entire
API is Contributor.

The add-on plugins are fine. Wiretap and OpenBiografy gate everything at
`manage_options`, and delegation additionally requires its feature flag to be
on. The problem is core.

---

## F1 — One capability for every operation · **Critical**

`edit_posts` is a coarse, publish-oriented capability being used as an
all-purpose gate. It cannot distinguish reading from deleting, or acting on
your own content from acting on someone else's, and it has nothing to say
about spending money.

**This was a decision, not an oversight.** `claude.md` states the principle
plainly: *"Single-user first — No multi-user permissions in MVP."* On a
personal install with one administrator, a single `edit_posts` gate is a
reasonable simplification and the findings below are all unreachable.

What changes is distribution. A plugin on WordPress.org lands on sites with
Contributors, Authors and Editors, and reviewers test capability handling
directly. The MVP assumption is sound; it just does not survive publication,
and every finding below is a consequence of shipping that assumption to
multi-user sites rather than of anyone getting it wrong.

The fix is not to raise it to `manage_options` — that breaks multi-user use.
It is to gate each endpoint on what the endpoint actually does. See the
capability map at the end.

## F2 — 35 write endpoints perform no object-level authorisation · **Critical**

Across 49 routes there are only **five** per-object capability checks, at
lines 662, 2310, 2355, 2445 and 3164. Everything else takes an arbitrary ID
from the request and acts on it after the blanket `edit_posts` gate.

Endpoints that accept an object ID and never check it include
`/headings/{id}/update`, `/headings/{id}/delete`, `/headings/{id}/duplicate`,
`/pages/{id}/notes`, `/pages/{id}/content/accept`, `/items/{id}/ai`,
`/items/{id}/subtasks` (add, toggle, delete), and both
`/pages/{id}/dynamic-listings` writes.

The correct pattern is already used elsewhere in the same file — `update_item`
calls `current_user_can('edit_post', $item_id)` at 2355, `delete_item` calls
`current_user_can('delete_post', $item_id)` at 2445. The codebase knows how to
do this; it just isn't applied consistently.

## F3 — `delete_heading` force-deletes any heading, bypassing trash · **Critical**

`class-rest-api.php:2927`

```php
public function delete_heading( $request ) {
    $heading_id = (int) $request->get_param('heading_id');
    $post = get_post($heading_id);
    if ( ! $post || $post->post_type !== 'wcp_heading' ) {
        return new WP_Error('not_found', ...);
    }
    wp_delete_post($heading_id, true); // true = force delete, skip trash
    return rest_ensure_response(array('success' => true));
}
```

The only validation is that the post exists and is a heading. There is no
ownership check, and `true` skips the trash — **the deletion is
unrecoverable**. Any Contributor can permanently destroy any heading on the
site, one request at a time, with no undo and no audit trail.

Two fixes, both needed: add `current_user_can('delete_post', $heading_id)`,
and drop the force flag so deletions land in the trash.

## F4 — Site-wide options are Contributor-writable · **High**

`delete_prompt()` at `class-rest-api.php:1814` splices the site-wide
`wcp_saved_prompts` option by index and writes it back. Any Contributor can
delete any saved prompt belonging to anyone.

There is a second, quieter bug here: mutation **by array index** is racy. Two
concurrent deletes shift the indices under each other and the second removes
the wrong prompt. Address the prompts by a stable ID instead.

Anything that reads or writes a site option should be at `manage_options`, or
the prompts should become per-user data.

## F5 — Unmetered spend of the site owner's API keys · **High**

Every `/ai/*` route and `/embeddings/batch` spends the site owner's Anthropic
or OpenAI credit, gated only at `edit_posts`. There is no rate limit, no batch
cap, and no per-user quota.

`/embeddings/batch` is the sharpest edge: one authenticated Contributor can
trigger embedding generation across the entire corpus, repeatedly. On a large
site that is a real bill.

Given BYO-key is the product's headline feature, "a low-privileged user can
empty your API budget" is a bad first support ticket. This wants a dedicated
capability — `wcp_use_ai`, granted to Editor and above by default and
filterable — plus a hard cap on batch size.

## F6 — Corpus-wide reads ignore ownership · **High** · **VERIFY**

`semantic_search()` (1045) and `get_context_items()` (428) contain no
reference to `post_author`, `current_user_can`, or `get_current_user_id`.
Neither restricts results to content the caller may read, so semantic search
appears to return matches from across the whole corpus to any Contributor.

Marked VERIFY because the query arguments continue past the section I read and
may constrain `post_status`; confirm against a running install with two users
before treating the scope as settled. The absence of any author or capability
term in either function body is not in doubt.

## F7 — `/version` is public and discloses the PHP version · **Medium**

`class-rest-api.php:29` is the sole `__return_true` route. It returns the
plugin version, server time and `PHP_VERSION` to unauthenticated callers.

Version disclosure to anonymous users is a routine reviewer complaint and a
free gift to anyone fingerprinting the host for known PHP vulnerabilities. The
hardcoded `'1.2.1'` is also already stale against the plugin header's `1.2.2`.
Either gate it at `manage_options` or delete it — it exists for debugging.

---

## Proposed capability map

Replace the single `check_permission()` with intent-specific callbacks:

| Endpoint class | Permission callback | Plus, inside the callback |
|---|---|---|
| Public/no-op | delete the route | — |
| Read own workspace | `read` | filter results by what the user may read |
| Create content | `edit_posts` | — |
| Modify an object | `edit_posts` | `current_user_can('edit_post', $id)` |
| Delete an object | `edit_posts` | `current_user_can('delete_post', $id)`, trash not force |
| AI actions (spend) | `wcp_use_ai` (new) | rate limit, batch cap |
| Site options | `manage_options` | — |
| Corpus-wide ops | `manage_options` | — |

A helper such as `WCP_REST_Auth::require_object(int $id, string $cap)`
returning `true|WP_Error` would let each callback assert authorisation in one
line, which is what makes the pattern stick across 49 endpoints.

## Suggested order

1. **F3** — smallest diff, worst consequence. Two lines.
2. **F2** — mechanical once the helper exists; apply down the list.
3. **F5** — introduce `wcp_use_ai` and the batch cap.
4. **F4** — move prompts to `manage_options` or per-user, fix the index race.
5. **F7** — delete the route.
6. **F6** — confirm against a running install, then constrain.

## Verifying the fixes

None of this is provable without a runtime. The check worth running once there
is one: create a Contributor, log in as them, and confirm every route in the
table above returns 403 except the ones intended for that role. That single
pass would have caught all seven findings.
