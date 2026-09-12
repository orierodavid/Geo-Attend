=== Geo-Attend ===
Contributors: orierodavid
Tags: attendance, geofence, gps, employee attendance, church attendance, school attendance
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Configurable GPS/geofenced attendance for organizations, churches, schools, teams and companies.

== Description ==
Geo-Attend is a WordPress-native attendance plugin. It stores its data in WordPress MySQL/MariaDB tables and does not require Supabase, Firebase or another external database.

Core features:
* Configurable organization/company identity.
* Configurable departments/categories.
* Member directory with secure 4-digit PIN hashes.
* Multiple attendance locations with independent GPS coordinates and geofence radii.
* Configurable attendance days, start/end time, late threshold and GPS accuracy threshold.
* Server-side geofence validation.
* Duplicate attendance protection per member/day.
* WordPress administrator dashboard and filtered attendance reports.
* CSV exports.
* Responsive frontend via the [geo_attend] shortcode.

== Shortcode ==
Add [geo_attend] to any WordPress page to display the attendance check-in interface.

== Security ==
Attendance validation is performed server-side. PINs are never stored in plain text. Administrative actions require WordPress capability checks and nonces. Public check-in attempts are rate-limited.

== Architecture ==
Geo-Attend is intentionally a native WordPress plugin. The original Lifeline application was used only as a functional/UI reference during conversion; organization-specific names, roles, coordinates and schedules are not part of the plugin architecture.
