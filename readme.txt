=== Plugin Name ===
Contributors: wrigs1
Donate link: http://means.us.com/
Tags: WP Super Cache, Super Cache, SuperCache, caching, Country, GeoIp, Geo-Location, Maxmind
Requires at least: 3.3
Tested up to: 4.1.1
Stable tag: 0.5.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Extends WP Super Cache to cache by page/visitor country instead of just page. Solves "wrong country content" Geo-Location issues.

== Description ==

Allows WP Super Cache to display the correct page/widgets content for a visitor's country when you are using geo-location; solves problems like these reported on
[Wordpress.Org](https://wordpress.org/support/topic/plugin-wp-super-cache-super-cache-with-geo-targeting ) and [StackOverflow](http://stackoverflow.com/questions/21308405/geolocation-in-wordpress ).
If you need country caching with other caching plugins then see comments at the bottom of the page.

This plugin builds an extension script that enables Super Cache to create separate snapshots (cache) for each page based on country location.
Separate snapshots can be restricted to specific countries.  E.g. if you are based in the US but customize some content for Canadian or Mexican visitors, you can restrict
separate caching to CA & MX visitors; and all other visitors will see the same cached ("US") content.

It works on both normal Wordpress and Multisite (see FAQ) installations.

More info in [the user guide]( http://wptest.means.us.com/2015/03/geolocation-and-wp-super-cache-caching-by-page-visitor-country-instead-of-just-page/ )

**Identification of visitor country for caching**


If you use Cloudflare and have "switched on" their GeoLocation option ( see [Cloudflare's instructions](https://support.cloudflare.com/hc/en-us/articles/200168236-What-does-CloudFlare-IP-Geolocation-do- ) )
then it will be used to identify visitor country.  If not, then the Maxmind GeoLite Legacy Country Database, included with this plugin, will be used. This plugin will install GeoLite data (both IPv4 and IPv6) created 
by MaxMind, available from http://www.maxmind.com.


Note: not tested on IPv6 (my servers are IPv4), however feedback on Stackoverflow indicate the code should work fine for IPv6.

**Updating** The provided Maxmind Country/IP range data files will lose accuracy over time. If you wish to keep your IP data up to date, then installation of the Category Country Aware plugin ([here on Wordpress.Org](https://wordpress.org/plugins/category-country-aware/ )) is recommended.
The CCA plugin automatically updates Maxmind data every 3 weeks (even if you don't use any of its other features).

** ADVICE:**

I don't recommend you use ANY Caching plugin UNLESS you know how to use an FTP program (e.g. Filezilla). Caching plugins can result in "white screen" problems for some unlucky
users; sometimes the only solution is to manually delete files using FTP or OS command line.  WP Super Cache is no different; when I checked just the first page of 
its support forum included 4 [posts like this](https://wordpress.org/support/topic/site-broken-after-activate-wp-super-cache). The Country Caching plugin deletes files
on deactivation/delete, but in "white screen" situations you may have to resort to "manual" deletion - see FAQ for instructions.


**Zen / Quick Cache:** works seamlessly with this [other plugin extension](https://wordpress.org/plugins/country-caching-extension/).

**W3 Total Cache** does not *currently* provide a suitable hook for plugin country caching. Others have [requested this facility](https://wordpress.org/support/topic/request-add-hook-to-allow-modification-of-the-cache-key ).



== Installation ==

The easiest way is direct from your WP Dashboard like any other plugin:

Once installed go to: "Dashboard->WPSC Country Caching". Check the "*Enable WPSC Country Caching add-on*" box, and save settings.

Then: “Dashboard->Settings->WP Super Cache->Advanced”  ensure “Legacy page caching” is selected, and save.

If you want automatic "3 weekly" update of *Maxmind Country->IP range data* then also install the [Category Country Aware plugin (here on Wordpress.Org)](https://wordpress.org/plugins/category-country-aware/ ).


== Frequently Asked Questions ==

= Where can I find support/additional documentation =

Support questions should be posted on Wordpress.Org<br />
Additional documentation [is provided here]( http://wptest.means.us.com/2015/03/geolocation-and-wp-super-cache-caching-by-page-visitor-country-instead-of-just-page/ )


= How do I know its working =

See [these checks](http://wptest.means.us.com/2015/02/quick-cache-and-geoip-enable-caching-by-pagevisitor-country-instead-of-just-page/#works).

= How do I keep the Maxmind country/IP range data up to date =

Install the [Category Country Aware plugin](https://wordpress.org/plugins/category-country-aware/ ) from Wordpress.Org; it will update Maxmind data every 3 weeks.


= Will it work on Multisites =

Yes, it will be the same for all blogs (you can't have it on for Blog A, and off for Blog B).

On MultiSites, the WPSC Country Caching settings menu will be visible on the Network Admin Dashboard (only).


= How do I stop/remove Country Caching =

Deactivating the plugin will remove the Caching Extension. Then clear the QC cache (Dashboard->QuickCache->Clear)

If all else fails:

1.  Log into your site via FTP; e.g. with CoreFTP or FileZilla.
2.  Delete this directory and contents: /wp-content/plugins/country-caching-wpsc/
3.  Delete this file: "cca_wpsc_geoip_plugin.php" from any add-on directory you defined and from "/wp-content/wp-super-cache/plugins/"
4.  Then via your Wordpress Admin: Dashboard->Settings->WP Super Cache->Easy->delete cache


== Screenshots ==

1. Simple set up. Dashboard->WPSC Country Caching


== Changelog ==

= 0.5.1 =
* License requirements - cosmetic change: settings form now displays notification tha Maxmind GeoIP data is being used.

= 0.5.0 =
* First published version.

== Upgrade Notice ==

= 0.5.1 =
* License requirements - cosmetic change: settings form now displays notification tha Maxmind GeoIP data is being used.


== License ==

This program is free software licensed under the terms of the [GNU General Public License version 2](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html) as published by the Free Software Foundation.

In particular please note the following:

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.