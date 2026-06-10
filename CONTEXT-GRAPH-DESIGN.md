# Context Graph — Design Sketch

Status: design only, not yet implemented.

## Summary

A queryable knowledge graph layered over existing Work Copilot content.
Users author it through familiar UI (tables on pages, chips on entity
pages); under the hood every cell is a triple: **subject — predicate —
object**.

Per the core principle "prefer native WordPress constructs", the design
needs exactly **one custom table** (`edges`). Everything else maps onto
constructs WordPress already has:

| Graph concept | Storage |
|---|---|
| Entity (node) | existing `wp_posts` row — any Page, Heading, or ItemPost |
| Predicate (edge label) | term in a new flat taxonomy `wcp_predicate` |
| Edge (triple) | row in custom table `wp_wcp_edges` |
| View (saved table query) | block/shortcode attributes inside page content — no storage of its own |

## Entities

Every post, page, and heading is already an entity: it has an ID, a
title, a permalink, and an edit screen. The graph adds nothing to
create one. If something deserves to be a node ("Acme Corp" as a
fulfiller), it deserves at least a stub Page — which also slots it
into the navigation taxonomy for free. No new post type.

**Literals** (dates, numbers, plain strings) are the one exception:
they are values, not nodes. An edge may carry a literal object instead
of an entity object (see schema). Rule of thumb: if you'd ever want to
traverse *from* it or attach facts *to* it, make it a Page; otherwise
it's a literal.

## Predicates

A non-hierarchical custom taxonomy `wcp_predicate`, **not** a fixed
vocabulary. The user types a label; autocomplete offers existing terms
first so the vocabulary converges instead of fragmenting
("fulfils" vs "fulfiller of"). Creating a new label is one keystroke
away — the ontology is emergent, never prescribed.

Term meta per predicate:

- `inverse_label` (optional) — display-only, e.g. `fulfils` ⇄
  `fulfilled by`. One edge, two readings; the inverse is never stored
  as a second edge.
- `object_kind` (optional hint) — `entity` | `text` | `date` |
  `number`. Lets table columns render the right input. Absent = entity.

Using a taxonomy (rather than a custom table) gives the native term
management screen, term counts, and REST support for free.

## Edges — the one custom table

```sql
CREATE TABLE {$wpdb->prefix}wcp_edges (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_id    BIGINT UNSIGNED NOT NULL,  -- wp_posts.ID
  predicate_id  BIGINT UNSIGNED NOT NULL,  -- wp_terms.term_id
  object_id     BIGINT UNSIGNED NULL,      -- wp_posts.ID, when object is an entity
  object_value  TEXT NULL,                 -- literal, when it is not
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY subject   (subject_id, predicate_id),
  KEY object    (object_id, predicate_id),
  KEY predicate (predicate_id)
) {$charset_collate};
```

Invariants (enforced in the repository class, not the DB):

- exactly one of `object_id` / `object_value` is non-null;
- no duplicate `(subject_id, predicate_id, object_id)` triples;
- edges are deleted when either endpoint post is deleted
  (`before_delete_post` hook).

Why not postmeta? Triples need to be queried from *both* ends
("everything that fulfils X" and "everything X fulfils") and by label
across all content. Postmeta indexes none of that well and has no
edge identity for later metadata (provenance, confidence, AI-proposed
flags).

## Views — tables are queries, not data

A table on a page is a Gutenberg block (or shortcode) whose attributes
*are* the saved query:

```
[wcp_table subjects="catalogs" columns="fulfils,launched"]
```

- `subjects` — a scope: a term in the existing structural taxonomy
  (e.g. all Pages under *Catalogs*), so "structure doubles as
  semantics" does the typing for free. No separate `instance_of`
  machinery needed.
- `columns` — predicate slugs, one per column.

Render: one row per subject; cell `(row, col)` shows all objects of
`(subject, predicate)` as chips (multiple chips = multiple triples;
empty cell = no triple). Editing a cell writes/removes edges directly.
Because nothing about the table is stored, every view is live: an edge
added on an entity page, in another table, or proposed by AI and
accepted, appears everywhere instantly.

## Entity page panel

Every Page/Heading/ItemPost gets a "Connections" panel rendering both
directions:

- outbound: `fulfils → Northwind Catalog`
- inbound: `fulfilled by ← Acme Corp` (inverse label, or
  `← fulfils` if none set)

Each chip links to the other endpoint — this is the traversal UI.

## Querying

A small repository class (`WCP_Graph`) plus REST routes under
`wcp-graph/v1`:

- `GET /edges?subject=ID` / `?object=ID` — one hop, either direction
- `GET /edges?predicate=slug&subject_scope=term` — exactly the table
  query
- `POST /edges`, `DELETE /edges/:id` — used by the table/panel UI

Multi-hop questions ("people who fulfil catalogs inside Project X")
are self-joins on one indexed table — cheap at single-user scale.
These routes also become part of the context pack for Hermes
delegations: the agent can read the graph, and per the human-in-the-
loop principle, may *propose* edges but never write them — proposals
land for explicit accept/dismiss like all other AI output.

## Implementation order

1. Taxonomy + edges table + repository class.
2. Connections panel on entity pages (read, then edit).
3. Table block with cell editing.
4. REST routes + Hermes read access + AI edge proposals.
