Ion Peptide Login Gate 1.7.9

Hybrid gate build. SEO-facing pages render normal HTML and display the same full-screen research access UI overlay without redirect. Cart, checkout, my-account, order-pay, and order-received remain server-side redirect gated. SendGrid verification resend shortcode is included.

1.5.2 - Adds processing/loading state when gate forms are submitted.
1.5.1 - Keeps logout on the previous/current public page instead of falling back to the research-access page.
1.5.3.6 - Adds accessible show/hide password eye buttons to login and registration password fields.

1.7.0 - Rebuilds the gate to match the supplied Ion Peptide desktop/mobile mockups. Adds a full light biotech landing layout, responsive sign-in/register card, feature grid, packaged Ion logo and custom SVG feature/hex assets, packaged lab and molecule artwork, updated login placeholders/actions, mobile feature cards, and restores submit-button markup after processing. Authentication, Google login, email verification, Wordfence 2FA, anti-bot, redirect, and WooCommerce gate logic are unchanged.

1.6.0 - Adds Google Identity Services login to Sign In and Register. Existing customers can sign in with a verified Google email. New Google customers complete Ion's required 21+, research profile, terms, and consent fields before the customer account is created. Privileged WordPress accounts remain on password + Wordfence 2FA. Google email verification satisfies the gate email-verification requirement. Client secret is not required or stored.

Google Cloud requirement: configure https://ionpeptide.com as an Authorized JavaScript origin for the OAuth Web Client ID used by this plugin. Add https://www.ionpeptide.com only if that hostname is actually used.


1.7.1 - Visual refinement pass: enlarges and rebalances the desktop composition, cleans the right-side laboratory/molecule artwork, removes the filename artifact from the molecule image, adds natural full-page scrolling for Register and Google account setup, reduces registration height with paired desktop fields, keeps the desktop hero available without trapping the form, and simplifies mobile registration so it cannot be cut off. Authentication and security behavior are unchanged.

= 1.7.2 =
* Refined right-side hero artwork composition to match the approved mockup and removed visible fallback filename artifacts.
* Reworked login card shadow and removed the heavy backing haze.


= 1.7.3 =
* Rebalanced the desktop hero to mirror the supplied reference layout.
* Restored the feature grid to full reference proportions so the 2x2 cards are wide, spacious, and readable instead of compressed.
* Matched the headline/copy/card/art relationship more closely to the approved composition while preserving the v1.7.2 artwork cleanup and registration behavior.

= 1.7.4 =
* Removes the extra “New Customers - Create an Account” heading from Register.
* Keeps desktop Sign In and Register anchored to the same top position so the hero no longer shifts when switching tabs.
* Restyles Terms/consent rows and makes newsletter signup opt-in instead of preselected.
* Rebuilds the mobile gate to match the supplied phone mockup: brand intro above the card, stable login/register form card, readable 2x2 feature grid, hidden desktop hero artwork, and centered footer.
* Keeps mobile Register as one natural-scrolling column and restores the feature cards below it without compressed desktop widths leaking into the breakpoint.



= 1.7.8 =
* Defaults the optional "Send me newsletters, promotions, and updates" checkbox to checked on both standard registration and Google account setup. Visitors can still uncheck it before submitting, and the existing backend continues to honor the submitted choice.

= 1.7.7 =
* Replaced the header logo with a transparent-background version derived from the supplied Ion Peptide logo.
* Removed the white logo canvas so the header blends seamlessly into the gate background.
* Adjusted desktop and mobile logo dimensions to preserve the same visual logo size after cropping transparent margins.

= 1.7.6 =
* Replaced the header logo with the approved Ion Peptide logo asset and corrected desktop/mobile sizing.

= 1.7.9 =
* Added built-in Registration Blocks controls under Settings > Ion Login Gate > Registration Blocks, using native WordPress admin styling.
* Restricts new signups from blocked domains in the built-in registration form and Google account creation; permits existing account sign-in.
* Supports WordPress/WooCommerce registration validation and a new-account fallback.
* Preserves prior registration block rules under the same option key and includes BrandShield in the initial list.
