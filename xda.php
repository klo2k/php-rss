<?php
require_once('RSSItem.php');
require_once('RSSFeed.php');

/*
e.g.
Today, 11:16 AM
Yesterday, 04:50 AM
Yesterday, 05:57 PM 
14th August 2015, 08:50 AM 
14th August 2015, 09:47 PM
*/
function getXDADate ($xdaDateStr) {
	$dt=new DateTime ($xdaDateStr, new DateTimeZone('CET'));
	$dt->setTimezone(new DateTimeZone('Australia/Sydney'));
	return $dt;
}

# Fix-up the HTML of posts
function cleanPostMessageHTML ($html) {
	# Emoticon URL
	$html = str_replace('<img src="//','<img src="https://', $html);
	# New line character
	$html = str_replace('&#13;',"\r", $html);
	# Blockquote styling
	$html = str_replace('<div class="bbcode-quote">','<div class="bbcode-quote" style="border: 1px solid grey; padding: 4px;">', $html);
	
	return trim($html);
}

# Parse a page and add the posts to the RSS Feed
function addPageToRSSFeed ($html, RSSFeed $rssFeed) {
	$html = preg_replace("#<script.*?</script>#is", "", $html);	# Strip out <script> tags so loadHTML() parses the page correctly for $xpath->query()
	$dom=new DOMDocument();
	@$dom->loadHTML($html);
	$xpath = new DOMXPath($dom);
	
	# Get the post wrapper divs
	$postDivs = $xpath->query('/descendant::div[@id="posts"]/div[starts-with(@id,"edit") and @class="postbit-wrapper "]');
	
	# Thread URL
	$pageURL = current(iterator_to_array($xpath->query('/html/head/link[@rel="canonical"]/@href')))->nodeValue;
	
	# Title
	$title = current(iterator_to_array($xpath->query('//div[@id = "thread-header-bloglike"]//h1')))->nodeValue;
	$rssFeed->title = $title;

	# Get the post element divs
	foreach ($postDivs as $postDiv) {
		$rssItem = new RSSItem();
		# Title (author)
		$rssItem->title='[Post]';	// Default to "[Post]" on first post
		foreach ($xpath->query('.//a[starts-with(@class, "bigfusername")]', $postDiv) as $postAuthor) {
			$rssItem->title=trim($postAuthor->nodeValue);
			$rssItem->author=trim($postAuthor->nodeValue);
			break;
		}
		# Link, GUID
		$rssItem->link=$pageURL;	// Default to page URL on first post
		$rssItem->guid=$rssItem->link;
		foreach ($xpath->query('.//a[@class="postCount"]/@href', $postDiv) as $postLink) {
			# Strip the 's' parameter out since it changes every so often....
			$parsedURL = parse_url($postLink->nodeValue);
			$queryStr = $parsedURL['query'];
			parse_str($queryStr, $queryParams);
			unset($queryParams['s']);
			$rssItem->link='https://forum.xda-developers.com/'.$parsedURL['path'].'?'.http_build_query($queryParams);
			$rssItem->guid=$rssItem->link;
			break;
		}
		# Description
		foreach ($xpath->query('.//div[starts-with(@id, "post_message") and starts-with(@class, "post-text")]', $postDiv) as $postMsgDiv) {
			# Strip ad
			foreach ($xpath->query('.//div[@class="purchad"]', $postDiv) as $postAd) {
				$postAd->parentNode->removeChild($postAd);
			}
			$rssItem->description=cleanPostMessageHTML($dom->saveXML($postMsgDiv));
			break;
		}
		# Publication Date
		$rssItem->setPubDate(new DateTime('1900-01-01'));	// Default to 1st JAN 1900 on first post... oh well...
		$rssFeed->setLastBuildDate($rssItem->getPubDate());
		foreach ($xpath->query('.//span[@class="time"]', $postDiv) as $postDateSpan) {
			$rssItem->setPubDate(getXDADate(trim($postDateSpan->nodeValue)));
			$rssFeed->setLastBuildDate($rssItem->getPubDate());	# Set the feed's lastBuildDate to the last post's date
			break;
		}
		$rssFeed->addRSSItem($rssItem);
	}
}

