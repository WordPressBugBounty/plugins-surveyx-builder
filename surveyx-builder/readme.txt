=== SurveyX Builder - Survey, Poll, Quiz & Feedback Form ===
Contributors: themeruby
Tags: survey, poll, quiz, questionnaire, feedback
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

WordPress survey plugin for surveys, polls, quizzes and feedback forms. Unlimited responses, stored in your own database. No code, no monthly fee.

== Description ==

[SurveyX Builder](https://surveyx.co/) is a WordPress survey plugin for **surveys, polls, quizzes and feedback forms**. You build the survey in a visual editor, publish it, and every answer lands in your own WordPress database.

No external survey service sits in the middle. That is why responses are unlimited on every plan, why there is no per-response pricing, and why nothing you collect ends up behind someone else's subscription.

**What the free version does.** Unlimited surveys, unlimited responses, 7 question types, 6 themes, an analytics dashboard and spam protection. Free surveys hold **one question each**, which is the right shape for polls, quick votes and a single feedback prompt. [Pro](https://surveyx.co/pricing/) lifts that limit and adds skip logic, 6 more question types and the integrations.

= 🆕 New in 2.0: every survey gets its own page =

Activate a survey and it is live at `/survey/123/your-survey-name/`. Paste that link into an email, a newsletter, a social post or a QR code. There is no page to build and no shortcode to embed.

* Survey pages carry Open Graph and Twitter card tags, so a shared link unfurls with a title, description and preview image
* The URL base (the "survey" part of the address) is configurable in Settings
* Standalone pages can be switched off site-wide if you only ever embed with the shortcode
* Free survey pages carry `noindex`, so they are for sharing a link rather than for ranking in search. [Pro](https://surveyx.co/pricing/) adds a per-survey indexing switch

= 🎯 What you get =

* **Unlimited surveys and unlimited responses**: no caps, no metering, no per-response fee
* **Your data stays on your site**: responses live in your WordPress database, not someone else's cloud
* **Two ways to publish**: the survey's own shareable page, or the `[surveyx id="X"]` shortcode
* **Lightweight**: the public survey loads one small bundle, and only on pages that show a survey
* **Works everywhere**: any properly coded theme, any page builder, responsive on a phone

= ✨ Core Features =

* **7 question types**: multiple choice, multi-select, dropdown, text input, yes/no, image choice, multi-image choice
* **Shareable survey pages**: `/survey/{id}/{slug}/` with social preview tags
* **6 themes**: finished designs, ready to use as they are
* **Analytics dashboard**: responses, completion rate and drop-off at a glance
* **Vote results for respondents**: switch on "view votes in results" for a Quick Vote and voters can open the tally, their own vote already counted
* **Google reCAPTCHA v2**: built-in spam protection
* **Shortcode embedding**: `[surveyx id="X"]` in any post, page or widget
* **Revision history**: autosave, with restore to any recent version
* **Template library**: import a ready-made survey and edit it
* **Turn the credit off**: the "Powered by SurveyX" line has a switch, in the free version too
* **Translation ready**: works with WPML and other translation plugins

= 🚀 SurveyX Pro Features =

Pro is the same plugin with the limits lifted:

* **Multiple Questions [Pro]**: Unlimited questions per survey, with page-by-page navigation
* **13 Question Types [Pro]**: Adds rating, opinion scale (0 to 10, for NPS), matrix / Likert grid, date, file upload and contact info
* **Skip Logic & Branching [Pro]**: Send respondents down different paths based on their answers
* **Answer Recall [Pro]**: Pull an earlier answer into a later question, so a form reads like a conversation
* **Save & Resume [Pro]**: A part-finished survey picks up where the respondent left off
* **Contact Info Question [Pro]**: Builds a respondent profile with name, email, phone and company
* **Email Notifications [Pro]**: One address is alerted as soon as someone completes a survey
* **Mailchimp Integration [Pro]**: Sync respondents with your email list
* **Webhooks & Zapier [Pro]**: Post every completed response to any URL, HMAC-signed
* **Google Sheets [Pro]**: Append each completed response to a sheet as a new row
* **CSV Export [Pro]**: Download the summary and every individual response
* **Survey Import & Export [Pro]**: Move whole surveys between sites as JSON
* **Advanced Analytics [Pro]**: Per-question drop-off, every individual response, and all surveys in one table
* **22 Themes [Pro]**: 16 more designs on top of the 6 included free
* **Custom Themes [Pro]**: Build your own with colours, buttons, containers and typography
* **Google Fonts [Pro]**: 1,000+ families for the survey's typography
* **Custom Backgrounds [Pro]**: A solid colour, a gradient or an image behind the survey
* **Your Own Branding [Pro]**: Replace the SurveyX credit with your logo, link and wording
* **Gutenberg & Elementor Blocks [Pro]**: Native page builder integration
* **Google reCAPTCHA v3 [Pro]**: Invisible spam protection, with no challenge for real people
* **Cloudflare Turnstile [Pro]**: A privacy-friendly alternative to reCAPTCHA
* **Survey Page Options [Pro]**: Per-survey search indexing, and a redirect after someone submits
* **Dedicated Support [Pro]**: Priority help through our [ticket system](https://ruby.ticksy.com/)

**[Get SurveyX Builder Pro →](https://surveyx.co/pricing/)**

= 📚 Template Library =

📋 **250+ ready-to-use templates**: customer feedback, quizzes, HR surveys, event forms, market research and more. Browse the [Template Library](https://surveyx.co/templates/), open one, and change the questions to yours.

  * Customer Feedback & Satisfaction Surveys
  * Knowledge Quizzes & Personality Tests
  * Human Resources & Talent Identification
  * Event Planning & Registration Forms
  * Market Research & Sales Optimization
  * Education, Health & Lifestyle Surveys

= 🔗 Useful Links =

* [Documentation](https://surveyx.co/docs/)
* [Pro Features & Pricing](https://surveyx.co/pricing/)
* [Template Library](https://surveyx.co/templates/)
* [Support Forum](https://wordpress.org/support/plugin/surveyx-builder/)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/surveyx-builder/`, or install it through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to SurveyX in the admin menu and create your first survey.
4. Activate the survey, then either share its page link or embed it with `[surveyx id="X"]`.

== External Services ==

This plugin connects to the following external services:

**Google reCAPTCHA**: Spam protection for survey submissions. [Service](https://www.google.com/recaptcha/)

**Cloudflare Turnstile [Pro]**: Alternative CAPTCHA solution. [Service](https://www.cloudflare.com/products/turnstile/)

**SurveyX.co Template Library**: Pre-built survey templates (no personal data transmitted). [Service](https://surveyx.co/) | [Privacy Policy](https://surveyx.co/privacy/) | [Terms](https://surveyx.co/terms/)

== Screenshots ==

1. Image choice question as respondents see it
2. Multiple choice question with a custom theme
3. Rating question (Pro) on the survey page
4. The survey editor: questions, answers and live preview

== Frequently Asked Questions ==

= How do I add a survey to my site? =

Two ways. Activate the survey and share its own page link (`/survey/123/your-survey/`), or embed it in any post, page or page builder with the shortcode `[surveyx id="X"]`, where X is the survey ID shown in the survey list.

= Can I share a survey without building a page for it? =

Yes. Since version 2.0 every active survey has its own page at `/survey/{id}/{slug}/`. Copy the link from Survey > Share and send it out. Nothing else to set up.

= Is SurveyX Builder really free? =

Yes. The free version creates unlimited surveys and collects unlimited responses, with no metering and no per-response fees. A [Pro version](https://surveyx.co/pricing/) adds multi-question surveys, skip logic, more question types, integrations and CSV export.

= What question types are supported? =

The free version includes 7: multiple choice, multi-select, dropdown, text input, yes/no, image choice and multi-image choice. [Pro](https://surveyx.co/pricing/) adds 6 more for 13 in total: rating, opinion scale, matrix / Likert grid, date, file upload and contact info.

= How many questions can one survey have? =

The free version builds single-question surveys, which covers polls, quick votes and one-question feedback prompts. [Pro](https://surveyx.co/pricing/) removes the limit and adds page-by-page navigation and skip logic.

= Where are the responses stored, and what about GDPR? =

In your own WordPress database, on your own hosting. Nothing is sent to an external survey service, so there is no third-party processor to account for and no export to request back. What you then do with that data is still your own responsibility.

= Can I customize the survey design? =

Yes. The free version ships 6 themes. [Pro](https://surveyx.co/pricing/) brings the total to 22, plus custom themes, Google Fonts, background images and your own logo.

= Does it work with my theme and page builder? =

Yes. Surveys are responsive and work with any properly coded theme. Use the shortcode in any page builder; [Pro](https://surveyx.co/pricing/) also includes a native Gutenberg block and Elementor widget.

= Will it slow down my site? =

No. The survey bundle is small and self-contained, and it only loads on pages that actually show a survey.

= How do I prevent spam submissions? =

The free version includes Google reCAPTCHA. [Pro](https://surveyx.co/pricing/) adds reCAPTCHA v3 and Cloudflare Turnstile for invisible, privacy-friendly protection.

= Can I export survey results? =

The dashboard shows responses, completion rate and drop-off. [Pro](https://surveyx.co/pricing/) adds CSV export of both the summary and every individual response.

= Does it support multiple languages? =

Yes. SurveyX Builder is translation ready and works with WPML and other translation plugins.

= Is this an alternative to Typeform, Google Forms or SurveyMonkey? =

It is the WordPress-native way to do the same job. The survey runs on your own domain, wears your own theme, and the answers sit in your WordPress database instead of an account somewhere else. What you give up is the hosted part: the hosting, the backups and the uptime are yours to run.

= Can I run an NPS survey? =

Yes, with [Pro](https://surveyx.co/pricing/). The 0 to 10 opinion scale is one of the six question types Pro adds. Pair it with an open text question and one form gives you the score and the reason behind it.

= Can I import and export my surveys? =

Survey import and export is a [Pro](https://surveyx.co/pricing/) feature, as JSON, for moving a survey between sites. The free version can import a ready-made survey from the template library.

= Where can I get support? =

Use the [WordPress support forum](https://wordpress.org/support/plugin/surveyx-builder/) or the [documentation](https://surveyx.co/docs/). [Pro users](https://surveyx.co/pricing/) get priority support through our [ticket system](https://ruby.ticksy.com/).

= How can I become a contributor? =

Visit our [GitHub Repository](https://github.com/ThemeRuby/surveyx-builder) to contribute.

== Changelog ==

= 2.0.1 =
* Changed: Vote results refresh every 15 minutes rather than on every vote, so busy polls stay fast - your own vote still appears immediately
* Fixed: Vote Results showed "No votes yet" on polls that had real votes
* Fixed: The call-to-action button never appeared on Result and Closing pages
* Fixed: Answer options were reshuffled every time a respondent went back a question
* Fixed: On phones the cover image pushed the survey title and Start button below the screen
* Fixed: Analytics listed and numbered questions in a different order from the survey
* Fixed: Analytics could show empty figures after switching between the free and Pro versions
* Fixed: Activating Pro while the free version was still active failed with a fatal error
* Fixed: The WordPress update notice sat outside the notice area on SurveyX admin screens

= 2.0.0 =
* Added: Every active survey gets its own shareable page at /survey/{id}/{slug}/, with no shortcode needed
* Added: Survey pages carry Open Graph and Twitter tags, so a shared link shows a title, description and preview image
* Added: The survey page URL base is configurable in Settings
* Added: Standalone survey pages can be turned off site-wide
* Changed: The survey link and the embed shortcode now sit together in the Activate Survey card
* Improved: The survey payload is cached, so a repeat visit does not rebuild it
* Improved: Survey data loads in split queries, so the settings and content are read once rather than once per answer row
* Improved: Added covering database indexes for the response and analytics queries, and dropped four that benchmarking proved dead
* Improved: The survey-page options are autoloaded, removing three database reads from every request
* Improved: View counts moved onto the survey row, so the analytics cache is no longer kept alive just to hold them
* Improved: The public survey loads its less-common question renderers on demand, keeping the first load smaller
* Fixed: Activating a survey from the Editor tab erased that survey's settings
* Fixed: Going Next then Back cleared a typed text answer
* Fixed: One Enter press acted on every survey embedded on the same page
* Fixed: Respondents were stranded when their current question had been deleted
* Fixed: Polls set to "view votes in results" did not show the live results to respondents
* Fixed: A rare false "unsaved changes" prompt while the editor was still loading
* Fixed: A vote could be counted twice when two submissions arrived at the same moment
* Fixed: Drop-off read 0 on sites with WP-Cron disabled
* Fixed: Yes/No answer order varied between page loads
* Fixed: A new question could land on a position already in use
* Fixed: Deleting a survey left its revision history and orphaned analytics rows behind
* Fixed: The export screen listed only the first 100 surveys
* Fixed: A search matching nothing showed the "no surveys yet" message
* Fixed: Saving a survey wrote PHP deprecation notices on PHP 8.1 and newer
* Fixed: A partial settings save could overwrite the rest of the settings
* Fixed: The 404 illustration rendered larger than the viewport
* Fixed: Corrected a "Queston Type" typo shown above every question
* Fixed: Logged-out visitors saw an empty space on a login-required survey
* Security: Repeated requests naming surveys that do not exist are now rate-limited

= 1.7.0 =
* Added: Survey image size option (Settings > Appearance) to reduce image weight and speed up page load, with automatic fallback to the full size image when needed
* Improved: Frontend no longer loads admin-only code or unused libraries, resulting in lighter and faster public surveys
* Improved: Reduced database queries when loading a survey and submitting answers
* Improved: Lighter admin builder assets for faster loading
* Improved: Redesigned captcha screen with a clearer prompt and description
* Improved: Captcha widget now follows the survey light/dark theme
* Improved: Create Survey wizard now supports browser Back navigation, swipe gestures, and Esc to close
* Fixed: Surveys created with the Pro version are now protected from being edited or overwritten in the free version, with an upgrade notice shown instead
* Fixed: Creating a survey now opens the editor directly instead of returning to the dashboard
* Fixed: Free/Pro filter on the Templates page not clickable
* Fixed: Embed dialog "Copy" button now resets when the survey size changes, so the current shortcode is copied
* Fixed: Captcha verification prompt not appearing before the survey
* Fixed: Console warnings during captcha retries

= 1.6.0 =
* Improved: Database performance with composite indexes for faster queries
* Improved: Session cleanup cron job optimized to use single UPDATE query
* Improved: Reduced duplicate database queries in progress handler
* Improved: Better session creation result handling

= 1.5.0 =
* Fixed: Cover page start button Enter key handler working properly

= 1.4.0 =
* Fixed: Cache timing display now correctly shows 6-hour refresh interval
* Fixed: Analytics cache info displays accurate remaining time
* Fixed: Translation strings loading correctly across all components
* Improved: Optimized time formatting utilities

= 1.3.0 =
* Fix Decode HTML entities issue with specific charsets.
* Reduce styling load in frontend.

= 1.2.0 =
* Added survey size option for shortcode
* Improved styling for better visual consistency

= 1.1.0 =
* Improve response summary

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.1 =
Fixes the Vote Results panel reading "No votes yet" on polls that had real votes, call-to-action buttons that never appeared on Result and Closing pages, and analytics numbering questions out of order.
