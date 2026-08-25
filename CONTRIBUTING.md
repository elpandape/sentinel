# Contributing

Thanks for taking the time to contribute.

## Working on the package

The project runs entirely in Docker — no PHP or Composer needed on your machine.

```bash
make build      # build the dev image
make install    # composer install
make test       # run the suite
make ci         # everything CI runs: pint, phpstan, rector, coverage, type coverage
make shell      # a shell inside the container
```

## Before opening a pull request

- `make ci` must pass. Coverage and type coverage are gates at **100%**, not goals.
- Tests are written in Pest, in English, with the intent in the `it(...)` description.
- Comments are minimal and in English: if a line needs explaining, rename it instead.
- Commits follow Conventional Commits, with a scope naming a real module.
- Anything touching the integrity chain ships a backwards-compatibility test.

## Reporting bugs

Include the Laravel and PHP versions, the ledger driver in use, and a minimal
reproduction. If the bug affects audit integrity, follow `SECURITY.md` instead.
