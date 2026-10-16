# Contributing

Open an issue before a large change, so we can agree on the approach first.

## Commits

Releases and the changelog are generated from commit messages, so commits follow [Conventional Commits](https://www.conventionalcommits.org): `feat(triggers): …`, `fix(transitions): …`, `docs: …`. A breaking change gets a `!` after the type and a `BREAKING CHANGE:` footer saying what users have to change.

## Checks

```bash
composer test      # Pest
composer analyse   # PHPStan
composer format    # Pint
```

Tests run against SQLite by default. Set `DB_CONNECTION` and the other `DB_*` variables to run them against MySQL or PostgreSQL, as CI does.
