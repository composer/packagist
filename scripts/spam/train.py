#!/usr/bin/env python3
"""Train the Packagist spam classifier.

This is the offline, one-off numeric fit. It never sees raw package text — only the token lists
produced by `bin/console packagist:spam:build-features` (which uses the PHP FeatureExtractor, the
single source of truth for tokenization). That guarantees training and the PHP inference share
identical features.

Two independent linear models are trained:

  * metadata - from name + description + tags   (metadata.jsonl, one row per package)
  * readme   - from the README text/links       (readme.jsonl, only packages that have a README)

The output is a single JSON file with a `metadata` and a `readme` section, each holding the bias,
the chosen decision threshold, and a sparse {token: weight} map. PHP inference computes
p = sigmoid(bias + sum of weights for the tokens present) and treats p < threshold as "safe".

The safe class hugely outnumbers spam, so by default we keep every spam row and randomly sample the
safe rows down to --max-per-class (streamed, so the whole file is never held in memory at once).
Capping the number of ROWS or the vocabulary is safe: PHP tokenizes the full package at inference
and simply ignores any token the model doesn't carry a weight for. (Do NOT truncate the per-example
token lists here — that would make training features differ from what PHP produces and reintroduce
train/serve skew. Shortening READMEs belongs in the PHP FeatureExtractor + a dataset rebuild.)

The weights file is PROD-ONLY and must never be committed (keeping it private stops spammers from
reverse-engineering the scoring). Deploy it to the path in the SPAM_MODEL_FILE env var.

Usage (defaults read metadata.jsonl/readme.jsonl from and write spam-model.json to the cwd):
    python scripts/spam/train.py
"""

import argparse
import json
import sys

import numpy as np
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import train_test_split


def load_jsonl(path, max_per_class, rng):
    """Stream a JSONL file, keeping every row of small classes and reservoir-sampling any class
    that exceeds max_per_class (None = keep all). Returns (list_of_token_lists, int labels, stats).

    Streaming + reservoir sampling means peak memory is bounded by the kept rows, not the file size.
    """
    kept = {}   # label -> list of token lists (the reservoir)
    seen = {}   # label -> number of rows seen for that label
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            label = int(row["label"])
            tokens = row["tokens"]
            i = seen.get(label, 0)
            seen[label] = i + 1
            bucket = kept.setdefault(label, [])
            if max_per_class is None or len(bucket) < max_per_class:
                bucket.append(tokens)
            else:
                # Algorithm R: replace a random slot with decreasing probability.
                j = int(rng.integers(0, i + 1))
                if j < max_per_class:
                    bucket[j] = tokens

    token_lists, labels = [], []
    for label, rows in kept.items():
        token_lists.extend(rows)
        labels.extend([label] * len(rows))
    stats = {label: (len(kept.get(label, [])), seen.get(label, 0)) for label in seen}
    return token_lists, np.array(labels, dtype=int), stats


def identity(tokens):
    """CountVectorizer analyzer: tokens are already extracted, pass them through verbatim."""
    return tokens


def threshold_table(y_true, spam_prob, grid):
    """For each candidate threshold, report the 'safe' decision (spam_prob < threshold).

    Returns a list of dicts. safe_precision = fraction of cleared items that are truly safe;
    the key error to avoid is spam leaking into the cleared set.
    """
    rows = []
    total = len(y_true)
    for t in grid:
        predicted_safe = spam_prob < t
        n_safe = int(predicted_safe.sum())
        if n_safe == 0:
            continue
        spam_leaked = int((predicted_safe & (y_true == 1)).sum())
        safe_precision = 1.0 - spam_leaked / n_safe
        rows.append(
            {
                "threshold": float(t),
                "coverage": n_safe / total,
                "safe_precision": safe_precision,
                "spam_leaked": spam_leaked,
                "cleared": n_safe,
            }
        )
    return rows


def pick_threshold(rows, target_precision):
    """Largest threshold (=most coverage) whose safe_precision still meets the target.

    Falls back to the most conservative usable threshold if nothing reaches the target.
    """
    eligible = [r for r in rows if r["safe_precision"] >= target_precision]
    if eligible:
        return max(eligible, key=lambda r: r["threshold"])["threshold"], True
    if rows:
        return min(rows, key=lambda r: r["threshold"])["threshold"], False
    return 0.0, False


