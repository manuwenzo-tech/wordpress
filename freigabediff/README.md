# FreigabeDiff

FreigabeDiff is a WordPress plugin for reviewing edits to already-published pages. An editor submits revised content for review. A reviewer can compare revisions, request changes with a note, or publish an approved revision. If the live page changes during review, approval pauses until the editor submits a new proposal.

## Try the demo

Download or clone this repository and open [demo/index.html](demo/index.html) in a browser. The standalone demo uses sample data and works without WordPress or a server.

- Click **Play walkthrough** to follow the sample from proposal to publication.
- Click the fee or registration deadline to inspect its previous and proposed values.
- Start a review and select **Simulate a newer live edit** to see the stale-content check.

## PHP implementation

| Area | Implementation |
| --- | --- |
| Proposal | Snapshots the published page content and saves the proposal in a per-site table. WordPress capabilities determine who can submit and review. |
| Review | Uses WordPress's split text diff for HTML and block markup, with escaped snapshots for longer content. Notes and decisions belong to the proposal record. |
| Publish | Locks the page and proposal rows, checks the live snapshot, and commits the post update and proposal decision together. Uses native WordPress revision APIs and respects site revision settings. |

The WordPress review screen shares the demo's visual styling and compares stored source text. The demo's in-page editing markers illustrate a fixed sample page.

## Run the PHP plugin locally

Requires WordPress 6.5+, PHP 8.1+, and MySQL or MariaDB with InnoDB tables. On multisite, activate it per site.

1. Place this repository in `wp-content/plugins/freigabediff` and activate it.
2. In **Pages**, find a published page and choose **Propose a change**. Change its content and submit.
3. Open **FreigabeDiff** in the dashboard. As a user who can publish pages and edit the target page, start review and approve it or request changes.
4. To exercise the conflict path, publish another change using the normal editor before trying to approve a pending proposal.

The plugin proposes page content changes. WordPress continues to manage page titles, fields, and the normal editor. Approval assumes the standard single WordPress database connection and transactional save hooks. Database changes can roll back, while external effects from third-party save hooks, such as outbound webhooks, are outside the database transaction. Proposal history remains in the database when the plugin is deactivated.

## License

GPL-2.0-or-later. The bundled Manrope font uses the SIL Open Font License included with the font.
