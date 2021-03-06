# API to RSS for UNIT3D-based Torrent Trackers

A `quick'n'dirty` "proxy wrapper" around UNIT3D's API to (locally) generate RSS feeds for various needed search terms *on-the-fly*. No more akwardly setting up tons of RSS feeds in your UNIT3D-based tracker's user account.

This is purely a personal helper, so code is a bit messy (but which PHP code isn't?). Shared here in case someone else wants to have a go at it, or grab some ideas, or something like that. Anyway… it works for me™ and my needs and that's all that is important. 😇 

Yay, **happy turtles!**

## Requirements

* a (locally running) webserver (Apache, nginx, …) with PHP (v7 or better) support

## Installation

* copy `jptv.php` and a `jptv-config.php` to a directory in the document root of your (preferably locally running) webserver
* (optionally?) create `log` and `cache` directories in the same location as the scripts. The `cache` directory must be readable and writeable by the webserver user. Inside the `log` directory only the logfile itself needs write access, if required.
* adjust jptv-config.php with your API_TOKEN (and the TRACKER_BASE, if it's not jptv.club)

If everything works then a quick cURL call should dump an RSS feed, something like:

```
$ curl 'http://localhost/jptv.php?keyword=怪物'
<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:atom="http://www.w3.org/2005/Atom" version="2.0" lang="en">
<channel>
	<title>jptv.club: 怪物</title>
	<description>Results for &lt;怪物&gt; fetched via jptv.club's API.</description>
	<language>en</language>
	<lastBuildDate>Wed, 15 Jun 2022 20:41:51 +0200</lastBuildDate>
	<link>https://jptv.club/</link>
	<generator>Wilhelm's UNIT3D API to RSS Wrapper 1.2 (15.6.2022)</generator>
	<ttl>300</ttl>
	<atom:link href="http://localhost/jptv.php?keyword=怪物" type="application/rss+xml" rel="self"/>
	<item>
		<title>…
		…
</channel>
</rss>
```

## Tips

Still too much to type? Things can be further optimized with short host names or via URL rewriting, e.g. for Apache updating a local .htaccess file with:

```
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^rss/(.+)/?$ jptv.php?keyword=$1 [QSA]
```

will allow shorter URLs like: `http://localhost/rss/怪物`

## Helper Script

`addfeed` is a shell script (for Mac OS X) which makes things even more comfortably. Just typing `addfeed 怪物` at the commandline prompt and BOOM a feed gets added to the RSS reader (the script currently loads "Vienna", so you may need to adjust it, if you use a different application).

## Security Considerations (Who cares about that?)

Probably best to not run this on public facing servers, unless you really understand what you're doing (you probably want to at least add password protection via the webserver to the location). Generated RSS feeds include your personal RSS Key ("RID") for the direct torrent file download links, unless adding of the "enclosure"  section is disabled in the code.
