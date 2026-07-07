# Spam classifier training

Trains the model that `packagist:spam:triage-queue` uses to auto-clear obviously-safe packages out
of the `/spam` review queue. Inference runs in pure PHP (`App\Service\Spam\SpamClassifier`); this
Python step only does the offline numeric fit and is never installed on prod.

## Design

All tokenization lives in **one** PHP class, `App\Service\Spam\FeatureExtractor`, used both to build
the training data and to score at runtime. Python only ever sees token lists, so training and
serving cannot drift. There are two independent linear models:

- **metadata** — name + description + tags (always available)
- **readme** — README text + link hosts/density (only for packages that have a README)

At inference a package is auto-cleared only if the metadata model scores it safe **and** — when a
README exists — the readme model also scores it safe. A spammy README vetoes a clear; a missing
README is never held against a package (this is why README is a separate, README-present-only pass).

Labels: spam = `package.frozen = 'spam'`; safe = packages under a `vendor.verified = 1` vendor.

## The weights are private

The trained `spam-model.json` is **never committed**. Publishing it would let spammers reverse-
engineer the scoring. It lives only on prod, at the path in the `SPAM_MODEL_FILE` env var. When the
file is absent the classifier is disabled and the triage command no-ops.

## Steps

The dataset is built straight on prod (it needs the live DB) and the files are downloaded to
train locally. All commands default to the current working directory for their files.

```bash
# 1. On PROD, build the labelled dataset. Writes metadata.jsonl + readme.jsonl into the cwd:
bin/console packagist:spam:build-features
#    (pass --output-dir <dir> to write elsewhere)

# 2. Download metadata.jsonl and readme.jsonl to your working machine (e.g. scp / rsync).

# 3. Fit the two models locally. Reads *.jsonl from the cwd, writes spam-model.json into the cwd:
python3 -m venv scripts/spam/.venv
scripts/spam/.venv/bin/pip install -r scripts/spam/requirements.txt
scripts/spam/.venv/bin/python /path/to/repo/scripts/spam/train.py
# Inspect the printed precision/coverage tables and adjust --target-precision / --threshold as needed.
# (--input-dir <dir> / --output <file> override the cwd defaults)
#
# The safe class dwarfs spam, so by default every spam row is kept and safe rows are streamed +
# sampled down to --max-per-class (default 50000) to keep memory and fit time sane. Raise it for
# more data (slower), or pass --max-features / --min-df to bound the vocabulary. Capping rows or
# vocabulary is inference-safe; do NOT try to shorten individual READMEs here (that would diverge
# from what PHP tokenizes and cause train/serve skew).

# 4. Dry-run the triage against the real queue before trusting it (point SPAM_MODEL_FILE at the file):
SPAM_MODEL_FILE="$(pwd)/spam-model.json" bin/console packagist:spam:triage-queue

# 5. Once happy, deploy spam-model.json to the prod SPAM_MODEL_FILE path and run with --apply
#    (typically from cron):
bin/console packagist:spam:triage-queue --apply
```

## Retraining

Spam drifts. Re-run steps 1–3 periodically and replace the file on prod. Start with a conservative
threshold (favour leaving items in the queue over wrongly clearing spam) and loosen based on the
observed clears.
