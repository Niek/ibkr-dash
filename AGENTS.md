# Repository Guidelines

## Project Overview
This repository hosts a lightweight PHP dashboard for Interactive Brokers (net liquidation chart with tooltips, positions table with P&L %s and base-currency P&L, intraday P&L, cash balances, and gateway status). The app depends on the IBKR Client Portal Gateway; [ibeam](https://github.com/Voyz/ibeam) is recommended to keep the session alive.

## Project Structure & Module Organization
- `index.php`: current entrypoint (renders gateway status, loops accounts, pulls performance/positions, intraday P&L, cash balances).
- `api.md`: concise IBKR Client Portal OpenAPI notes and key endpoints.
- `.env` / `.env.example`: local configuration for gateway base URL and headers.
- `tests/`: automated tests (when added).

## Build, Test, and Development Commands
- `php -S 127.0.0.1:5080`: run the local dev server (serves `index.php`).
- `php -l path/to/file.php`: lint a single PHP file.
- `curl -k https://localhost:5050/v1/api/iserver/auth/status`: verify the gateway session (adjust base URL/port as configured).
- `curl -k https://localhost:5050/v1/api/portfolio/{accountId}/positions`: confirm positions data.
- `curl -k -X POST https://localhost:5050/v1/api/pa/performance -H 'Content-Type: application/json' -d '{"acctIds":["..."],"period":"30D"}'`: confirm performance data.
- Site URL for testing: `http://127.0.0.1:5080/`

## UI Features
- Privacy toggle (👁️) in the top-right blurs sensitive amounts and account IDs; percentages and symbols remain visible.
- When privacy is enabled, the chart Y-axis is hidden to widen the plot area.

## Screenshot Workflow
1. Start the dev server: `php -S 127.0.0.1:5080`.
2. Open `http://127.0.0.1:5080/`.
3. Click the 👁️ privacy toggle to blur sensitive data.
4. Capture a full-page screenshot and save as `.github/screenshot.jpg`.
5. Resize the screenshot to max 800px width: `gm convert .github/screenshot.jpg -resize 800x\> .github/screenshot.jpg`.

## Coding Style & Naming Conventions
- Follow PSR-12 with 4-space indentation; add strict types where practical.
- Classes use `PascalCase` in `ClassName.php`; functions/vars use `camelCase`; constants use `UPPER_SNAKE_CASE`.
- Keep IBKR API wrapper methods aligned to endpoint intent (e.g., `getPortfolioSummary`).

## Testing Guidelines
- No test framework is configured yet. If adding tests, use PHPUnit in `tests/` with `*Test.php` names.
- Prioritize coverage for API response parsing, aggregation logic, and error handling.

## Commit & Pull Request Guidelines
- No git history detected in this folder. Use short, imperative commit subjects; optional scope prefixes like `api:` or `ui:`.
- PRs should include a clear description, screenshots for UI/chart changes, and notes about any API/gateway behavior changes.
- Commit and push after each change.

## Security & Configuration Tips
- Never commit credentials, account IDs, or session cookies.
- Copy `.env.example` to `.env` and keep gateway host/port there (git-ignored).

## API Reference
- Key endpoints in use: `/iserver/auth/status`, `/iserver/accounts`, `/iserver/account/pnl/partitioned`, `/portfolio/{accountId}/summary`, `/portfolio/{accountId}/ledger`, `/portfolio/{accountId}/positions`, `/pa/performance` (period `30D`, fallback `1M`), `/pa/transactions` (`days` param, default 3650; only when positions are non-base currency) for base-currency cost/P&L.
- See `api.md` for a concise summary of the IBKR Client Portal OpenAPI spec and key endpoints used by this dashboard.
- Always check the OpenAPI spec before implementing or changing any API calls (parameter names, allowed values, and constraints).
