# wajaycha-back-laravel

Laravel 11 API. **Sole owner of the Wajaycha financial domain.**

## Read the shared context first

This repository is part of the Wajaycha workspace. Domain language, subdomain
boundaries, architecture diagrams, and accepted decisions live one level up:

- `../CLAUDE.md` — workspace entry point
- `../docs/domain/ubiquitous-language.md` — **read before touching `Detail`, `Transaction`, `Category`, or `ParetoClassification`**
- `../docs/domain/context-map.md` — subdomain boundaries
- `../docs/architecture/technical-debt.md` — verified outstanding issues
- `../docs/decisions/` — accepted decisions

## Coding rules for this repository

- `.agents/rules/01-laravel-core.md`
- `.agents/rules/02-database-dba.md`

## Warnings

- `PLAN_REFACTORIZACION.md` is a **2026-04-19 historical audit**, not current
  state. Several of its findings are already fixed. See `../docs/decisions/0003-supersede-refactoring-plans.md`.
- `../wajaycha-nest/` is abandoned. Do not read it as a reference. See `../docs/decisions/0001-discard-nest-microservice.md`.
- `config/database.php` defaults to `sqlite`. The system requires PostgreSQL
  with pgvector and the SQL functions in `database/migrations/`.
