<?php

/*  Base URL for a UNIT3D based torrent tracker. Trailing slash required.
 *
 *  [required]
 */
define('TRACKER_BASE', 'https://jptv.club/');

/*  API Token, see user menu: "My Security > API Token". A "Reset API Token" may be required initially, even
 *  when the tracker already displays a token. It's kinky.
 *
 *  [required]
 */
define('API_TOKEN', '!!!! INSERT_YOUR_API_TOKEN_HERE !!!!');

/*  A comma-separated list of uploaders to filter out of the search results of all feeds. Use "blocked_uploaders=" URL
 *  parameter to block uploaders from specific feeds.
 *
 *  [optional]
 */
/* define('BLOCKED_UPLOADERS' , ''); */
