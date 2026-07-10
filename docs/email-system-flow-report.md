# Email System Flow Report

## 1. Executive Summary

The application has email flows implemented through `NotificationService`, three queue-aware listeners/jobs, direct controller calls, and one Mailable (`ArticleRejectedMail`). No Laravel Notification classes were found. Workflow notifications are controlled by `SendArticleWorkflowNotifications`; article-submission notices are controlled by `SendArticleSubmissionNotifications`. Both listeners implement `ShouldQueue`. The system has broad workflow coverage, but email bodies are inconsistent in detail and some category-specific flows (editor assignment, issue changes, status-change support mail, security alerts) have no dedicated email flow.

## 2. Email Infrastructure Overview

- Configuration: `config/mail.php`; queue configuration: `config/queue.php`.
- Primary sender: `app/Services/NotificationService.php::send`, which records a `NotificationLog` and dispatches `app/Jobs/SendNotificationJob.php`.
- Shared rendering/layout is owned by `NotificationService`; action links are passed as `{ text, url }`.
- Frontend links use `APP_URL_FRONTEND`, defaulting to `http://localhost:3000`.
- Queued listeners: `SendArticleSubmissionNotifications`, `SendArticleWorkflowNotifications`; queued job: `SendNotificationJob`.
- Mailable: `app/Mail/ArticleRejectedMail.php`. No `app/Notifications` directory/classes were found.

## 3. Global Delivery Rules

`NotificationService::send` is the normal delivery gateway. Workflow listeners deduplicate recipients by lower-cased email and suppress a repeated workflow event using `article_audit_logs` (`notification.sent`, matching event/from/to status). This prevents repeated transition notifications, but is not a general idempotency key for all controller-driven emails. Email delivery depends on configured mail/queue workers; queued listeners are not immediate.

## 4. Complete Email Inventory

| ID | Email Flow | Trigger | Sender | Template | Recipients | Delivery | Subject | Status |
|---|---|---|---|---|---|---|---|---|
| E-001 | Password setup | Account provisioned | `PasswordSetupService` | Notification service | New user | queued job | Set your ScholarlyNest password | Exists |
| E-002 | Password reset / verification | Auth actions | `AuthController` | Notification service | Account email | queued job | action-specific | Exists |
| E-003 | Article submitted | `ArticleSubmitted` event | `SendArticleSubmissionNotifications` | Notification service | owner, co-authors, editors, super admins | queued listener | Manuscript Submitted: {{ article_title }} — {{ magazine_name }} | Exists |
| E-004 | Reviewer invitation | assign reviewer | `ArticleWorkflowController::sendReviewerInvitation` | Notification service | invitee | queued job | Review Invitation: {{ article_title }} — {{ magazine_name }} | Exists |
| E-005 | Reviewer accept/decline | invitation response | `SendArticleWorkflowNotifications` | Notification service | scoped editors, sub-editors, super admins | queued listener | Reviewer Accepted/Declined Invitation: {{ reviewer_name }} — {{ article_title }} | Partial |
| E-006 | Workflow notifications | workflow events | `SendArticleWorkflowNotifications` | Notification service | event-specific | queued listener | workflow-specific | Exists/Partial |
| E-007 | Article rejected | decision | `ArticleRejectedMail` / workflow path | Mailable | author path | unclear | Article Decision: {{ article_title }} | Partial |
| E-008 | Support ticket emails | ticket create/reply | `SupportTicketController` | Notification service | requester/support users | queued job | action-specific | Exists |
| E-009 | Newsletter | campaign/subscribe | `NewsletterController` | Notification service | subscriber | queued job | campaign subject | Exists |

## 5. Authentication and Account Emails

### E-001 — Password Setup Email

**Status:** Exists. **Code path:** `app/Services/PasswordSetupService.php`; invoked by `AuthController`, `RbacController`, co-author provisioning, and reviewer invitation acceptance. **Recipient:** provisioned account only. **Delivery:** NotificationService job. **Subject:** `Set your ScholarlyNest password`. **Content:** greeting; account/setup explanation; `{{ setup_url }}` action; expiry/security guidance supplied by the service. No password is emailed.

