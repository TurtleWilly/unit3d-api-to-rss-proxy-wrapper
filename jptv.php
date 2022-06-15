<?php

/*
 *  jptv.php
 *
 *  A quick'n'dirty-work's-for-me "proxy wrapper" around UNIT3D's API to (locally) generate RSS
 *  feeds for various needed search terms on-the-fly. No more akwardly setting up RSS feeds in
 *  your UNIT3D-based tracker's user account. Yay, happy turtles!
 *
 *  Quickly hacked together by Wilhelm/ JPTV.club and released in the Public Domain.
 */

/*  TODO:
 *   - Add Seeder/Leecher/Completed stats
 *   - Cache: Adjust some output to reflect "cached" status?
 *	 - Add test/protection/warn against reverse order results for older UNIT3D versions?
 *   - Add support for generating feeds by 'uploader' rather than name search
 */

define('TEST_MODE', FALSE); /* for "visual testing" via HTML output in a web browser. */


/*   Errors
 */
ini_set('error_log',	  dirname(__FILE__).'/log/jptv-rss.log');
ini_set('log_errors',     '1');
ini_set('display_errors', '0');
error_reporting(E_ALL|E_STRICT);


/*   Initialize Configuration
 */
require_once(dirname(__FILE__).'/jptv-config.php');
if (!(defined('TRACKER_BASE') && defined('API_TOKEN'))) { die(); /* Never, never land… */ }

date_default_timezone_set('Europe/Berlin'); /* CE[S]T */



/*   Support Functions
 */
function humanreadable($bytes = 0)
{
	if ($bytes > 0)
	{
		$size   = array('Bytes', 'KiB', 'MiB', 'GiB', 'TiB');
		$factor = min(floor((strlen(strval($bytes)) - 1) / 3), (sizeof($size) - 1));

		return sprintf("%.2f %s", $bytes / (1024 ** $factor), $size[$factor]);
	}

	return "0 Bytes";
}

