#!/usr/bin/env python3
"""
Categorise an X bookmarks CSV into Raindrop.io collections using Claude.

Usage:
    python categorise_bookmarks.py bookmarks.csv output.csv
    python categorise_bookmarks.py bookmarks.csv output.csv --limit 20   # test run

Requirements:
    pip install anthropic
    export ANTHROPIC_API_KEY=sk-ant-...

The script adds a 'collection' column to the CSV. Rows that don't clearly
match a collection get an empty string and can be reviewed manually.
"""

import anthropic
import csv
import json
import sys
import time
import argparse

# ── Configuration ─────────────────────────────────────────────────────────────

COLLECTIONS = [
    "Analytics",
    "Business",
    "Business+",
    "Career",
    "Coding",
    "Content",
    "DataScience",
    "Ecommerce",
    "Fitness-nutrition",
    "GenAI",
    "Investing",
    "Leadership",
    "Marketing",
    "Parenting",
    "Personalmotivation",
    "Product",
    "SEO",
    "Tools",
    "Travel",
]

MODEL      = "claude-haiku-4-5-20251001"
BATCH_SIZE = 30   # tweets per API call — tune down if you hit token limits
MAX_CHARS  = 400  # chars per tweet sent to the model (long tweets get truncated)

# ── Prompt ────────────────────────────────────────────────────────────────────

SYSTEM_PROMPT = (
    "You are a content classifier. You will be given a numbered list of tweets.\n"
    "For each tweet assign it to the single best-matching collection from this list, "
    "or return an empty string if it doesn't clearly fit any:\n\n"
    + "\n".join(f"- {c}" for c in COLLECTIONS)
    + "\n\nRules:\n"
    "- Return ONLY a valid JSON array, one entry per tweet, in the same order.\n"
    "- Each entry must be a collection name from the list above, or \"\" (empty string).\n"
    "- Do not add explanation or markdown.\n"
    "- Example for 3 tweets: [\"GenAI\", \"\", \"Investing\"]"
)

# ── Helpers ───────────────────────────────────────────────────────────────────

def tweet_text(row: dict) -> str:
    # Try known column names in order of preference
    for col in ("full_text", "note_tweet_text", "text", ""):
        val = (row.get(col) or "").strip()
        if val:
            return val[:MAX_CHARS]
    return ""


def classify_batch(client: anthropic.Anthropic, tweets: list[str]) -> list[str]:
    numbered = "\n".join(f"{i + 1}. {t}" for i, t in enumerate(tweets))
    message = client.messages.create(
        model=MODEL,
        max_tokens=256,
        system=SYSTEM_PROMPT,
        messages=[{"role": "user", "content": f"Classify these {len(tweets)} tweets:\n\n{numbered}"}],
    )
    text = message.content[0].text.strip()

    # Strip markdown code fences if the model wraps the JSON
    if text.startswith("```"):
        lines = text.splitlines()
        text = "\n".join(lines[1:])
        text = text.rsplit("```", 1)[0].strip()

    result = json.loads(text)

    if len(result) != len(tweets):
        raise ValueError(f"Got {len(result)} results for {len(tweets)} tweets")

    # Ensure every value is either a valid collection or empty string
    cleaned = []
    for val in result:
        cleaned.append(val if val in COLLECTIONS else "")
    return cleaned


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Categorise X bookmarks CSV for Raindrop import.")
    parser.add_argument("input",          help="Input CSV path")
    parser.add_argument("output",         help="Output CSV path")
    parser.add_argument("--limit", type=int, default=0,
                        help="Process only the first N rows (useful for testing)")
    args = parser.parse_args()

    client = anthropic.Anthropic()  # reads ANTHROPIC_API_KEY from env

    # ── Read ──────────────────────────────────────────────────────────────────
    with open(args.input, newline="", encoding="utf-8") as f:
        reader     = csv.DictReader(f)
        fieldnames = reader.fieldnames or []
        rows       = list(reader)

    if not rows:
        print("Input CSV is empty.")
        sys.exit(1)

    if args.limit:
        rows = rows[: args.limit]
        print(f"Test mode: processing first {args.limit} rows.")

    total         = len(rows)
    total_batches = (total + BATCH_SIZE - 1) // BATCH_SIZE
    print(f"Processing {total} rows in {total_batches} batches of up to {BATCH_SIZE}...\n")

    # ── Classify ──────────────────────────────────────────────────────────────
    all_collections: list[str] = []

    for batch_idx in range(total_batches):
        start      = batch_idx * BATCH_SIZE
        end        = min(start + BATCH_SIZE, total)
        batch_rows = rows[start:end]
        tweets     = [tweet_text(r) for r in batch_rows]

        print(f"  Batch {batch_idx + 1}/{total_batches}  (rows {start + 1}–{end})", end="  ", flush=True)

        for attempt in range(3):
            try:
                result  = classify_batch(client, tweets)
                matched = sum(1 for c in result if c)
                print(f"→ {matched}/{len(result)} matched")
                all_collections.extend(result)
                break
            except Exception as exc:
                if attempt < 2:
                    print(f"retry [{exc}]", end="  ", flush=True)
                    time.sleep(2 ** attempt)
                else:
                    print(f"FAILED ({exc}) — filling with blanks")
                    all_collections.extend([""] * len(tweets))

    # ── Write ─────────────────────────────────────────────────────────────────
    out_fields = list(fieldnames)
    if "collection" not in out_fields:
        out_fields.append("collection")

    with open(args.output, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=out_fields, extrasaction="ignore")
        writer.writeheader()
        for row, collection in zip(rows, all_collections):
            row["collection"] = collection
            writer.writerow(row)

    # ── Summary ───────────────────────────────────────────────────────────────
    matched_total = sum(1 for c in all_collections if c)
    blank_total   = total - matched_total

    print(f"\n{'─' * 50}")
    print(f"Done.  {matched_total}/{total} rows assigned  ({blank_total} left blank)")
    print(f"Output: {args.output}")

    # Breakdown by collection
    from collections import Counter
    counts = Counter(c for c in all_collections if c)
    print("\nBreakdown:")
    for col, n in sorted(counts.items(), key=lambda x: -x[1]):
        print(f"  {col:<25} {n}")


if __name__ == "__main__":
    main()