def train_model(name, token_lists, labels, stats, args):
    """Train one linear model and return its serialisable section (bias/threshold/weights)."""
    print(f"\n=== {name} model ===")
    for label in sorted(stats):
        kept, total = stats[label]
        cls = "spam" if label == 1 else "safe"
        note = f" (sampled from {total})" if kept < total else ""
        print(f"  {cls}: {kept} examples{note}")

    if len(set(labels.tolist())) < 2:
        print(f"  WARNING: only one class present for '{name}'; emitting a disabled (never-veto) section.")
        return {"bias": 0.0, "threshold": 1.0, "weights": {}}

    vectorizer = CountVectorizer(analyzer=identity, binary=True, min_df=args.min_df, max_features=args.max_features)
    x = vectorizer.fit_transform(token_lists)
    vocab = vectorizer.get_feature_names_out()
    print(f"  vocabulary (min_df={args.min_df}, max_features={args.max_features}): {len(vocab)} tokens")

    x_train, x_val, y_train, y_val = train_test_split(
        x, labels, test_size=args.test_size, random_state=args.seed, stratify=labels
    )

    # l1_ratio=1.0 => pure L1 (sparse weights). saga is the solver that supports L1/elastic-net.
    # NB sklearn >= 1.8 replaced penalty='l1' with l1_ratio; do not set penalty here.
    clf = LogisticRegression(
        solver="saga",
        C=args.C,
        l1_ratio=args.l1_ratio,
        class_weight="balanced",
        max_iter=args.max_iter,
        random_state=args.seed,
        verbose=1,
    )
    print(f"  fitting on {x_train.shape[0]} rows x {x_train.shape[1]} features ...")
    clf.fit(x_train, y_train)

    spam_prob = clf.predict_proba(x_val)[:, 1]
    grid = np.concatenate([np.linspace(0.005, 0.1, 20), np.linspace(0.1, 0.9, 33)])
    rows = threshold_table(y_val, spam_prob, np.unique(np.round(grid, 4)))

    print("  threshold  coverage  safe_precision  spam_leaked/cleared")
    for r in rows:
        print(
            "    {threshold:>7.3f}  {coverage:>7.2%}  {safe_precision:>13.4%}  {spam_leaked}/{cleared}".format(**r)
        )

    if args.threshold is not None:
        threshold = args.threshold
        print(f"  using explicit --threshold {threshold}")
    else:
        threshold, met = pick_threshold(rows, args.target_precision)
        status = "meets" if met else "BELOW (best available)"
        print(f"  chosen threshold {threshold:.4f} ({status} target precision {args.target_precision:.3%})")

    # Ship the weights from the model we just fit + validated (no expensive refit on all rows).
    coefs = clf.coef_[0]
    weights = {}
    for token, weight in zip(vocab, coefs):
        if abs(weight) >= args.min_weight:
            weights[str(token)] = float(weight)
    print(f"  non-zero weights kept (>= {args.min_weight}): {len(weights)}")

    return {"bias": float(clf.intercept_[0]), "threshold": float(threshold), "weights": weights}


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--input-dir", default=".", help="dir holding metadata.jsonl and readme.jsonl")
    parser.add_argument("--output", default="spam-model.json", help="path to write the combined model JSON")
    parser.add_argument("--max-per-class", type=int, default=50000,
                        help="cap rows PER CLASS for the metadata model (keeps all of smaller classes, samples the rest); 0 = no cap")
    parser.add_argument("--max-readme-per-class", type=int, default=20000,
                        help="same cap for the readme model; smaller because README rows are much larger; 0 = no cap")
    parser.add_argument("--target-precision", type=float, default=0.995,
                        help="minimum fraction of auto-cleared items that must be truly safe")
    parser.add_argument("--threshold", type=float, default=None,
                        help="force this decision threshold for BOTH models instead of auto-picking")
    parser.add_argument("--min-df", type=int, default=2, help="drop tokens seen in fewer than this many packages")
    parser.add_argument("--max-features", type=int, default=None,
                        help="keep at most this many tokens (by frequency); bounds model size and fit time")
    parser.add_argument("--min-weight", type=float, default=1e-4, help="drop weights smaller than this (sparsity)")
    parser.add_argument("--C", type=float, default=1.0, help="inverse regularization strength")
    parser.add_argument("--l1-ratio", type=float, default=1.0, help="1.0 = L1 (sparse), 0.0 = L2, between = elastic-net")
    parser.add_argument("--max-iter", type=int, default=2000)
    parser.add_argument("--test-size", type=float, default=0.2)
    parser.add_argument("--seed", type=int, default=42)
    args = parser.parse_args()

    meta_cap = None if args.max_per_class in (0, None) else args.max_per_class
    readme_cap = None if args.max_readme_per_class in (0, None) else args.max_readme_per_class
    rng = np.random.default_rng(args.seed)

    meta_tokens, meta_labels, meta_stats = load_jsonl(f"{args.input_dir}/metadata.jsonl", meta_cap, rng)
    readme_tokens, readme_labels, readme_stats = load_jsonl(f"{args.input_dir}/readme.jsonl", readme_cap, rng)

    if len(meta_labels) == 0:
        print("No metadata examples found - run packagist:spam:build-features first.", file=sys.stderr)
        return 1

    model = {
        "version": 1,
        "metadata": train_model("metadata", meta_tokens, meta_labels, meta_stats, args),
        "readme": train_model("readme", readme_tokens, readme_labels, readme_stats, args),
        "config": {
            "min_df": args.min_df,
            "max_features": args.max_features,
            "min_weight": args.min_weight,
            "C": args.C,
            "l1_ratio": args.l1_ratio,
            "max_per_class": meta_cap,
            "max_readme_per_class": readme_cap,
            "target_precision": args.target_precision,
        },
    }

    with open(args.output, "w", encoding="utf-8") as fh:
        json.dump(model, fh)
    print(f"\nWrote model to {args.output}")
    print("Deploy this file to the prod path in SPAM_MODEL_FILE. Do NOT commit it.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