function selfurl()
{
	/* Note: no support for custom ports
	 */
	$protocol = ((array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS'] != 'off') || ($_SERVER['SERVER_PORT'] == 443)) ? 'https' : 'http';
	return $protocol.'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
}



/*   Support Classes
 */
class SimpleXMLElementSafe extends SimpleXMLElement
{
	/*  Yikes, BS…
	 *  	https://stackoverflow.com/questions/552957
	 */
	function addChild($name, $value = null, $ns = null)
	{
		$foo = parent::addChild($name, NULL, $ns);

		if ($value)
		{
			/* Note: This will do the full escaping, incl. "&" -> "&amp; which the default addChild() doesn't do for
			 *       some silly reason. Why have such a "high-level" API when the direct "low-level" does better work
			 *       being "high" level? Should be the other way around, no? PHP in a nutshell? :-)
			 */
			$foo[0] = $value;
		}

		return $foo; /* @phan-suppress-current-line PhanPartialTypeMismatchReturn */
	}
}



/*   main()
 */
define('USER_AGENT',      'Wilhelm\'s UNIT3D API to RSS Wrapper 1.2 (15.6.2022)');
define('RSS_DATE_FORMAT', 'D\,\ d\ M\ Y\ H\:i\:s\ O');


$rc = 503;

if (array_key_exists('keyword', $_GET) && !empty($keyword = strval($_GET['keyword'])))
{
	/* Note: We need to normalize the input it seems. Especially if we are pasting around the search term via
	 *       Mac OS X then things most likely will end up in a different internal form. And that will break
	 *       any search (un)expectededly.
	 */
	$keyword_norm = Normalizer::normalize($keyword);
	/* trigger_error('Used keyword: "'.$keyword.'" (orignal length: '.strlen($keyword).', normalized length: '.strlen($keyword_norm).')'); */
	if (TEST_MODE)
	{
		echo '<h2>User Input</h2><strong>Keyword:</strong> <span style="color:red;">'.$keyword.'</span>';
	}

	/* Initialize 'uploader filter' list.
	 */
	$blocked_uploaders = [];
	if (defined('BLOCKED_UPLOADERS'))
	{
		$blocked_uploaders = array_map('trim', explode(',', BLOCKED_UPLOADERS));
	}
	if (array_key_exists('blocked_uploaders', $_GET) && !empty($_GET['blocked_uploaders']))
	{
		$bulist = array_map('trim', explode(',', strval($_GET['blocked_uploaders'])));
		$blocked_uploaders = array_unique(array_merge($blocked_uploaders, $bulist));
	}
	/* trigger_error('Initialized "uploader" filter: '.implode(',', $blocked_uploaders)); */

	$prettyhost = parse_url(TRACKER_BASE, PHP_URL_HOST) ?? 'Unknown';
	$cachefile  = dirname(__FILE__).'/cache/'.hash('sha256', $keyword_norm).'.json';

	$request_url = TRACKER_BASE.'/api/torrents/filter?'.http_build_query([
		'api_token' => API_TOKEN,
		/* Actual search query parameters
		 */
	    'name'      => $keyword_norm,
		'sorting'   => 'created_at',
		'direction' => 'desc', /* alternatively: 'asc' */
		'qty'       => file_exists($cachefile) ? 25 : 100,  /* try to grab more for inital sync */
	]);

	$ch = curl_init($request_url);
	if ($ch)
	{
		$loaded_from_cache = FALSE;

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, USER_AGENT);
		$response_body = curl_exec($ch);
		curl_close($ch);

		/*  Try to load from cache, if remote failed.
		 */
		if (!($response_body !== FALSE)) /* :-P */
		{
			trigger_error("Remote failed, will try to use the cache.");
			$response_body = file_get_contents($cachefile);
			$loaded_from_cache = TRUE;
		}

		if ($response_body !== FALSE)
		{
			if (TEST_MODE)
			{
				echo '<h2>RAW Response Body</h2><pre>'.htmlspecialchars(strval($response_body)).'</pre>';
			}

			$r = json_decode(strval($response_body), TRUE);

			/* Check, if we may have potentially useful data
			 */
			if (($r != NULL) && is_array($r) && array_key_exists('data', $r) && is_array($r['data'])) /* NOTE: allow empty results for now: && (sizeof($r['data']) > 0)) */
			{
				/* Add RAW response to the cache
				 */
				if (!$loaded_from_cache)
				{
					file_put_contents($cachefile, $response_body);
				}

				/* Note: This is rather silly, IMHO. We have to write the RAW XML almost fully manually for this "higher-level" API
				 *       to make encoding and root namespaces to be applied properly in the output.
				 */
				$xmldoc = new SimpleXMLElementSafe('<?xml version="1.0" encoding="UTF-8"?><rss xmlns:atom="http://www.w3.org/2005/Atom" />');
				$xmldoc->addAttribute('version', '2.0');
				$xmldoc->addAttribute('lang',    'en');

				/*  @Reference: https://www.rssboard.org/rss-specification#requiredChannelElements
				 *  @Reference: https://www.rssboard.org/rss-validator/
				 */
				$channel = $xmldoc->addChild('channel');
				$channel->addChild('title',         $prettyhost.': '.$keyword_norm);
				$channel->addChild('description',   'Results for <'.$keyword_norm.'> fetched via '.$prettyhost.'\'s API.');
				$channel->addChild('language',      'en');
				$channel->addChild('lastBuildDate', date(RSS_DATE_FORMAT));  /* TODO: pubDate too? */
				$channel->addChild('link',          TRACKER_BASE);
				$channel->addChild('generator',     USER_AGENT);
				$channel->addChild('ttl',           '300');

				$tmp = $channel->addChild('atom:link', NULL, 'http://www.w3.org/2005/Atom');
				$tmp->addAttribute('href', selfurl());
				$tmp->addAttribute('type', 'application/rss+xml');
				$tmp->addAttribute('rel',  'self');

				$cnt = 0;

				/*  Scan data for usable items
				 */
				foreach($r['data'] as $t)
				{
					if (array_key_exists('type',         $t) && ($t['type'] == 'torrent') &&
						array_key_exists('id',           $t) &&
						array_key_exists('attributes',   $t) && is_array(($a = $t['attributes'])) &&
						/* We need at least this 3 attributes to generate anything remotely useful below
						 */
						array_key_exists('name',         $a) &&
						array_key_exists('created_at',   $a) &&
						array_key_exists('details_link', $a)
						)
					{
						if (TEST_MODE)
						{
							echo '<h4>Item #'.$cnt.'</h4><pre>'; var_dump($t); echo '</pre>';
						}

						if (array_key_exists('uploader', $a) && in_array(trim($a['uploader']), $blocked_uploaders))
						{
							continue;
						}

						$item = $channel->addChild('item');

						$item->addChild('title', $a['name']);
						/* TODO: safely verify the input date? Currently ignoring FALSE from strtotime.
						 */
						$item->addChild('pubDate', date(RSS_DATE_FORMAT, intval(strtotime($a['created_at']))));
						$item->addChild('link', $a['details_link']);

						$tmp = $item->addChild('guid', $a['details_link']);
						$tmp->addAttribute('isPermaLink', 'true');

						if (array_key_exists('category', $a))
						{
							$item->addChild('category', $a['category']);
						}

						if (array_key_exists('uploader', $a))
						{
							$item->addChild('dc:creator', $a['uploader'], 'http://purl.org/dc/elements/1.1/');
						}

						/* Generate and add detailed description
						 */
						$tmp = $item->addChild('description');
						if ($tmp !== NULL)
						{
							$layout = [
								'Name'            => $a['name'],
								'Category'        => array_key_exists('category',   $a) ? trim($a['category'])              : NULL,
								'Type'            => array_key_exists('type',       $a) ? trim($a['type'])                  : NULL,
								'Resolution'      => array_key_exists('resolution', $a) ? trim($a['resolution'])            : NULL,
								'Size'            => array_key_exists('size',       $a) ? humanreadable(intval($a['size'])) : NULL,
								'Number of Files' => array_key_exists('num_file',   $a) ? intval($a['num_file'])            : NULL,
								'Uploader'        => array_key_exists('uploader',   $a) ? trim($a['uploader'])              : NULL,

								/* Note: Those aren't too helpful w/o any refreshing of already loaded entries in the (RSS) clients
								 */
								/* 'Uploaded'        => 'Some time ago.', */
								/* 'Seeding Stats' => 'x Seeders, x Leechers, x Completed.', */

								/* Note: it dumps an ID in the API, but no way to determine w/o random assumption if it is 'tv/' or 'movie/'
								 */
								/* 'TMDB' =>  'n/a',  */
							];

							$description = '';
							foreach($layout as $k => $v)
							{
								if ($v !== NULL)
								{
									$description .= '<strong>'.htmlspecialchars($k).':</strong> '.htmlspecialchars(strval($v)).'<br>'.PHP_EOL;
								}
							}

							$node = dom_import_simplexml($tmp);
							if ($node)
							{
								$nod  = $node->ownerDocument;
								$node->appendChild($nod->createCDATASection($description));
							}
						}

						/* Add direct download link pointing to the torrent file
						 */
						if (array_key_exists('download_link', $a))
						{
							$tmp = $item->addChild('enclosure');
							$tmp->addAttribute('type', 'application/x-bittorrent');
							/* Note: forcing the suffix here. Link still works like that, and now the downloaded file is
							 *       properly detected as '.torrent' file by the OS and applications too.
							 */
							$tmp->addAttribute('url', $a['download_link'].'.torrent');
							/* Note: RSS standard requires it, but the information is not available via API. My RSS
							 *       client doesn't mind either way. But disabling it breaks feed validation.
							 */
							$tmp->addAttribute('length', '1048576');
						}

						$cnt++;
					}
				}

				if (TEST_MODE)
				{
					$x = new DOMDocument();
					$x->loadXML(strval($xmldoc->asXML())); /* ? */
					$x->preserveWhiteSpace = false;
					$x->formatOutput       = true;
					echo '<h2>Generated Output</h2><pre>'.htmlspecialchars($x->saveXML()).'<pre>';
				}
				else
				{
					header('Expires: Mon, 01 Jan 2000 00:00:00 GMT');
					header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
					header('Cache-Control: no-store, no-cache, must-revalidate');
					header('Pragma: no-cache');
					header('Content-Type: application/rss+xml; charset=utf-8');

					echo $xmldoc->asXML();
					exit();

					/* And we're done. Happy feed syncing!
					 */
				}
			}
			else
			{
				trigger_error(($loaded_from_cache ? 'Cache' : 'Remote').' returned unsuitable data.');
			}
		}
		else
		{
			trigger_error('Remote fetch failed'.($loaded_from_cache ? ', and cache was empty.' : '.'));
		}
	}
	else
	{
		trigger_error('cURL initialization failed.');
	}
}
else
{
	$rc = 404;
	trigger_error('Called without \'keyword\'. Our helper turtles don\'t know what to do.');
}

http_response_code($rc);
die();
