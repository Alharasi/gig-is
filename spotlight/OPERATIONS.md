# Gig spotlight: unattended publication

Canonical public site: https://gig.is/
Canonical source: Alharasi/gig-is, branch main, directory spotlight/.
Do not replace the repository root index.html or legacy Cloudflare/GitHub Pages workflows.

## Editing
- Read spotlight/index.html and its referenced versioned gig-app-*.js.
- Ideas live as valid JSON in the JavaScript: var ideas = [...]; followed by var currentIndex.
- 1 to 5 strong services only. Preserve the UI, Tajawal/Montserrat, bookmark behavior and manual navigation.
- whyNow is the opportunity analysis shown under تحليل الفرصة. Include need and impact, evidence, solution rationale, assumptions and risks; no unsupported earnings/rarity promises.
- To change content, create a new versioned JS filename with a unique dated release suffix, validate syntax and every plan, then update spotlight/index.html to reference it and change meta gig-release.
- Create the new JS first, update HTML last, using fresh GitHub blob SHAs. Preserve concurrent changes.
- Keep evidence and decision logs in this directory; no secrets or private research in public files.

## Production
On Namecheap cPanel, /home/adigekrz/gig_publisher/publish.php is outside the web root.
The existing cron job runs it every five minutes. It reads only the spotlight index and referenced JS from this public repo over verified HTTPS.
It validates the release, 1-5 complete ideas and duplicate IDs, backs up the current page, publishes the versioned JS before the page, then verifies public page/script hashes.
If public verification fails, it restores the previous page when available.
It leaves the last good site alone when source download or validation fails.
Live status: https://gig.is/gig-update-status.json
Status includes release, sha256 of HTML, idea count, check/publication time and Telegram state; no credentials.
A GitHub commit is not proof of live publication. Verify the live release/status after the next cron; if unavailable state that publication is unconfirmed.
Do not require local browser/cPanel/SSH credentials for scheduled content edits: GitHub connector is the write path.
Existing server CSP allows same-origin scripts/fonts; never add unsafe-inline to script-src.
Fonts are local /gig-fonts.css and /gig-fonts/; install-fonts.php bootstrapped these from Google's font service.

## Telegram
Not configured at setup. Do not claim any notification sent.
Publisher reads optional /home/adigekrz/gig_publisher/telegram.json containing token and chat_id. Store it only on server with mode 0600.
Never commit tokens, put them in frontend code or expose them in status/logs.
When configured it sends after confirmed publication, with one pending notice on the next unchanged check if a previous notification was not delivered.
Credential setup requires user input; target chat must be explicitly identified.

## Scheduled research
Morning: discovery and screening. Evening: deep comparison and challenges.
Prioritize existing displayed services, allow keep/develop/suspend/replace/archive; no quota and no cosmetic churn.
Use direct need/payment evidence and official implementation docs. Distinguish job post, seller offer, claimed revenue, and verified paid contract. Do not generalize foreign demand to Oman.
Publish evidence-backed improvements under the user's authorization; if no meaningful change, do not commit or republish.
