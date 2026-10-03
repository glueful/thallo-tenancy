# glueful/thallo-tenancy

Multi-tenancy (workspaces) for [Thallo](https://thallo.dev): several independent sites on one
install, one code base and one PostgreSQL database. This pack owns Thallo's side of it: which
tables are workspace-owned, the staged enablement flow that widens them, request scoping, tenant
seed/sync, public origins, and workspace purge. The `glueful/tenancy` engine provides the tenant
registry and row-level enforcement.

## What it provides

- **Enablement flow** (`src/Enablement`): a staged, resumable, locked state machine that adds a
  workspace column to every workspace-owned table, widens unique constraints and adopts existing
  data into a first workspace. It pauses for a restart and blocks writes while the schema changes.
  Disabling turns scoping off and keeps the widened schema.
- **Admin API** under `/v1/admin` (behind `content_permission:tenancy.manage`):
  `GET /tenancy/status`, `GET /tenancy/diagnose`, `POST /tenancy/begin|confirm|retry|cancel|finalize|disable`,
  full request resolution (`/tenancy/resolution…`), the public origin (`/tenancy/public-origin`),
  and workspace, domain and membership management under `/tenants…` and `/domains…`.
- **Admin screens**: **Settings › Workspaces** runs the enablement flow and holds the platform
  switches. Once workspaces are on, the sidebar's **Workspaces** group adds **All workspaces**,
  **Domains**, **Members** and **Roles**.
- **Console commands**: `thallo:tenancy:status`, `thallo:tenancy:enable` (`--slug`, `--name`,
  `--owner`), `thallo:tenancy:disable`, `thallo:tenancy:diagnose`, the `thallo:tenancy:resolution:*`
  commands, `thallo:tenancy:tenant`, `thallo:tenancy:domain`, `thallo:tenancy:member`,
  `thallo:tenant:seed`, `thallo:tenant:sync`, `thallo:tenant:blocks:sync`,
  `thallo:tenancy:purge:recover`, `thallo:tenancy:hosts:sweep` and
  `thallo:tenancy:single-store:repair`, all run as `php glueful …`.

## Turning it on

The pack ships with Thallo: `glueful/thallo-core` requires it at the same version and the project's
`config/serviceproviders.php` loads its provider. It registers the `thallo.tenancy` capability,
whose owning package is `glueful/tenancy`. A new install is one site with tenancy **off**.

Workspaces are turned on only through the enablement flow: **Settings › Workspaces** in the admin,
or `php glueful thallo:tenancy:enable` from a shell. The engine's enforcement provider is listed
under `protected` in `config/extensions.php`, so `php glueful extensions:enable glueful/tenancy`
refuses it, and the **Extensions › Capabilities** switchboard cannot turn it on either: while
enforcement is off, enabling there answers 409 with a remedy that points at Settings › Workspaces.

## Documentation

- [`docs/concepts/08-workspaces.md`](../../docs/concepts/08-workspaces.md): what a workspace owns
  and what turning workspaces on costs.
- [`docs/operations/07-multi-site.md`](../../docs/operations/07-multi-site.md): the enablement
  stages, commands and how to undo them.
- `docs/internal/operations/tenancy.md`: provider model, purge recovery and signup operations.

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-tenancy/`.
