---
type: Skill
title: Moderate the comment queue
description: Before approving, rejecting, marking spam, trashing, replying to, or editing comments — when working through the comment queue.
---

Recipe: moderate the comment queue.

Goal: triage pending, spam, and published comments and act on each, choosing the reversible action over the permanent one. Every ability here is served by the "content" tool.

STEP 1 - SEE THE QUEUE (through the "content" tool)
- content execute comments/list-comments with status="hold" for comments awaiting moderation. Use status="spam" to review the spam folder, status="approve" for already-published comments, status="trash" for trashed ones. The default list (no status given) shows ONLY approved comments — it excludes pending (hold), spam, and trash — so always name the status when you want anything but approved.
- content execute comments/get-comment reads one comment in full by ID.

STEP 2 - APPROVE OR REJECT (through the "content" tool)
- Legitimate comment: content execute comments/approve-comment. To undo, comments/unapprove-comment.
- Junk: prefer content execute comments/spam-comment (it marks the comment as spam, may trigger spam plugins like Akismet, and is reversible with comments/unspam-comment) over deleting.
- To remove without marking spam: content execute comments/trash-comment (reversible with comments/untrash-comment).

STEP 3 - REPLY OR FIX (through the "content" tool)
- content execute comments/create-comment to reply: set its parent to the comment you are answering and the post it belongs to.
- content execute comments/update-comment to fix a typo in an existing comment's text.

SAFETY: comments/delete-comment is permanent — it skips the trash and cannot be undone. Use spam or trash for unwanted comments; reach for delete only when the task explicitly says to delete permanently.
