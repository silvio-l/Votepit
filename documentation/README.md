# Votepit Community Edition — Documentation

Technical reference for installing, configuring, operating, and integrating with a
self-hosted Votepit instance. Start with the top-level [`../README.md`](../README.md) for
the product pitch and a quick install; this directory is the detailed reference.

| Guide | Covers |
|---|---|
| [`architecture.md`](architecture.md) | System overview, request pipeline, tenancy model, data model, frontend/backend split |
| [`installation.md`](installation.md) | Requirements, step-by-step install, upgrades, `.htaccess` deployment layout |
| [`configuration.md`](configuration.md) | Every `config.php` key, defaults, when it matters, env-driven scripts |
| [`api-reference.md`](api-reference.md) | REST endpoints, auth models, request/response shapes |
| [`mcp-server.md`](mcp-server.md) | The Agent API's MCP (Model Context Protocol) endpoint: tools, auth, examples |
| [`operations.md`](operations.md) | Migrations, backup/restore, cron jobs, logging, error monitoring |
| [`development.md`](development.md) | Local dev setup, test/QA pipeline, frontend i18n architecture, contributing |
| [`troubleshooting.md`](troubleshooting.md) | Known failure modes and how to diagnose them |

## Two audiences, one codebase

This package is the same code whether it runs as a single self-hosted instance or as the
base of a hosted, multi-account deployment (`routing_mode: cloud` plus registered
`extensions` — see [`configuration.md`](configuration.md)). Everything in this directory
applies to both; whatever a hosting operator layers on top (their own extension package,
infrastructure, contracts, paperwork) lives outside this package and is out of scope here.

## Source of truth

This documentation is written from the actual implementation (PHP source under `../src/`,
config template `../config/config.example.php`, migrations under `../migrations/`, tests
under `../tests/`). If something here and the code disagree, the code wins — please file
an issue or PR against the mismatch.
