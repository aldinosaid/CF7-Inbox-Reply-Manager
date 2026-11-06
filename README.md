# CF7 Inbox & Reply Manager

**CF7 Inbox & Reply Manager** is a WordPress plugin that captures Contact Form 7 submissions into a dedicated database inbox, enables direct admin replies, and integrates custom SMTP configuration.

- **Author:** Aldino Said
- **Folder Slug:** `cf7-inbox-reply-manager`
- **Main File:** `cf7-inbox-reply-manager.php`
- **Prefix:** `cir_` (Functions, variables, options, tables)

---

## Features
1. **Custom Database Table**:
   - Stores submissions into `wp_cir_messages` (`id`, `form_id`, `sender_name`, `sender_email`, `message`, `created_at`).
   - Automatically created upon plugin activation via `dbDelta`.
2. **Contact Form 7 Interception**:
   - Hooks into `wpcf7_before_send_mail` to capture form submissions.
   - Intelligently detects sender name, sender email, and message body with multi-field fallback.
3. **WP_List_Table Admin Inbox**:
   - Located under **Contact > CF7 Inbox**.
   - Searchable, sortable, paginated, and supports bulk delete.
4. **Direct Reply Feature**:
   - One-click reply link per message.
   - Dedicated reply compose view with original message context.
   - Sends formatted HTML email to the sender.
5. **SMTP Settings Sub-menu**:
   - Located under **Contact > Inbox SMTP Settings**.
   - Configurable Host, Port, Encryption (TLS/SSL/None), Authentication, Username, Password, and From details.
   - Hooks into `phpmailer_init` to route reply emails through your SMTP server.
6. **Automatic GitHub Updates**:
   - Connects to GitHub Releases API (`aldinosaid/cf7-inbox-reply-manager`).
   - Hooks into standard WordPress transient (`pre_set_site_transient_update_plugins`).
   - Supports 1-click update in the WordPress Plugins screen and View Version Details modal popup.
   - Correctly restores the extracted folder slug to prevent deactivation.

---

## Directory Structure
```
cf7-inbox-reply-manager/
├── cf7-inbox-reply-manager.php         # Main bootstrap & autoloader
├── README.md                           # Documentation
└── includes/
    ├── class-cir-db.php                # Database activation and CRUD (wp_cir_messages)
    ├── class-cir-cf7-capture.php       # Hook for wpcf7_before_send_mail
    ├── class-cir-mailer.php            # SMTP handling & phpmailer_init hook
    ├── class-cir-admin-ui.php          # Admin menus, reply UI, and settings API
    ├── class-cir-github-updater.php    # GitHub Releases updater & version checker
    └── class-cir-message-list-table.php# WP_List_Table implementation
```

