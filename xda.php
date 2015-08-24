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

# Parase a page and add the posts to the RSS Feed
function addPageToRSSFeed ($html, RSSFeed $rssFeed) {
	$dom=new DOMDocument();
	@$dom->loadHTML($html);
	$xpath = new DOMXPath($dom);

	# Get the post wrapper divs
	$postDivs = $xpath->query('/descendant::div[@id="posts"]/div[starts-with(@id,"edit") and @class="postbit-wrapper "]');

	# Get the post element divs
	foreach ($postDivs as $postDiv) {
		$rssItem = new RSSItem();
		# Title (author)
		foreach ($xpath->query('.//a[starts-with(@class, "bigfusername")]', $postDiv) as $postAuthorA) {
			$rssItem->title=trim($postAuthorA->nodeValue);
			break;
		}
		# Link, GUID
		foreach ($xpath->query('.//a[@class="postCount"]/@href', $postDiv) as $postLink) {
			# Strip the 's' parameter out since it changes every so often....
			$parsedURL = parse_url($postLink->nodeValue);
			$queryStr = $parsedURL['query'];
			parse_str($queryStr, $queryParams);
			unset($queryParams['s']);
			$rssItem->link='http://forum.xda-developers.com/'.$parsedURL['path'].'?'.http_build_query($queryParams);
			$rssItem->guid=$rssItem->link;
			break;
		}
		# Description
		foreach ($xpath->query('.//div[starts-with(@id, "post_message") and starts-with(@class, "post-text")]', $postDiv) as $postMsgDiv) {
			# Strip ad
			foreach ($xpath->query('.//div[@class="purchad"]', $postDiv) as $postAd) {
				$postAd->parentNode->removeChild($postAd);
			}
			$rssItem->description=trim(str_replace('&#13;',"\r",$dom->saveXML($postMsgDiv)));
			break;
		}
		# Publication Date
		foreach ($xpath->query('.//span[@class="time"]', $postDiv) as $postDateSpan) {
			$rssItem->setPubDate(getXDADate(trim($postDateSpan->nodeValue)));
			$rssFeed->setLastBuildDate($rssItem->getPubDate());	# Set the feed's lastBuildDate to the last post's date
			break;
		}
		$rssFeed->addRSSItem($rssItem);
	}
}


function getRealThreadURL ($threadID) {
	$lastPageURL='http://forum.xda-developers.com/showthread.php?t='.$threadID.'&page=999999999999999';
	$headers=get_headers($lastPageURL,1);
	if ($headers[0]=='HTTP/1.1 301 Moved Permanently') {
		return 'http://forum.xda-developers.com'.$headers['Location'];
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
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		return curl_exec($ch);
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
		$links[]=str_replace('http://forum.xda-developers.com//', 'http://forum.xda-developers.com/', 'http://forum.xda-developers.com/'.$pageLink->nodeValue);
	}
	return $links;
}







$threadID=$_REQUEST['t'];
#$threadID='3033808';
if (!is_numeric($threadID)) {
	echo "Thread ID must be a number\n";
	exit (1);
}


$rssFeed=new RSSFeed('XDA Thread RSS', 'http://forum.xda-developers.com/showthread.php?t='.$threadID, 'XDA Thread RSS');
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