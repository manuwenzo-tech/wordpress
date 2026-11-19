# ReparaturPass

ReparaturPass gives bike workshops and repair cafés a WordPress dashboard for repair intake and a QR-linked progress page for customers. Try the [animated design preview](https://reparaturpass-design-preview.bloomybracelet17.chatgpt.site).

## Install

Requires WordPress 6.5+, PHP 8.1+, and HTTPS for a live installation. For WordPress Multisite, activate it per site.

1. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
2. Upload `reparaturpass.zip` and activate it.
3. Open **ReparaturPass** in the dashboard. Administrators can use it immediately and can assign the **Repair staff** role to workshop staff.
4. Create a repair. Open its customer pass or print its QR code from the repair page.

To build the installable ZIP from a Git checkout, run `git archive --format=zip --prefix=reparaturpass/ -o reparaturpass.zip HEAD` from the repository root.

## Two-minute demo

1. Add **City bike Velo 7** with the issue **Rear brake is rubbing**. Enter a private workshop note such as **Replacement cable in bin B3**.
2. Open the customer pass in a private browser window. It shows **Received** without requiring a WordPress account.
3. In the workshop dashboard, advance to **In repair**. Refresh the customer pass to see the step update.
4. Add a customer-facing summary and advance to **Ready for pickup**. Refresh the pass to see the summary.
5. Set **Picked up**. Replace the customer link and verify that the older pass is unavailable.

## How it works

- The plugin uses a dedicated, per-site WordPress database table installed with `dbDelta()`.
- Workshop writes use WordPress capabilities and nonce checks. Advancing compares the submitted stage with the persisted stage in a single database update, preventing duplicate submissions from advancing two stages.
- Customer URLs keep the access key in the URL fragment. The browser sends it in the JSON body of a REST POST. The server checks it against a per-record secret and sends an explicit allowlist of customer fields, with caching disabled.
- Anyone holding the QR link can view the pass. Staff can replace the link to revoke the old one. Changing the WordPress authentication salt also invalidates existing links. Keep names, contact details, and other private information out of the customer-facing summary.
- The QR image is generated in the workshop browser with a bundled library. The pass page uses the approved pale-mint design and respects the browser's reduced-motion preference.
- Repairs remain in the database after plugin deactivation or deletion. Remove the plugin table explicitly if you intend to erase those records.

## License

ReparaturPass is GPL-2.0-or-later. The bundled QR Code Generator for JavaScript is MIT-licensed. See `assets/qrcode.LICENSE.txt`.