Password reset, verification-code, email-change, and password-change code flows are implemented in `app/Http/Controllers/AuthController.php`; they send only to the authenticated/requested account email. No separate welcome-email flow was found.

## 6. Article Submission Emails

### E-003 — Article Submitted

**Trigger/code:** `ArticleController::store` dispatches `ArticleSubmitted`; `SendArticleSubmissionNotifications::handle` queues delivery. **Recipients:** owner, all supplied co-authors, magazine editor/magazine_editor users, and super admins; deduped by email. **Subject:** `Manuscript Submitted: {{ article_title }} — {{ magazine_name }}`. **Content:** `Dear {{ recipient_name }},` confirmation/new-submission notice; title, magazine, `{{ tracking_code }}`, submitted timestamp, workflow/dashboard action. Staff copy additionally includes submitting author and stripped abstract. **Action:** `{{ APP_URL_FRONTEND }}/admin/articles`. **Privacy:** no files/S3 keys are included. Drafts do not dispatch `ArticleSubmitted`; terms acceptance does not send mail.

## 7. Reviewer Workflow Emails

### E-004 — Reviewer Invitation

**Trigger/code:** `ArticleWorkflowController::assignReviewer` then `sendReviewerInvitation`. **Recipient:** invited reviewer only. **Subject:** `Review Invitation: {{ article_title }} — {{ magazine_name }}`. **Content:** greeting; invitation statement; tracking code; corresponding author; stripped abstract; secure invitation action; statement that files are available only after acceptance. **Action:** `{{ APP_URL_FRONTEND }}/review-invitations/{{ assignment_id }}?token={{ token }}`. **Privacy:** no attachment/S3 URL; token is required by public invitation endpoints.

### E-005 — Reviewer Accepted / Declined

**Trigger/code:** `acceptReviewerInvitation` emits `review.accepted`; `declineReviewerInvitation` emits `review.declined`; `SendArticleWorkflowNotifications::handle` sends after duplicate guard. **Recipients:** magazine editors, assigned sub-editors, super admins; unrelated users excluded by scope queries and recipient dedupe. **Subjects:** `Reviewer Accepted Invitation: {{ reviewer_name }} — {{ article_title }}` / `Reviewer Declined Invitation: {{ reviewer_name }} — {{ article_title }}`. **Content:** greeting; reviewer name/email, response, article title, magazine, tracking code, timestamp; `Open Workflow` action. **Action:** `{{ APP_URL_FRONTEND }}/admin/articles`. **Gap:** external declined invitee has no account actor, so its name/email must be confirmed from invitation payload in QA.

## 8. Editorial Workflow Emails

`SendArticleWorkflowNotifications::messageFor` covers sub-editor assigned, reviewer assigned, under review, review submitted/reopened, revision requested, accepted, rejected, production assigned/completed, ready for publication, published, and post-publication recorded. Each has an `Open Workflow` action. Recipient routing is defined by `recipientsFor`; author-facing events use owner/corresponding authors, editorial events use scoped magazine editors plus super admins, and publication events add publishers. No separate editor-assignment email was found.

## 9. Resubmission / Revision Emails

**Trigger:** `ArticleController` dispatches `article.resubmitted`. **Recipients:** owner/corresponding authors, scoped editors, assigned sub-editors, super admins; deduped. **Subject:** `Article Resubmitted: {{ article_title }}`. **Content:** resubmission statement, magazine, base tracking code, computed `{{ revision_tracking_code }}`, submitting author, timestamp, next action, workflow action. Revision codes are internal workflow/version data.

## 10. Support Ticket Emails

`SupportTicketController` uses `NotificationService` for ticket creation and reply flows. Requester receives confirmation/replies; support/admin recipients receive new-ticket and owner-reply notices. Review controller methods for exact subject/body when validating environment configuration. No dedicated closed/resolved email flow was found.

## 11. Publication Emails

Workflow events provide production/ready/published notices. Magazine/issue create/update-specific emails were not found. Newsletter campaign delivery is managed by `NewsletterController`.

## 12. Other System Emails

Contact submissions, newsletter subscription/unsubscribe, password/account actions, and support tickets have controller-owned notification paths. No permission/role-assignment, queue-failure-alert, or generic security-alert email flow was found.

