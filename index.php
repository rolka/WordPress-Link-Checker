<?php

// CONFIG
$db_host = 'localhost';
$db_user = '';
$db_pass = '';
$db_name = '';
$excluded_domains = array(
	'localhost', 'www.mydomain.com');
$max_connections = 10;
// initialize some variables
$url_list = array();
$working_urls = array();
$dead_urls = array();
$not_found_urls = array();
$active = null;

// connect to MySQL
if (!mysql_connect($db_host, $db_user, $db_pass)) {
	die('Could not connect: ' . mysql_error());
}
if (!mysql_select_db($db_name)) {
	die('Could not select db: ' . mysql_error());
}
// get all published posts that have links
$q = "SELECT post_content FROM wp_posts
	WHERE post_content LIKE '%href=%'
	AND post_status = 'publish'
	AND post_type = 'post'";
$r = mysql_query($q) or die(mysql_error());
while ($d = mysql_fetch_assoc($r)) {

	// get all links via regex
	if (preg_match_all("!href=\"(.*?)\"!", $d['post_content'], $matches)) {

		foreach ($matches[1] as $url) {

			// exclude some domains
			$tmp = parse_url($url);
			if (in_array($tmp['host'], $excluded_domains)) {
				continue;
			}

			// store the url
			$url_list []= $url;
		}
	}
}

// remove duplicates
$url_list = array_values(array_unique($url_list));

if (!$url_list) {
	die('No URL to check');
}



// 1. multi handle
$mh = curl_multi_init();

// 2. add multiple URLs to the multi handle
for ($i = 0; $i < $max_connections; $i++) {
	add_url_to_multi_handle($mh, $url_list);
}

// 3. initial execution
do {
	$mrc = curl_multi_exec($mh, $active);
} while ($mrc == CURLM_CALL_MULTI_PERFORM);

// 4. main loop
while ($active && $mrc == CURLM_OK) {

	// 5. there is activity
	if (curl_multi_select($mh) != -1) {

		// 6. do work
		do {
			$mrc = curl_multi_exec($mh, $active);
		} while ($mrc == CURLM_CALL_MULTI_PERFORM);

		// 7. is there info?
		if ($mhinfo = curl_multi_info_read($mh)) {
			// this means one of the requests were finished

			// 8. get the info on the curl handle
			$chinfo = curl_getinfo($mhinfo['handle']);

			// 9. dead link?
			if (!$chinfo['http_code']) {
				$dead_urls []= $chinfo['url'];

			// 10. 404?
			} else if ($chinfo['http_code'] == 404) {
				$not_found_urls []= $chinfo['url'];

			// 11. working
			} else {
				$working_urls []= $chinfo['url'];
			}

			// 12. remove the handle
			curl_multi_remove_handle($mh, $mhinfo['handle']);
			curl_close($mhinfo['handle']);

			// 13. add a new url and do work
			if (add_url_to_multi_handle($mh, $url_list)) {

				do {
					$mrc = curl_multi_exec($mh, $active);
				} while ($mrc == CURLM_CALL_MULTI_PERFORM);
			}
		}
	}
}

// 14. finished
curl_multi_close($mh);

echo "==Dead URLs==\n";
echo implode("\n",$dead_urls) . "\n\n";

echo "==404 URLs==\n";
echo implode("\n",$not_found_urls) . "\n\n";

echo "==Working URLs==\n";
echo implode("\n",$working_urls);

// 15. adds a url to the multi handle
function add_url_to_multi_handle($mh, $url_list) {
	static $index = 0;

	// if we have another url to get
	if ($url_list[$index]) {

		// new curl handle
		$ch = curl_init();

		// set the url
		curl_setopt($ch, CURLOPT_URL, $url_list[$index]);
		// to prevent the response from being outputted
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		// follow redirections
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		// do not need the body. this saves bandwidth and time
		curl_setopt($ch, CURLOPT_NOBODY, 1);

		// add it to the multi handle
		curl_multi_add_handle($mh, $ch);
		// increment so next url is used next time
		$index++;

		return true;
	} else {

		// we are done adding new URLs
		return false;
	}
}
?>