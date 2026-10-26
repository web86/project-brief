# ProjectBrief 0.5.0.2

Integration actions now use global localized notifications. Revoked API keys
immediately disappear from the active list while their server records and audit
metadata remain. Failed requests preserve visible records and never show success.

Webhook deletion names the endpoint and warns that its delivery/attempt history
will be permanently deleted and already sent events cannot be recalled. Successful
deletion clears only its card and selected history. Existing database cascades and
worker locking are regression-tested, including a concurrent in-flight delivery.

“All projects” is the compact upper sidebar heading/link; its lower duplicate is
removed. Integrations is under “My settings” for workspace owners. Team now has a
distinct user-check icon. Desktop/mobile, RU/EN and light/dark/system are covered.

This patch adds no migration or REST/webhook contract change. It includes all prior
5.0/5.0.1 migrations; read PRODUCTION.md before installation or rollback. No push or
live deployment has been performed. Specifications are in INTEGRATIONS/ and evidence
in GUIDES/milestone-5-0-2/ in the production archive.