# From http://stackoverflow.com/questions/6368574/how-to-get-the-functionality-of-http-parse-headers-without-pecl#answer-21227489
function _http_parse_headers ($raw) {
	$res = array();
	foreach (explode("\n", $raw) as $h) {
		$h = explode(':', $h, 2);
		$first = trim($h[0]);
		$last = @trim($h[1]);
		if (array_key_exists($first, $res)) {
			$res[$first] .= ", " . $last;
		} else if (isset($h[1])) {
			$res[$first] = $last;
		} else {
			$res[] = $first;
		}
	}
	return $res;
}

# Same as get_headers($lastPageURL) - except we're injecting a cookie value
function _get_headers ($url) {
	try {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		# Emulate a real request - if "visited=1" cookie value is not present, you'll get HTTP 500
		curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:40.0) Gecko/20100101 Firefox/40.0');
		curl_setopt($ch, CURLOPT_COOKIE, "bblastvisit=".time()."; bblastactivity=0; visited=1; xda_adtest=0");
		$headers = _http_parse_headers(curl_exec($ch));
		curl_close($ch);
		return $headers;
	} catch (Exception $e) {
		try {curl_close($ch);} catch (Exception $e) {}
	}
}

function getRealThreadURL ($threadID) {
	$lastPageURL='https://forum.xda-developers.com/showthread.php?t='.$threadID.'&page=999999999999999';
	$headers=_get_headers($lastPageURL);	# We can't use get_headers($lastPageURL,1); anymore - we need to inject the visited=1 value
	if ($headers[0]=='HTTP/1.1 301 Moved Permanently') {
		return 'https://forum.xda-developers.com'.$headers['Location'];
	} else {
		# For the old threads - it doesn't actually redirect
		return $lastPageURL;
	}
}

# Get the page's HTML for a given URL
function getPageHTML ($url) {
	try {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		#curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);	# 000 Webhost doesn't allow CURLOPT_FOLLOWLOCATION
		#curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		# Emulate a real request - if "visited=1" cookie value is not present, you'll get HTTP 500
		curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:40.0) Gecko/20100101 Firefox/40.0');
		curl_setopt($ch, CURLOPT_COOKIE, "bblastvisit=".time()."; bblastactivity=0; visited=1; xda_adtest=0");
		$html = curl_exec($ch);
		if ($html === false)
			throw new Exception (curl_error($ch), curl_errno($ch));
		curl_close($ch);
		return $html;
	} catch (Exception $e) {
		try {curl_close($ch);} catch (Exception $e) {}
	}
}

/*
Get the last N pages' URL
For now, n = 0..2
*/
function getLastNPageURLs ($html, $n=2) {
	$dom=new DOMDocument();
	@$dom->loadHTML($html);
	$xpath = new DOMXPath($dom);
	$links=array();
	foreach ($xpath->query('/descendant::div[@class="pagenav"][1]/a[contains(@class,"pagenav-pagelink")][position()>last()-'.$n.']/@href') as $pageLink) {
		# Old thread page links include the '/' prefix, new don't, replace '//' with '/'
		$links[]=str_replace('https://forum.xda-developers.com//', 'https://forum.xda-developers.com/', 'https://forum.xda-developers.com/'.$pageLink->nodeValue);
	}
	return $links;
}







$threadID=$_REQUEST['t'];
#$threadID='3033808';
if (!is_numeric($threadID)) {
	echo "Thread ID must be a number\n";
	exit (1);
}


$rssFeed=new RSSFeed('XDA Thread RSS', 'https://forum.xda-developers.com/showthread.php?t='.$threadID, 'XDA Thread RSS');
$rssFeed->setLastBuildDate(new DateTime());

# Get the last page and work out the last 2 pages' URLs
$html=getPageHTML(getRealThreadURL($threadID));	# 000 Webhost doesn't allow CURLOPT_FOLLOWLOCATION so I need to do this...
$links=getLastNPageURLs($html,2);
#require_once('html.php');	#### DEBUG ####
#$links=array(0);	#### DEBUG ####

# Add the second, and first last page to the RSS feed
for ($i=0; $i<sizeof($links); $i++) {
	addPageToRSSFeed(
		getPageHTML($links[$i]),
		#$html,	#### DEBUG ####
		$rssFeed
	);
}
# Add the very last page to the RSS feed
addPageToRSSFeed($html, $rssFeed);

# Reverse the RSS Items so the latest one appears up top
$rssFeed->reverseRSSItems();


# Display the content
ob_start();
header('Content-Type: text/xml;charset=UTF-8');
echo $rssFeed->getXMLSource();
ob_end_flush();

?>