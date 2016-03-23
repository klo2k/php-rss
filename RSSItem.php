<?php
class RSSItem {
	public $title;
	public $link;
	public $guid;
	public $author;
	public $enclosureURL;
	public $enclosureType;
	private $pubDate;		# The parsed date string in rfc-2822
	public $description;
	
	# Replace ']]>' in CDATA with 2 blocks of CDATA - first block inserting ']]', second block '>'
	# http://stackoverflow.com/questions/223652/is-there-a-way-to-escape-a-cdata-end-token-in-xml/18405980#18405980
	public static function escapeCDataString ($cDataString) {
		return str_replace(']]>', ']]]]><![CDATA[>', $cDataString);
	}
	
	public function getPubDate () {return $this->pubDate;}
	public function setPubDate (DateTime $pubDate) {$this->pubDate=$pubDate;}
	
	public function getXMLSource() {
		$src='
<item>
	<title><![CDATA['.self::escapeCDataString($this->title).']]></title>
	<link>'.htmlspecialchars($this->link).'</link>
	<guid>'.htmlspecialchars($this->guid).'</guid>';
		if (!empty($this->author)) {
			$src .= '
	<author><![CDATA['.self::escapeCDataString($this->author).']]></author>';
		}
		if (!empty($this->enclosure_url)) {
			$src.='
	<enclosure url="'.urlencode($rssItem->enclosureURL).'" type="'.htmlspecialchars($rssItems->enclosureType).'"/>';
		}
		$src.='
	<pubDate>'.$this->pubDate->format(DateTime::RSS).'</pubDate>
	<description><![CDATA['.self::escapeCDataString($this->description).']]></description>
</item>';
		#return str_replace("\t",'',$src);
		return $src;
	}
}
?>