## 13. Email Content Catalogue

All NotificationService messages use a greeting, body-line array, optional action `{ text, url }`, and the service’s common footer. Dynamic fields are: `{{ recipient_name }}`, `{{ article_title }}`, `{{ magazine_name }}`, `{{ tracking_code }}`, `{{ revision_tracking_code }}`, `{{ reviewer_name }}`, `{{ reviewer_email }}`, `{{ abstract }}`, `{{ timestamp }}`, and `{{ action_url }}`. Exact static workflow body lines are in `SendArticleWorkflowNotifications::messageFor`; exact submission lines are in `SendArticleSubmissionNotifications::handle`.

## 14. Recipient Matrix

| Flow | Recipients |
|---|---|
| Submission | owner, co-authors, scoped editors, super admins |
| Invitation | invited reviewer |
| Reviewer response | scoped editors, assigned sub-editors, super admins |
| Revision requested | owner/corresponding authors |
| Resubmitted | owner/corresponding authors, scoped editors/sub-editors, super admins |
| Accepted/rejected/published | author/editorial recipients per event |
| Support | requester and scoped support/admin users |

## 15. Trigger Matrix

See E-001–E-009 above. Events are registered in `app/Providers/AppServiceProvider.php`; controller-driven invitation/account/support flows call services directly.

## 16. Queue / Delivery Mode Matrix

| Flow | Mode |
|---|---|
| Submission/workflow listeners | queued (`ShouldQueue`) |
| NotificationService controller calls | queued `SendNotificationJob` |
| `ArticleRejectedMail` | verify invocation path; class does not establish delivery by itself |

## 17. Security and Privacy Review

No reviewed manuscript, private storage key, or password is intentionally placed in the documented article/reviewer mail bodies. Invitation links use a token; invite context endpoint validates its hash and expiry. Public article payload does not expose version tracking. Email HTML/body fields should continue to sanitize rich-text abstracts before sending.

## 18. Missing or Weak Email Flows

- No dedicated magazine/issue update email found.
- No dedicated editor-assignment, reviewer-reminder, ticket-closed, permission-change, or queue-failure alert email found.
- Workflow action currently points to the article board rather than an article-specific URL.
- Reviewer decline body must preserve external invitee identity when no user account exists.

## 19. QA Checklist for Email Testing

1. Run queue worker and configured mail sink.
2. Submit article; assert deduped owner/co-author/editor/admin delivery and no attachments.
3. Invite, accept, and decline external reviewer; inspect token, recipients, bodies, and duplicate guard.
4. Request revision and resubmit three times; assert R1/R2/R3 internal codes and mail content.
5. Exercise support create/reply and password/account flows.
6. Confirm public endpoints never return internal tracking/version/file data.

## 20. Code Reference Index

