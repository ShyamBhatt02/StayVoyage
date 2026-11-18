# StayVoyage build progress

All 8 phases are implemented and verified (2026-11-17). See README.md for setup and architecture.

Verification performed:
- 29 automated feature tests (MySQL `stayvoyage_test`) — all 24 critical scenarios — passing.
- Headless-Edge smoke tests: 21 public pages (desktop + mobile), all customer, admin and partner pages — no console errors, no broken images.
- End-to-end browser booking: login → hotel → Book now → guest details → sandbox payment → confirmed booking → invoice.
- `memberships:verify-ledger` and inventory-vs-bookings reconciliation match.

Open items for the user:
- Restart WAMP (admin rights needed) so the new Apache vhost on port 8096 is active.
- Optionally add Razorpay/Stripe test keys and a Mailtrap inbox in backend/.env.
- Git repository not initialised/committed (awaiting user instruction).

Known limitations / future work:
- Auto-renewal sends a reminder with one-tap renewal (no stored cards / mandates).
- Admin "no-show" action not implemented (cancellation with penalty covers the case).
- Invoice PDFs embed the DejaVu font (~0.8 MB each).
