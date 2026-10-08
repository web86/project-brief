# ProjectBrief SaaS Roadmap

Branch: `feature/saas-foundation`  
Baseline: `c24c475`

## Product direction

ProjectBrief is evolving from a single-owner project planner into a public multi-tenant SaaS.

The core model is:

```
User
  └─ Workspace
      ├─ Owner
      ├─ Admin / team member
      └─ Projects
          └─ Project clients
```

A registered public user becomes the owner of a private workspace. Owners can invite team members and grant them access to selected projects. Project clients remain a separate lightweight identity and continue to use personal access links without passwords.

## Architecture principles

1. **Workspace is the tenant boundary.**
   Every project belongs to exactly one workspace. No authenticated user may access another workspace unless they have an explicit membership.

2. **Do not use global admin access for SaaS authorization.**
   Existing `role=admin` behavior is transitional. Access must be resolved through workspace membership and project membership.

3. **Owner and team member are account identities.**
   They authenticate with email/password and may belong to more than one workspace.

4. **Project clients remain separate from users.**
   Keep `project_clients` and personal access-link authentication. Do not force client passwords in these milestones.

5. **Unread state is personal.**
   Email/Push delivery records are not read-state. Each authenticated team member gets their own per-project last-seen marker.

6. **Notifications are recipient-localized.**
   System copy uses the recipient's locale. User-authored content is preserved verbatim.

7. **Security boundaries must be enforced server-side.**
   Frontend visibility is never an authorization mechanism.

---

# Milestone 1 — UX and notification fixes

## Developer task editing

Replace the current aggressive developer autosave behavior.

Current implementation debounces changes by roughly 450 ms, which can generate unnecessary PATCH requests and noisy task-history records.

Target behavior:

- explicit **Save changes** action for developer-only task fields;
- dirty-state indicator;
- Cmd+S / Ctrl+S support;
- warn before leaving a task with unsaved developer changes;
- optionally preserve an unsaved local draft for accidental reload recovery;
- status changes, comments, approvals and ordering remain immediate actions;
- do not create history rows for intermediate typing.

## Email notifications

Make notification emails immediately understandable to non-technical clients.

Every notification should answer:

- what happened;
- where it happened;
- what the recipient should do next.

Requirements:

- RU and EN system copy according to recipient locale;
- client locale comes from `project_clients.preferred_locale`;
- authenticated users will later have their own `preferred_locale`;
- comment notifications include a safe excerpt or the full bounded comment text;
- clarification notifications explain what needs clarification and include relevant latest developer context when appropriate;
- review notifications explicitly say the task is waiting for client review;
- completed notifications explicitly say the task is completed;
- admin notifications clearly identify the client and include the relevant client message/action;
- user-authored text is not machine-translated;
- HTML and plain-text templates stay equivalent;
- Push payloads remain concise.

Tests must cover RU/EN output and event-specific content.

---

# Milestone 2 — SaaS foundation / tenant isolation

Introduce the multi-tenant domain model before adding public registration.

## New domain

Suggested structure:

```
users
workspaces
workspace_members
projects.workspace_id
project_members
```

### workspaces

Own the projects and later billing/plan state.

Suggested fields:

- id
- uuid
- name
- slug or stable display identifier
- timestamps

### workspace_members

Links users to workspaces.

Suggested fields:

- workspace_id
- user_id
- role: owner | admin
- status
- invited_at
- joined_at
- timestamps

### project_members

Controls which team members can access which projects.

Suggested fields:

- project_id
- user_id or workspace_member_id
- role if needed later
- timestamps

Owners implicitly retain access to all projects in their workspace.

## Migration of existing production data

The current production account and all current projects must be migrated safely into one initial workspace, for example `Web86`.

Requirements:

- additive migrations only;
- no destructive reset;
- current administrator becomes workspace owner;
- all existing projects are assigned to that workspace;
- all existing client access links remain valid;
- existing Push subscriptions and notification history remain valid;
- current admin login remains functional during migration.

## Authorization

Every server-side project action must resolve:

```
authenticated user
→ workspace membership
→ project belongs to workspace
→ project access permitted
```

No global `Project::find(...)` style access may bypass tenant checks.

Add cross-tenant security tests for all important project/task/client/attachment/notification endpoints.

---

# Milestone 3 — Admin navigation and personal unread state

Introduce the SaaS-oriented admin shell.

## Global admin layout

Desktop:

- persistent sidebar;
- workspace switcher;
- New project action;
- quick project list;
- per-project unread badge;
- All projects;
- Team;
- Workspace settings;
- current user / Profile / Sign out.

Mobile:

- compact header;
- sidebar becomes a drawer;
- same navigation hierarchy and unread information.

## Project navigation

Split the current combined project settings view into clear routes:

```
/admin/projects/{id}/brief
/admin/projects/{id}/clients
/admin/projects/{id}/structure
/admin/projects/{id}/notifications
/admin/projects/{id}/settings
```

Use a project-level secondary navigation.

## Personal unread state

Do not use `notification_deliveries`.

Introduce a per-user/project read marker, conceptually:

```
project_reads
  user_id
  project_id
  last_seen_history_id
  updated_at
```

Unread client activity is derived from relevant `task_history` rows after the user's last seen marker.

Initial relevant events should include at least:

- client created task;
- client comment;
- client approval.

Unread counts must be independent for each team member.

---

# Milestone 4 — Public auth, profiles and team invitations

Only after tenant isolation is complete.

## Public owner registration

Add public registration:

```
/register
→ name
→ email
→ password
→ create user
→ create workspace
→ create owner membership
→ enter admin area
```

Registration must never grant access to existing workspaces.

## Email verification

Support email verification for public accounts before sensitive account/team operations as appropriate.

## Password recovery

Implement:

```
/forgot-password
/reset-password/{token}
```

Requirements:

- Laravel password broker;
- bounded token lifetime;
- throttling;
- identical public response for existing and unknown email addresses;
- invalidate appropriate sessions after password reset;
- RU/EN pages and mail.

## User profile

Add:

- name;
- email;
- preferred locale;
- password change;
- Push device management.

## Team invitations

Workspace owner can invite a person by email and select project access.

Flow:

```
Owner
→ invite email
→ recipient opens one-time invitation
→ existing user signs in OR new user registers
→ accepts workspace membership
→ receives selected project memberships
```

Requirements:

- signed/hashed, expiring, single-use invitation token;
- no plaintext reusable invite token stored in DB;
- owner-only team management;
- cannot remove/demote the last workspace owner;
- existing users may belong to multiple workspaces.

## Roles

Initial roles:

- `owner`
- `admin`

Avoid complex RBAC until a concrete need appears.

---

# Later milestones

Not part of the four milestones above, but the architecture must not block:

- workspace plans/subscriptions;
- project/member/storage limits;
- billing;
- multiple workspaces per user;
- assigning a project to selected team members;
- richer activity center;
- client accounts as an optional future feature;
- durable notification outbox/queue if delivery reliability requirements increase.

---

# Delivery strategy

Implement one milestone at a time.

For every milestone:

1. create a clean checkpoint;
2. preserve current production behavior unless explicitly changed;
3. add backend and frontend tests;
4. run the full existing test suite;
5. build the production release;
6. update documentation;
7. report migrations and deployment steps;
8. keep the working tree clean.

Do not mix Milestone 4 authentication changes into Milestones 1–3.

## Current next step

Start with **Milestone 1 — UX and notification fixes** on `feature/saas-foundation`.

After Milestone 1 is complete and verified, continue with tenant isolation before implementing public registration or multiple independent owners.