- `app/Services/NotificationService.php::send`
- `app/Jobs/SendNotificationJob.php::handle`
- `app/Services/PasswordSetupService.php`
- `app/Listeners/SendArticleSubmissionNotifications.php::handle`
- `app/Listeners/SendArticleWorkflowNotifications.php::{handle,recipientsFor,messageFor}`
- `app/Http/Controllers/Admin/ArticleWorkflowController.php::{assignReviewer,acceptReviewerInvitation,declineReviewerInvitation}`
- `app/Http/Controllers/ArticleController.php::{store,update}`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/SupportTicketController.php`
- `app/Mail/ArticleRejectedMail.php`
- `config/mail.php`, `config/queue.php`, `app/Providers/AppServiceProvider.php`

## 21. Final Notes

This is a code audit, not delivery evidence. Verify queue workers, provider configuration, rendered templates, and recipient mailboxes in the demo environment before relying on any flow operationally.

### Client/QA detailed-flow appendix

The inventory above is expanded below so each code-discovered flow has a concrete send contract. All `NotificationService` flows create a pending `notification_logs` row and dispatch `SendNotificationJob` on the supplied queue (`default` unless stated). The job retries 5 times with 60/120/240/480/960-second backoff, renders `GenericSystemMail`, then marks the row `sent` or `failed`. The shared rendered footer is owned by `GenericSystemMail`; no flow sends a plaintext password.

| Flow | Trigger / sender | Exact recipient rule | Subject / reconstructed body | Conditions, links, privacy |
|---|---|---|---|---|
| Password setup | `PasswordSetupService` after admin/editor/reviewer/co-author provision | Newly provisioned user only | `Set your ScholarlyNest password`; `Dear {{ name }},` account setup instruction and password-setup action | Reset token link; no password sent; expiry is governed by password-reset implementation. |
| Forgot password | `AuthController::forgotPassword` | Requested account email if account/code conditions allow | Account-recovery instruction, verification/reset code, next-step reset action | Code is secret; no account enumeration details should be inferred from response. |
| Password reset | `AuthController::resetPassword` | Account being reset | Reset completion/setup information where dispatched | Token/code validation prevents send/action; no confirmation-specific flow was found. |
| Password-change code | `AuthController` password change endpoints | Authenticated account | Security verification code and instruction to enter it before password change | Code is sensitive; do not forward/cache; expiry is controller/config governed. |
| Registration/welcome | account registration | **Missing:** no dedicated welcome email found | N/A | QA should not expect a welcome email. |
| Author submission confirmation | `ArticleSubmitted` → submission listener | Article owner | `Manuscript Submitted: {{ title }} — {{ magazine }}`; confirmation, tracking code, submitted timestamp, dashboard action | Suppressed for drafts; no files/S3 keys. |
| Co-author submission confirmation | same listener | Every supplied co-author, deduped | `Article Submitted: {{ title }}`; co-author notice, magazine, tracking code, dashboard note | Existing provisioned co-authors may instead receive setup link; no files/S3 keys. |
| Editor submission notification | same listener | Users attached to article magazine as `editor`/`magazine_editor` | `New Manuscript Submitted: {{ title }}`; title, tracking code, submitting author, abstract, timestamp, board action | Scoped to magazine; no unrelated magazine editors. |
| Super-admin submission notification | same listener | `super_admin` role users | Same editor notification body | Recipient email dedupe applies. |
| Reviewer invitation | `assignReviewer` → `sendReviewerInvitation` | Invitee email only | `Review Invitation: {{ title }} — {{ magazine }}`; invitation, tracking code, corresponding author, abstract, secure-review instruction, no-files-before-acceptance notice | Tokenized invitation URL; no manuscript attachment/S3 URL. |
| Reviewer accepted | invitation acceptance event | Scoped editors, assigned sub-editors, super admins | `Reviewer Accepted Invitation: {{ reviewer_name }} — {{ title }}`; reviewer name/email, `Accepted`, title, magazine, tracking code, timestamp, Open Workflow | Workflow-event audit dedupe; author is not recipient; identity remains editorial-only. |
| Reviewer declined | invitation decline event | Scoped editors, assigned sub-editors, super admins | `Reviewer Declined Invitation: {{ reviewer_name }} — {{ title }}`; reviewer name/email, `Declined`, title, magazine, tracking code, timestamp, Open Workflow | Same dedupe; external decline actor identity is a known QA risk. |
| Review submitted | `review.submitted` event | Scoped editors and super admins | `Reviewer Report Submitted: {{ title }}`; `A reviewer report has been submitted for "{{ title }}".` + Open Workflow | Does not send confidential review content to authors. |
| Reviewer reminder | N/A | **Missing** | N/A | No automatic reminder flow found. |
| Sub-editor assignment | `sub_editor.assigned` | Author/corresponding authors, assignee, super admins | `Sub Editor Assignment: {{ title }}`; assignment created, current status + Open Workflow | Dedupe applies. |
| Reviewer assignment | `reviewer.assigned` | Author/corresponding authors, reviewer, super admins | `Reviewer Assignment: {{ title }}`; invitation created, current status + Open Workflow | Reviewer gets separate token invitation too. |
| Revision requested | `revision.requested` | Owner/corresponding authors | `Revision Requested: {{ title }}`; revision requested and author-facing-comments instruction + Open Workflow | No reviewer confidential comments in body. |
| Article resubmitted | `article.resubmitted` | Owner/corresponding authors, scoped editors, assigned sub-editors, super admins | `Article Resubmitted: {{ title }}`; magazine, base tracking, revision tracking, submitting author, timestamp, next action + Open Workflow | Internal code only; recipients deduped. |
| Accepted | `article.accepted` | Authors, scoped editors, super admins | `Article Accepted: {{ title }}`; accepted statement + Open Workflow | Event dedupe applies. |
| Rejected | `article.rejected` | Authors, scoped editors, super admins | `Article Decision: {{ title }}`; final decision statement + Open Workflow | `ArticleRejectedMail` also exists; invocation must be QA-verified. |
| Production assigned/completed | production events | Event-specific author/editor/publisher/super-admin sets | `Production Assignment: {{ title }}` / `Production Task Completed: {{ title }}`; event statement + Open Workflow | No dedicated copy-editor-only message found. |
| Ready/published/post-publication | corresponding workflow event | Authors, editors, publishers, super admins | `Article Ready for Publication`, `Article Published`, or `Post-Publication Action Recorded: {{ title }}`; event statement + Open Workflow | Public newsletter is separate. |
| Support ticket create/reply | `SupportTicketController` | Ticket owner and support/admin permission users by action | Controller-created subject/body; ticket context and action link | Attachments are not to be represented as public URLs; closed/resolved-specific email is **Missing**. |
| Newsletter | `NewsletterController` | Opted-in subscriber | Campaign subject/body, unsubscribe URL | Unsubscribe link is included through NotificationService payload. |
| Contact | `ContactController` | Configured contact/admin recipient where controller invokes notification | Contact enquiry context | No separate client confirmation flow was conclusively found. |

#### Expanded recipient matrix

| Email Flow | Super Admin | Magazine Editor/Editor | Sub Editor | Publisher | Author/Co-author | Reviewer | Support user | External |
|---|---|---|---|---|---|---|---|---|
| Submission | Yes | Conditional (article magazine) | No | No | Yes | No | No | Co-author email conditional |
| Reviewer invitation | No | No | No | No | No | Yes | No | Yes |
| Reviewer accept/decline | Yes | Conditional | Conditional (assigned) | No | No | No | No | No |
| Revision requested | No | No | No | No | Yes | No | No | No |
| Resubmitted | Yes | Conditional | Conditional (assigned) | No | Yes | No | No | No |
| Accepted/rejected/published | Yes | Conditional | Conditional by event | Conditional publication events | Yes | No | No | No |
| Support ticket | Conditional | No | No | No | Ticket owner conditional | No | Conditional | Ticket owner conditional |

#### Expanded security matrix

| Category | S3/private URLs | Auth token/code | Reviewer identity to author | Internal tracking public | Manuscript attachment | Notes |
|---|---|---|---|---|---|---|
| Auth | No | Reset/verification secrets only to account | N/A | N/A | No | Never send plaintext passwords. |
| Submission | No | No | N/A | No | No | Abstract may be included for editorial recipients. |
| Reviewer invitation | No | Invitation token in link | N/A | No | No | Token is required and expiry/hash checked. |
| Reviewer response | No | No | No | No | No | Editorial recipients only. |
| Revision/workflow | No | Authenticated workflow link | No | No | No | Do not add confidential review comments. |
| Support | Do not expose attachment storage URLs | Authenticated ticket access | N/A | N/A | No | Verify attachments in mail sink. |

#### Missing/weak-flow register

| Flow | Current status | Risk | Recommended fix | Priority |
|---|---|---|---|---|
| Magazine/issue update | Missing | Stakeholders receive no change notice | Add explicit event/listener | Medium |
| Editor assignment | Missing | Assignment awareness gap | Add dedicated event/email | Medium |
| Reviewer reminder | Missing | Late reviews | Scheduled reminder with opt-out/expiry | High |
| Ticket closed/resolved | Missing | Requester lacks closure notice | Add status-transition notification | Medium |
| Role/permission changes | Missing | Security/audit gap | Add security alert | Medium |
| Queue failure alert | Missing | Silent delivery degradation | Alert administrators on repeated failures | High |
| Board rather than article-specific link | Partial | Extra navigation / possible wrong context | Use scoped workflow URL | Low |
| External decline identity | Partial | Editorial notice may lack invitee identity | Carry invitee name/email in event payload | High |
