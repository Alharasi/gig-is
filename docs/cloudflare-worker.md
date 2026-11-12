# Gig.is - Cloudflare Worker

This repository continues to serve the public website from GitHub Pages. The Cloudflare Worker is a **separate API** deployed from this repository; it does not replace the website.

## Deployment
1. In Cloudflare Workers, connect GitHub repository `Alharasi/gig-is`.
2. Project configuration: `wrangler.toml` at repository root. Deploy Worker `gig-is-search`.
3. In Worker Settings > Variables and Secrets add secret `GITHUB_TOKEN` (fine-grained GitHub token with **Contents: Read-only** access to **only** `Alharasi/gig-is-data`). Never paste this token into chat, GitHub files or browser code.
4. In Worker Settings, optionally set `ALLOWED_ORIGINS` to your custom site origin, for example `https://gig.is`. The GitHub Pages origin `https://alharasi.github.io` is already allowed.
5. Test `/health` and `/search?category=all&level=0&page=1`. The private data file is currently empty; add reviewed services with `status: "published"` to return results.
6. Only after successful tests, update the public site to call the Worker endpoint instead of `data/services.json`.

## Security notes
- The private repository is never served as a static asset.
- The API only returns up to 3 approved published services per page. It does not return the full file in one response.
- CORS is a browser control, not authentication or anti-scraping. Public search results can still be collected through repeated requests.
- For production, add Cloudflare rate limiting and a data cache to reduce GitHub API requests; avoid relying on a GitHub fetch for every search.
- The `/health` route requires no token. The `/search` route returns 503 until configured.
- This Worker does not publish or expose any GitHub token.
