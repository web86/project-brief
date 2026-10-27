# Project Brief 0.7.2 — Flexible Account Security

Implements GitHub issue [#7](https://github.com/web86/projects-manager/issues/7) on the retained local Milestone 7 hotfix lineage. Owners keep mandatory TOTP and saved recovery codes; Passkeys are optional. Developers can choose either factor, but enabling TOTP enforces it on every password path. Client and bearer identities remain unchanged.

- [Audit and baseline](AUDIT.md)
- [Policy, account matrix and sensitive ACL map](POLICY.md)
- [Local verification and limitations](VERIFICATION.md)
- [Jino update, diagnosis and rollback](PRODUCTION.md)

No push, merge or deploy. The production ZIP and its digest are recorded separately after verification of the extracted package.
