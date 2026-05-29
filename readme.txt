=== British Esports Calandendar ===
Contributors: openai
Tags: calendar, events, esports, shortcode, acf block
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.17.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A front end British Esports event calendar with backend event management, quick staff event entry, a front-end staff event portal, recurring event support, Eventbrite integration, shortcode support, and an ACF block.

== Description ==

British Esports Calandendar gives you:

* A BEF Events custom post type in the WordPress admin
* A **Quick Add Event** screen for staff with a stripped-back event form
* A **BEF Event Staff** role for easier, safer event entry
* A **front-end staff portal** shortcode so staff can add or edit events without using wp-admin
* Duplicate and quick-edit shortcuts for recent events
* Event date, end date, time, location, and URL fields
* A front end month calendar with day selection and event sidebar
* Shortcode support: [bef_calendar]
* An ACF block called BEF Calendar
* A configurable block intro area with heading, content, button, and background image
* Eventbrite integration with private token settings and cached event fetching
* Optional toggles to show WordPress events, Eventbrite events, or both in the same calendar
* British Arena sync, recurring events, single event pages, archive pages, category filters, agenda view, and calendar export buttons

== Installation ==

1. Upload the plugin zip in Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Add events in **BEF Calendar > Quick Add Event** for the simplest workflow, or use **BEF Calendar > Add New** if you prefer the full editor.
4. Optionally connect Eventbrite under **BEF Calendar > Eventbrite**.
5. Place [bef_calendar] on a page for the calendar, and optionally add [bef_staff_event_portal] on a staff-only page for front-end event entry.
6. If using the block, make sure Advanced Custom Fields Pro is active.

== Frequently Asked Questions ==

= How do I show the calendar? =

Use the shortcode [bef_calendar] or the BEF Calendar ACF block.

= What is the easiest way for staff to add events? =

Use **BEF Calendar > Quick Add Event** in wp-admin, or create a staff-only page with the shortcode [bef_staff_event_portal] so staff can add and edit events from the front end.

= Does the block need ACF Pro? =

Yes, the block registration and its fields rely on ACF.

= Can I add multi-day events? =

Yes. Add an Event Date and an optional End Date.

= Can I show Eventbrite events? =

Yes. Add your Eventbrite private token in **BEF Calendar > Eventbrite**, optionally set the organisation ID, and enable Eventbrite events in the block or shortcode.

= Can I control which event sources appear? =

Yes. The block has toggles for WordPress events and Eventbrite events. The shortcode also supports show_wordpress="yes|no" and show_eventbrite="yes|no".

== Changelog ==

= 1.17.1 =
* Pulled richer Eventbrite event details into local BEF event pages.
* Added fuller Eventbrite description, organiser, and venue details to the single event template.

= 1.17.0 =
* Eventbrite events are now imported into local BEF event posts when fetched.
* Eventbrite events now open on the local single-bef_event.php template instead of only linking externally.
* Added Eventbrite source labels for imported events across the frontend and admin.


= 1.11.0 =
* Added recurring event support for BEF events with daily, weekly, and monthly repeats.
* Recurring events now flow through the month calendar, agenda view, archive, single pages, and staff entry forms.
* Added recurrence summary and upcoming occurrence details to event pages.

= 1.10.0 =
* Added a front-end staff portal shortcode: [bef_staff_event_portal].
* Staff can now add, edit, duplicate, and draft/publish their own events without opening wp-admin.
* Added front-end image upload, category assignment, and recent-event shortcuts to the staff portal.

= 1.9.0 =
* Added a Quick Add Event staff screen with a simplified event entry form.
* Added a BEF Event Staff role and custom event capabilities.
* Added duplicate and quick-edit shortcuts for recent events.
* Added image selection, category assignment, draft/publish control, and advanced options to the quick-add flow.

= 1.8.0 =
* Added Google Calendar, Apple Calendar, and ICS export buttons for local BEF events.

= 1.6.0 =
* Added month and agenda view toggle for the front-end calendar block and shortcode.

= 1.4.0 =
* Added a plugin-based archive template for BEF events.
* Added theme override support via archive-bef_event.php or bef-calendar/archive-bef_event.php.
* Archive template includes upcoming and past event views with polished event cards and pagination.

= 1.3.0 =
* Added plugin-based single page template for BEF calendar events.
* WordPress calendar events now open to their own single event page from the front end calendar.
* Added theme override support via single-bef_event.php or bef-calendar/single-bef_event.php.


= 1.12.0 =
* Added Google Sheets intake sync with ready-checkbox gating, writeback status columns, and scheduled/manual imports.

* Added direct XLSX upload support for the Google Sheet event-planning template, with header-row auto-detection and better column alias matching.
