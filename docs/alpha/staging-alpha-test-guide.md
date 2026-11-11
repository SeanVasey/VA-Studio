# Staging alpha test guide

For Sean. Written 2026-11-11 for an alpha test by 2026-11-14, done by you alone, from a browser, on a desktop and a phone.

Everything here was checked against the source on `main` (commit `089ccdcf`, plus the unpaid-release and kit-admin changes of #333 and #334), not against a running site. Button names, headings and messages are quoted from the code, so match them exactly. Where the code cannot tell me something, the step says so. If the site disagrees with this guide, the site is the finding: write it down (see [What to write down](#what-to-write-down-when-something-is-wrong)).

The staging site is `https://<staging host>`. It is Stripe **test mode** only. No real money can move.

## What you need before you start

- A desktop browser and a phone, each with its own session. A private window is a separate session. Your cart and your order access do not carry between devices or browsers.
- The staging address and its HTTP basic-auth username and password. Every page asks for them before it loads. They come from the operator, not from this guide.
- A staff account for the admin at `https://<staging host>/admin` (the header and footer also link to it as "Artist admin"). Sign-in uses a TOTP authenticator app. If you have no account or no authenticator yet, ask the operator first.
- Stripe test cards. Use any future expiry, any CVC, any postcode:
  - `4242 4242 4242 4242`: succeeds.
  - `4000 0000 0000 0002`: declined.
  - `4000 0025 0000 3155`: asks for 3D Secure; "Complete" succeeds, "Fail" declines.
- A name and an email address you control for the order forms. The site sends no email from staging (see [Limits](#limits-to-know-before-you-start)), so the address is only stored.
- A place to take notes and screenshots as you go.
- The operator on call for one step you cannot do from the browser: turning on downloads for a **track** order. Give them the order id (see below). Kit orders are operated from the admin ("Test kit orders").

### Do not use these tracks for purchase tests

Three tracks were held by abandoned checkouts on 2026-11-11: Wide Awake, Words I Said and Lion's Den (each from a Basic order). A held order blocks every license of its track, not only the one that was in the cart. They are due to be released once the release path below is switched on; if one of them still refuses an order, release it yourself with Script 5 or use another track.

## Limits to know before you start

1. **An unpaid order keeps its offer reserved.** If you abandon, decline-and-leave, or let a checkout expire, nobody can prepare an order for that same offer again until staff release it (`docs/ops/staging-test-purchase.md`, "An unpaid order keeps its offer reserved"). Treat the whole track as held after any order you do not complete. Staff can release it from the admin: **Test commerce > Test unpaid release**, "Verify unpaid status and release" (Script 5). If you opened Stripe's page, Stripe must show the checkout session expired and unpaid, which happens 60 minutes after checkout was opened; before that the result is "Unpaid status was not established". If you prepared the order but never pressed "Open Stripe test checkout", the page lists it as "Never sent to Stripe" and staff use "Release never-sent order" instead, once the order's 15-minute payment window has closed (before that: "Checkout window is still open"). After that release the order can never be paid. The switch for this page is set per host (`VASEY_TEST_UNPAID_RELEASE_ENABLED`); if the action says it is unavailable, tell the operator.
2. **Do not buy Exclusive unless you mean to.** It is $1,500 and it locks that track's other licenses (the exclusive docs say exclusive and linked non-exclusive offers compete for the same rights scope). You cannot undo it from the browser.
3. **Order access is the browser session.** Guest orders belong to the browser session that placed them (120 minutes idle by default), and downloads work in that same browser only. Do not clear cookies or close a private window mid-test.
4. **Your cart lives in the browser tab.** A new tab starts with an empty cart.
5. **Each deploy takes the site down for about 20 minutes.** If pages stop answering, wait before reporting. Work to shorten this is in progress.
6. **Downloads need a deliberate step per order.** Payment, grant and the contract happen by themselves (a kit contract is issued by a schedule within about 10 minutes of the grant). Enabling downloads is separate. For a **track** order it is a command run by the operator (`vasey:control-test-delivery`); there is no admin button for it. For a **kit** order you do it yourself in the admin: **Test commerce > Test kit orders**, "Enable delivery".
7. **Email.** The store sends none from staging: `MAIL_MAILER=log` and `VASEY_TRANSACTIONAL_MAIL_ENABLED=false` in the staging settings file. The buyer email is also not passed to Stripe Checkout (known follow-up in `docs/ops/staging-test-purchase.md`), so the Stripe page asks for it again. If an email arrives, write it down: it should not.
8. **Quote and payment windows.** The selection review expires about 15 minutes after you press "Review selection", and the order's payment window ends with it. Finish a purchase within about 10 minutes of opening the review.
9. **Rate limits.** At most three download authorizations per minute per track order. A track-order download link lasts 60 seconds and works once.

## 10-minute smoke test

Do this first, on the desktop. Stop and report if a step fails.

1. Open `https://<staging host>` and pass the basic-auth prompt.
   Expect: the home page, the VASEY.AUDIO logo in the header, a "FIND YOUR NEXT RECORD." catalog heading, a "NN TRACKS ON THIS PAGE" count.
2. In "Search tracks" type a word from a track title and press Enter or "Search".
   Expect: the list narrows and the count updates. The small X in the search box ("Clear search") restores the full list.
3. Press the play button on a track's artwork.
   Expect: audio plays and a player bar appears at the bottom with the track title. A short branded tag is audible (see the preview-tag section).
4. Press "Choose license for <track>, from $40" (the price button on the row).
   Expect: a dialog titled "CHOOSE YOUR LICENSE." listing the offers with prices. Close it with the X or Escape.
5. Open the footer. Expect: footer links (including "Artist admin"). Open any legal link that is present and note what it says.
6. Open `https://<staging host>/sound-kits`.
   Expect: heading "Sound kits" and one card, IMPERIAL DRVMZ, $14.99, with "View kit".
7. Open `https://<staging host>/admin` and sign in with your staff account and authenticator code.
   Expect: the admin dashboard and a navigation menu.
8. On your phone, open the staging address and play one track.
   Expect: it plays; the header shows a "Menu" button instead of the full navigation.

If all eight pass, run the scripts below.

## Test scripts

Every step has an expected result. Write down the time, the browser and the exact message for each step that differs.

### Script 1: browse, search and preview playback

1. On the home page scroll the catalog. Count the rows.
   Expect: at most 12 tracks per page; "Next tracks" at the bottom of a full page, then "Previous tracks" and "Back to first results" on later pages.
2. Open the "Sort tracks" menu ("Sort by").
   Expect: three choices: "Newest releases", "Tempo: low to high", "Title: A–Z". The list reorders after you pick one.
3. Press a genre button (the row starts with "All sounds").
   Expect: the button shows as selected and the list is filtered. Genre buttons only list genres on the current page. Search covers the whole catalog (search matches title, artist, genre, mood, key and tags; at most 100 characters).
4. Search for a word that matches nothing.
   Expect: "NO MATCHES. KEEP EXPLORING." and a "Reset filters" button.
5. Open a track by its title link.
   Expect: a track page at `/tracks/<slug>` with cover art, Tempo, Key, Preview length, "Play preview", "View licenses" and a share button. Each offer shows its price and "Read terms & choose".
6. Press the share button.
   Expect: a notice "Link copied for <track>." (or "The link could not be copied..." with an "Open track link" link).
7. Press "Play preview", then seek with the "Seek preview" slider, then change "Preview volume".
   Expect: playback follows each control. The preview is full length, not a clip.
8. Press "Queue & loop". Try "Play next automatically", "Repeat" (Off, This track, Queue), "Playback speed", then "Set A here", move the seek slider, "Set B here", and "Clear loop".
   Expect: the panel opens and lists tracks, each with "Move <track> earlier", "Move <track> later" and "Remove <track> from queue" buttons. The loop repeats the A to B section while "Loop on" shows. Note anything that does not respond.
9. Press Play on a second track while the first plays.
   Expect: the first stops and the second starts, so only one track plays at a time.

#### Seller tag on previews

Public previews are tagged by default: the seller tag is mixed into the preview at the start and repeats on a fixed interval, and the preview runs the full length of the track. The code default for the interval is 30 seconds (`config/media.php`); the approved tag is a 4-second cut that Sean chose (`docs/ops/staging-placeholders.md`, P-1). I could not read the staging host's actual configuration.

10. Play a track from 0:00 and listen through at least one minute.
    Expect: the tag is audible at the start and again about every 30 seconds. Write down the actual interval you hear.
11. Check the page text on a track page.
    Expect: "Listen to the tagged preview. Your license determines the delivered files." while the track serves the tagged preview, and "Listen to the preview. Your license determines the delivered files." when the track is set to untagged (steps 14 and 15 below). The line follows the server's setting, but still confirm the tag by ear.

#### Per-track preview-tag setting (admin)

This changes what the public hears at once, on a live track. Do it on one track, then put it back.

12. In the admin open **Preview tag** (the page at `/admin/preview-tag`, titled "Preview tag (store default)").
    Expect: a checkbox "Public previews carry the seller tag (store default)", a "Save store default" button, and a line "N of M tracks with a preview have a recorded untagged variant." Do not change the store default. Note the numbers: tracks without an untagged variant cannot be switched off.
13. Open **Tracks** and find the "Public preview" column.
    Expect: each row says what the public currently hears.
14. On one track press the **Preview tag** row action, choose "Off (untagged...)" and submit.
    Expect: either the notice "Preview tag saved", or "Preview tag not changed" with a reason when that track has no untagged variant. Both are valid outcomes; record which one you got and the reason text.
15. If it saved, reload the public track page and play the preview.
    Expect: no tag. A browser that already fetched the file may keep it for up to 5 minutes; use a hard reload or a private window.
16. Set that track's **Preview tag** back to "Default (store default is tagged)" and submit.
    Expect: "Preview tag saved", and the tag is audible again.

### Script 2: buy a Basic license (card succeeds)

Use a track that is not in the held list above.

1. On a track row press the price button, choose "Basic" in the dialog, press "Read full terms" to expand the license, then press "Add license · $40".
   Expect: the dialog closes with a notice "Basic for <track> added to your cart." The header "Cart" button shows `01`.
2. Press "Cart".
   Expect: a dialog "YOUR SELECTIONS." with "CART / 1 TRACK", the track, "Change license" and "Remove" links, an "Estimated subtotal" of $40 and a "Review selection" button.
3. Press "Review selection".
   Expect: "SELECTION REVIEWED", the license terms, "Reviewed subtotal", and "Review expires at <time>".
4. In "TEST ORDER PREPARATION" press "Review test order".
   Expect: seller name, the full license text, a price line with zero test tax, "Test total" of $40, and a form: "Legal name", "Email address", and an assent checkbox.
5. Fill in the name and email, tick the checkbox, press "Prepare test order".
   Expect: "TEST ORDER PREPARED" with "Order <uuid>". **Copy the order id.** A "STRIPE TEST CHECKOUT" panel appears with an "Open Stripe test checkout" button.
6. Press "Open Stripe test checkout", then "Continue to Stripe test checkout".
   Expect: Stripe's hosted page, product "VASEY.AUDIO test license 1", amount $40. The page may show other payment options besides card and Link (bank and Klarna were seen on 2026-11-11); note what you see. If it offers "Save my information with Link", untick it.
7. Pay with `4242 4242 4242 4242`.
   Expect: Stripe returns you to the store at `/orders/<order id>/checkout/return`, headed "CHECKOUT STATUS". The order id is in the address bar; the page does not print it.
8. Watch the page without clicking.
   Expect, in order: "Stripe reports that its test checkout session is complete. Payment has not been verified and fulfillment has not started." (it may be skipped), then "Test payment verified. Order finalization is pending. Contracts and download access are not available yet.", then "Test payment verified and order finalized. Contracts are pending; download access is not available yet.", then "Test payment verified and order finalized. Test contracts have been issued. Check the separate test downloads panel for current availability." While it waits the page says "Checking for updates automatically. This page refreshes about every 5 seconds for up to 3 minutes." If it stops ("Automatic checking has stopped..."), press "Check Stripe test checkout status" (before payment is verified) or "Refresh test order status" (after).
9. Look for the downloads panel, "TEST ORDER DOWNLOADS". It appears once the contract is issued.
   Expect: "Downloads are not available for this test order currently." until the operator enables delivery.
10. Send the operator the order id and ask them to enable delivery. Then press "Refresh downloads and recent attempts".
    Expect: one row per file: "Original contract" plus the files the license dialog listed for Basic (they are named "MP3", "WAV master" or "Stems ZIP"; compare), each with a "Download <name>" button and a size in bytes.
11. Press "Download Original contract", then each file button.
    Expect: the browser saves each file. The button then reads "Request another <name>" and a notice says a download attempt was submitted. "RECENT DOWNLOAD ATTEMPTS" lists each as "Stream attempted". Open the PDF and check it names the licensed track, your legal name and the license; play the MP3.
12. Press one download button four times in a row, quickly.
    Expect: refusals after the third within a minute (three authorizations per minute per order). Record the message text.
13. Optional: in the Stripe Dashboard (test mode) open Payments.
    Expect: a succeeded payment for $40 matching your order.

### Script 3: buy a higher tier with stems

Only 32 tracks have stems. Premium and Basic are on every track; Premium Plus ($100), Unlimited ($500) and Exclusive ($1,500) appear only on tracks with stems. Do not choose Exclusive.

1. Find a track whose dialog offers "Premium Plus" or "Unlimited". Pick Premium Plus.
   Expect: the offer line lists the files. Write down which files each tier lists; the tiers that carry stems should include "Stems ZIP".
2. Repeat Script 2 steps 1 to 11 with this offer.
   Expect: the same flow. The downloads list now includes "Stems ZIP" (and "WAV master" if the license lists it). The set of files should equal the set named in the dialog plus "Original contract".
3. Download the stems ZIP and open it.
   Expect: it opens and holds the stems. Write down the file count and total size.
4. Optional: put two tracks in the cart (the cart holds up to 10) and prepare one order for both.
   Expect: one order, two lines, a combined total, and a downloads list with files for both tracks.

### Script 4: declined card

Use a fresh track.

1. Go through Script 2 steps 1 to 6, then pay with `4000 0000 0000 0002`.
   Expect: Stripe shows "Your card was declined" and stays on its page. You are not sent back to the store. The store never shows verified payment.
2. Without leaving Stripe's page, pay with `4242 4242 4242 4242` within the payment window.
   Expect: the normal chain completes, as in Script 2 steps 7 to 9.
3. Optional, on another fresh track: after the decline, close the Stripe tab and do not pay. Open the cart: the "PREVIOUS TEST ORDERS" panel has "Browse test orders". Use it to open the order ("View test order status <id>").
   Expect: nothing is shown as verified. This track is now reserved; do not reuse it.

### Script 5: abandon a checkout

Use a fresh track. This deliberately burns that track.

1. Go through Script 2 steps 1 to 6 and stop on Stripe's page. Close the tab.
2. Return to the store, open "Cart" and "Browse test orders", open the order.
   Expect: the "STRIPE TEST CHECKOUT" panel says "Stripe test checkout is available. Opening it does not verify payment or issue rights." with a "Continue to Stripe test checkout" link. After the quote window it says "This checkout link has expired. Check the current Stripe test checkout status."
3. Press "Check Stripe test checkout status".
   Expect: no payment is shown as verified. After Stripe expires the session (60 minutes) it reads "The Stripe test checkout session has expired. No payment or fulfillment is confirmed here."
4. Try to buy any license on that track again.
   Expect: it fails. The message you will see in the order step is "The reviewed terms or availability changed. Load a current review and explicitly accept its terms before preparing an order." (the review step may instead say "Your selection changed or is no longer available. Refresh the catalog and choose its licenses again."). Other tracks still work. Write down which message you got.
5. Release it. Wait until 60 minutes after you opened Stripe's page, then in the admin open **Test commerce > Test unpaid release**, find the order, and press "Verify unpaid status and release".
   Expect: "Test resources released". If you are early, the result is "Unpaid status was not established": close the dialog, wait, and start a new check. "Release evidence needs attention" means Stripe's answer did not match the order; do not retry, write it down.
6. Buy a license on that track again.
   Expect: the order can now be prepared.
7. Optional, on another fresh track: prepare an order (Script 2 up to "Prepare test order") and do not press "Open Stripe test checkout". Wait 15 minutes, then in the admin find the order: its "Checkout" column reads "Never sent to Stripe". Press "Release never-sent order".
   Expect: "Test resources released". Earlier, "Checkout window is still open". The order's page then says the checkout has expired, and the track can be bought again.

### Script 6: buy the sound kit

The kit page and its order page do not use the track cart. A kit is bought alone, on its own page.

1. Open `/sound-kits` and press "View kit" on IMPERIAL DRVMZ (or follow the "Sound kits" header link if it is there).
   Expect: the kit page with cover art, "N samples", a summary, a "Demo" section with "Play demo", and "Details" (Samples, Total length, Price $14.99). Below, "Buy this kit (test mode)".
2. Press "Play demo".
   Expect: the demo plays in the same bottom player. The line under it says the demo is "A tagged preview of sounds from this kit." Check that the tag is audible. (The per-track preview-tag setting does not apply to kit demos.)
3. Press "Buy with Stripe test checkout" with the name empty.
   Expect: "Enter the licensee's legal name."
4. Enter a "Licensee legal name" and press "Buy with Stripe test checkout".
   Expect: the button reads "Starting test checkout…", then Stripe opens with a line named "VASEY.AUDIO sound kit (test): IMPERIAL DRVMZ", $14.99.
5. Pay with `4242 4242 4242 4242`.
   Expect: Stripe returns you to `/sound-kit-orders/<order id>` (copy the id from the address bar). The page shows "Test order · Stripe test mode", the kit title and $14.99. Right after Stripe returns you, this page does not refresh itself (see the next step).
6. If it says "A Stripe test checkout was started. Returning here does not confirm payment: check the payment status below.", press "Check payment status" or reload.
   Expect, in order: "Test payment verified. The kit is being granted.", then "Test payment verified and the kit granted. Your license contract is being prepared; downloads open after it is issued." Once the order is paid, the page refreshes itself about every 10 seconds for up to 15 minutes and announces the change, with a line "Checking for updates automatically." If that line says checking has stopped (after 15 minutes, or because a read failed), reload the page. The "Check payment status" button is only shown up to the granting step.
7. In the admin open **Test commerce > Test kit orders** and find the order by its id. Its status reads "Granted, contract pending". Either wait (a schedule issues the contract within about 10 minutes) or press "Issue license contract" on the row; it takes a few seconds.
   Expect: the row's status becomes "Contract issued, delivery not enabled". On the order page (it updates itself while it is still within its 15 minutes, otherwise reload): "Test payment verified, kit granted and license issued. Test downloads open once delivery is enabled for this order."
8. On the same row press "Enable delivery", enter a reference (for example `ALPHA-KIT-1`) and confirm. Reload the order page.
   If the dialog says "Another change was recorded first", close it and open it again.
   Expect: "Test payment verified. Your kit and license are ready. Each download link is single-use and lasts one minute." and two buttons: "Download the kit (ZIP)" and "Download the license (PDF)".
9. Press both buttons.
   Expect: a ZIP and a PDF save. Open the ZIP: it holds the kit's samples. Compare the number of samples to the "N samples" on the kit page and write down any difference. Open the PDF: the code fills in the kit name, your licensee name, the licensor name, the purchase date, the fee and the order reference; check each one.
10. Press "Download the kit (ZIP)" again.
    Expect: a fresh download starts (every click mints a new one-minute, single-use link).
11. Optional: buy it a second time, and for a variant abandon the Stripe page.
    Expect: no reservation. The kit code takes no inventory reservation, so an abandoned kit checkout should not block the next one (`docs/sound-kit-storefront.md`); I found no purchase limit in the code, but did not run it. An order not started within 15 minutes shows "This order expired before its checkout started. Start a new purchase from the kit page."

### Script 7: admin tasks available today

Labels below are the navigation labels the code generates. Which entries you see depends on your role and the host's switches; if an entry is missing, write down its name. Look first; change only what a step says.

1. **Tracks** (`/admin/tracks`). Row actions: "Review track", "Edit", "Check readiness", "Publish", "Publish unlisted", "Schedule" or "Reschedule", "Cancel schedule", "Change visibility", "Preview tag", "Share", "Return to draft" (which of them show depends on the track's state). Bulk: "Add tags", "Edit metadata". Press "Check readiness" on a published track.
   Expect: a notice "Ready to publish" or "Publication blocked" listing blockers. Do not press "Publish" or "Return to draft" on live tracks.
2. **Offers** (`/admin/offers`): columns Draft price, Published price, Revision, Active; actions "Edit draft", "Publish revision", "Deactivate", "History". Open "History" on one offer.
   Expect: the published revisions with prices ($40 and $75 on every track).
3. **License Templates** and **License Versions**: open "Preview" on a version.
   Expect: the license text with its version. Actions such as "New revision", "Request review" and publish change licenses; leave them.
4. **Rights Declarations**: each row has a status, and pending ones have "Edit" and "Verify".
   Expect: a status per track. Rights for a purchase also need a scope link that only the operator console can create (`vasey:rights-scope`), so the browser cannot make a new track purchasable.
5. **Media Assets**: "Review preview", "Processing details", "Process / retry".
   Expect: "Review preview" opens the stored preview. "Process / retry" reprocesses media; leave it.
6. **Preview tag** page and the per-track **Preview tag** action: see Script 1, steps 12 to 16.
7. **Sound kit drafts** (no group) and **Sound kit products** (group Publishing). Product actions: "Cover art", "Approve cover", "Upload demo", "Retry demo", "Approve demo", "Clear rights", "Revoke rights", "Approve offer", "Publish", "Unpublish". Open IMPERIAL DRVMZ.
   Expect: price $14.99, and Cover art, Demo audio and offer approval shown as approved. Do not press "Unpublish" unless you want the kit offline.
8. **Site images** and **Site content** (group Publishing): look only.
9. Group **Test commerce**. After your Script 2 order has finished:
   - **Test Orders And Refunds**: find the order by its id; "Order evidence" opens the order's evidence. "Check refunds and disputes" reads Stripe and records; refunds themselves are issued in the Stripe test dashboard, not here.
   - **Test Contract Issuance**: your grant shows a recorded status and "Original recorded (UTC)".
   - **Test Payment Exceptions**: empty unless a payment arrived after the window.
   - **Test Customers**: your guest order appears as "Guest (no account)".
   - **Test Unpaid Release**: lists orders, with a "Checkout" column ("Checkout started" or "Never sent to Stripe"); "Verify unpaid status and release" frees the track held by an abandoned order once Stripe shows its session expired and unpaid (Limit 1, Script 5), and "Release never-sent order" frees one that never reached Stripe once its payment window has closed (Script 5, step 7). "Release history" shows what was recorded. It refuses a paid order.
   - **Test kit orders**: one row per kit order with its status ("Order started", "Awaiting payment", "Paid, grant pending", "Granted, contract pending", "Contract issued, delivery not enabled", "Delivery enabled", "Delivery blocked"). Row actions: "Re-check payment", "Issue license contract", "Enable delivery", "Block delivery" (Script 6).
   - **Order-ready Emails** and **Test promotions** exist; no mail is sent.
10. **Reporting**: **Sales report** and **Customer segments**.
    Expect: figures that include your test orders. Treat them as test data.
11. **Held Paid Orders** (Fulfillment) and **Customer Inquiries**: look only.

Not available in the admin today: enabling **track** downloads, linking rights scopes and issuing refunds. Each is an operator command or a Stripe dashboard action.

### Script 8: phone and web-app checks

Do these on a real phone. Admin on a phone has only been tested in emulation (`docs/remaining-parity-coverage.csv`, FP-041), so any problem there is new information.

1. Open the staging address on the phone and pass basic auth.
   Expect: the layout fits the screen with no sideways scrolling; the header shows "Menu".
2. Press "Menu".
   Expect: the navigation opens and the button reads "Close menu". Escape closes it on a keyboard.
3. Play a track, then lock the phone.
   Expect: lock-screen controls with the title and artist, and play and pause work. The code sets these (`resources/js/lib/media-session.ts`); no real-phone test has been recorded.
4. Run Script 2 on the phone from the cart to the Stripe page, using the phone's own browser.
   Expect: the dialogs scroll and every button can be tapped. Stripe returns to the same browser tab.
5. Scroll to the footer and open "Add VASEY.AUDIO to your device".
   Expect: instructions for iPhone ("Share, then Add to Home Screen"), Mac Safari and other browsers. On Android Chrome an "Install VASEY.AUDIO" button may appear.
6. Add the site to your home screen and open it from there.
   Expect: it opens full screen (the manifest sets `display: standalone`) with the name VASEY.AUDIO. Write down how the icon looks. The full icon set and the official mark are not done yet (`CLAUDE.md`, Standard adoption gaps), so a plain or wrongly sized icon is known.
7. With the site loaded, turn on airplane mode and reload the home page.
   Expect: a static offline page. The offline worker installs by fetching files without credentials, and staging sits behind basic auth, so it may fail to install here. I could not verify either way. A missing offline page on staging is therefore a note, not a defect.
8. In the phone's admin, sign in and open Tracks.
   Expect: the table is usable; actions open as dialogs. Report anything cut off.

### Script 9: accessibility quick checks

On the desktop, with the mouse put away.

1. Reload the home page and press Tab once.
   Expect: a "Skip to content" link appears, and Enter jumps to the main content.
2. Keep pressing Tab through the header, the catalog and a track row.
   Expect: a visible focus outline (a 3px ring) on every control, in a sensible order, with no traps. The play button, the share button and the license price button are all reachable.
3. Press Enter on the license price button.
   Expect: the dialog opens, focus is inside it, Tab stays inside it, Escape closes it, and focus returns to the button.
4. Open the cart and run Script 2 steps 3 to 5 with the keyboard only.
   Expect: every button and field works; the assent checkbox is a real checkbox.
5. Use the player with the keyboard: Tab to "Seek preview" and press the arrow keys.
   Expect: the position moves; the slider announces the time.
6. Turn on "reduce motion" in your operating system and reload.
   Expect: no animations or smooth scrolling (the stylesheet turns them off under `prefers-reduced-motion`).
7. Zoom the browser to 200 percent, and again at 400 percent.
   Expect: the layout reflows with no hidden controls. Write down anything that overlaps.
8. Optional with a screen reader: open the catalog, the license dialog and the checkout status page.
   Expect: dialogs are announced with their title; status messages ("Checking for updates automatically...") are announced when they change.

## What to write down when something is wrong

For every problem:

- **The order id.** For a track order it is in the address bar of the checkout-return page (`/orders/<id>/checkout/return`) and printed as "Order <id>" in the cart. For a kit order it is in the address bar of `/sound-kit-orders/<id>`. No order id means no order existed; say so.
- **The time**, with your time zone, to the minute. The operator reads logs by time.
- **The browser and device**, including version if you can see it, and whether it was a private window.
- **The page address** you were on, and the track or kit name.
- **A screenshot** that shows the whole message, and the exact text of any message. Copy red or yellow notices word for word.
- **What you did just before**, including whether you reloaded or went back.
- **What you expected** and what you saw instead.

Do not try to fix a stuck order by buying again. Report it and move to a different track.

## What is not built yet

Short list, from `docs/remaining-parity-coverage.csv` and `docs/sound-kit-storefront.md`. Each of these is expected to be missing on staging.

- No live payments. Tax is a fixed test value of zero. Cards through Stripe only (no PayPal or cryptocurrency).
- Refunds are issued in the Stripe test dashboard. For track orders the admin only observes and records them; kit orders ignore refunds and disputes, so block delivery by hand ("Block delivery" on the kit order's row).
- No email: no receipt, no "order ready" message, no marketing email.
- No self-service release of an abandoned checkout, and no admin button for enabling downloads or issuing the kit contract.
- Memberships and subscriptions are not sold. Merchandise and services storefronts, license upgrades, negotiated offers, bundles and deals, affiliate and abandoned-cart tools, and collaborator payouts are post-launch.
- Kit orders do not appear in a customer library, order history or data export, and kit downloads cannot resume if interrupted.
- The production customer account path, final privacy and terms text, and the official icon set are open items. Staging legal pages, if published, are stand-ins that the runbook says open with "STAGING PLACEHOLDER — not in effect"; I could not confirm they are published.
