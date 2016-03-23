<?php
require_once("RSSItem.php");

class RSSFeed {
	public $title;
	public $link;
	public $description;
	private $lastBuildDate;
	
	private $rssItems=array();
	
	public function __construct ($title, $link=null, $description=null) {
		$this->title=$title;
		$this->link=$link;
		$this->description=$description;
	}
	
	public function setLastBuildDate (DateTime $lastBuildDate) {$this->lastBuildDate=$lastBuildDate;}
	
	public function reverseRSSItems () {
		$this->rssItems=array_reverse($this->rssItems);
	}
	
	public function addRSSItem (RSSItem $rssItem) {
		$this->rssItems[]=$rssItem;
	}
	
	public function getXMLSource () {
		$src=
'<?xml version="1.0"?>
<rss version="2.0">
	<channel>
		<title>'.htmlspecialchars($this->title).'</title>
		<link>'.htmlspecialchars($this->link).'</link>
		<description>'.htmlspecialchars($this->description).'</description>
		<lastBuildDate>'.$this->lastBuildDate->format(DateTime::RSS).'</lastBuildDate>';
		# <item>-s
		for ($i=0; $i<sizeof($this->rssItems); $i++) {
			$src.=$this->rssItems[$i]->getXMLSource();
		}
		$src.='
	</channel>
</rss>
';
		return $src;
	}
}
?>