<?php

/* CONFIGURATION PARAMETERS */

define("RSSZ_USE_PROXY", false);
define("RSSZ_PROXY", 'localhost:8118');
/* Allow web browsers to get content from this file (Your TorrentzRSS back end) if its not located in the same domain as the requesting web page. */
define("RSSZ_ALLOW_CROSS_DOMAIN", true);
define("RSSZ_SITE_NAME", "TorrentzRSS!");
define("RSSZ_SITE_URL", "http://Theadd.github.io/TorrentzRSS/");
/* Time in minutes for torrent applications to wait between requests. */
define("RSSZ_TTL", 15);

/* END OF CONFIGURATION PARAMETERS */

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

require_once 'XML/RSS.php';
require_once 'XML/Serializer.php';

$results = 0;

if (RSSZ_ALLOW_CROSS_DOMAIN)
    header('Access-Control-Allow-Origin: *');

function array_orderby()
{
    $args = func_get_args();
    $data = array_shift($args);
    foreach ($args as $n => $field) {
        if (is_string($field)) {
            $tmp = array();
            foreach ($data as $key => $row)
                $tmp[$key] = $row[$field];
            $args[$n] = $tmp;
            }
    }
    $args[] = &$data;
    call_user_func_array('array_multisort', $args);
    return array_pop($args);
}

function process_url($url, &$channel) {
	$url = addslashes(stripslashes($url));
    $content = "";

    $command = (RSSZ_USE_PROXY) ? 'curl -x '.RSSZ_PROXY.' "'.$url.'" -iX GET' : 'curl "'.$url.'" -iX GET';
    $response = shell_exec($command);

    $isHeader = true;
    foreach(preg_split("/((\r?\n)|(\r\n?))/", $response) as $line){

        if ($isHeader && empty($line)) {
            $isHeader = false;
            continue;
        }
        if (!$isHeader) {
            $content .= $line.PHP_EOL;
        }
    }

    $filename = microtime(true);
    file_put_contents("data/".$filename.".xml", $content);

    $rss = new XML_RSS("data/".$filename.".xml");
    $rss->parse();
    $items = $rss->getItems();

    foreach ($items as $item) {
        preg_match("/Size\:\s(.*?)\sSeeds\:\s(\d+?)\D.*?Peers\:\s(\d+?)\D.*?Hash\:\s(.*?)$/", $item['description'], $m);
        $item['title_lowercase'] = strtolower(htmlentities($item['title'], ENT_COMPAT, "UTF-8"));
        $item['link'] = "http://torrage.com/torrent/".strtoupper($m[4]).".torrent";
        $item['size'] = $m[1];
        $item['size_raw'] = intval($m[1]);
        $item['hash'] = strtoupper($m[4]);
        $item['seeds'] = $m[2];
        $item['peers'] = $m[2] + $m[3];
        $item['leechers'] = $m[3];
        $item['seeds-leechers'] = $m[2] - $m[3];
        @$item['pubtimestamp'] = strtotime($item['pubdate']);
        $channel[] = $item;
    }

    unset($rss);
    unlink("data/".$filename.".xml");

    return count($items);
}

function getMovieQualityRate($title) {
    $rate = 0;

    if (preg_match("/(?:dvd.?scr(?:eener)?)|(?:br.?scr(?:eener)?)|(?:bluray.?scr(?:eener)?)|(?:hdtv.?scr(?:eener)?)/", $title)) {
        $rate = 2;
    } else if (preg_match("/screener/", $title)) {
        $rate = 1;
    } else {
        if (preg_match("/(?:br.?rip)|(?:bd.?rip)|(?:bluray.?rip)|(?:bluray)/", $title)) {
            $rate = 7;
        } else if (preg_match("/(?:dvd.?rip)/", $title)) {
            $rate = 6;
        } else if (preg_match("/hdtv/", $title)) {
            $rate = 5;
        } else if (preg_match("/(?:web.?rip)/", $title)) {
            $rate = 4;
        } else if (preg_match("/rip/", $title)) {
            $rate = 3;
        }
    }

    return $rate;
}

/* Returns an associative array (key: hash) corresponding with the resulting channel items. */
function handleDuplicatesAsMovies($channel, $rule) {
    //Handle duplicates as movies
    $hashes = array();
    $args = str_split(substr($rule, 1));    //i.e.: QSs --> array('Q', 'S', 's');
    $aux = array_orderby($channel, 'title_lowercase', SORT_ASC);
    $items = count($aux);
    $i = 0;
    while ($i + 1 < $items) {
        if (strlen($iname = substr($aux[$i]['title_lowercase'], 0, strpos($aux[$i]['title_lowercase'], ' ', 5)))) {
            $wnd = 1;
            $halfpos = round(strlen($aux[$i]['title_lowercase']) / 2);
            while (true) {
                if (!((@substr($aux[$i+$wnd]['title_lowercase'], 0, strlen($iname)) == $iname)
                    && levenshtein(substr($aux[$i]['title_lowercase'], 0, $halfpos), substr($aux[$i+$wnd]['title_lowercase'], 0, $halfpos)) < round($halfpos * 0.5)))
                    break;
                $item1 = $aux[$i];
                $item2 = $aux[$i+$wnd];
                $swapped = false;
                foreach ($args as $arg) {
                    switch ($arg) {
                        case 'Q':
                            //better quality
                            $rate1 = getMovieQualityRate($item1['title_lowercase']);
                            $rate2 = getMovieQualityRate($item2['title_lowercase']);
                            if ($rate2 > $rate1) {
                                //keep item2 and break foreach
                                $item1 = $item2;
                                break 2;
                            } else if ($rate1 > $rate2) break 2;
                            break;
                        case 'q':
                            //poorer quality
                            $rate1 = getMovieQualityRate($item1['title_lowercase']);
                            $rate2 = getMovieQualityRate($item2['title_lowercase']);
                            if ($rate2 < $rate1) {
                                //keep item2 and break foreach
                                $item1 = $item2;
                                break 2;
                            } else if ($rate1 < $rate2) break 2;
                            break;
                        case 'S':
                            //quite more seeds
                            if ($item2['seeds'] > $item1['seeds']) {
                                if ($item2['seeds'] >= $item1['seeds'] * 1.25) {
                                    //keep item2 and break foreach
                                    $item1 = $item2;
                                    break 2;
                                } else {
                                    //swap
                                    if (!$swapped) {
                                        $tmp = $item1;
                                        $item1 = $item2;
                                        $item2 = $tmp;
                                        $swapped = true;
                                        //continue foreach
                                    }
                                }
                            } else if ($item1['seeds'] >= $item2['seeds'] * 1.25) break 2;
                            break;
                        case 'P':
                            //quite more peers
                            if ($item2['peers'] > $item1['peers']) {
                                if ($item2['peers'] >= $item1['peers'] * 1.25) {
                                    //keep item2 and break foreach
                                    $item1 = $item2;
                                    break 2;
                                } else {
                                    //swap
                                    if (!$swapped) {
                                        $tmp = $item1;
                                        $item1 = $item2;
                                        $item2 = $tmp;
                                        $swapped = true;
                                        //continue foreach
                                    }
                                }
                            } else if ($item1['peers'] >= $item2['peers'] * 1.25) break 2;
                            break;
                        case 'L':
                            //larger sizes
                            if ($item2['size_raw'] > $item1['size_raw']) {
                                if ($item2['size_raw'] >= $item1['size_raw'] * 1.25) {
                                    //keep item2 and break foreach
                                    $item1 = $item2;
                                    break 2;
                                } else {
                                    //swap
                                    if (!$swapped) {
                                        $tmp = $item1;
                                        $item1 = $item2;
                                        $item2 = $tmp;
                                        $swapped = true;
                                        //continue foreach
                                    }
                                }
                            } else if ($item1['size_raw'] >= $item2['size_raw'] * 1.25) break 2;
                            break;
                        case 's':
                            //smaller sizes
                            if ($item2['size_raw'] < $item1['size_raw']) {
                                if ($item2['size_raw'] <= $item1['size_raw'] * 1.25) {
                                    //keep item2 and break foreach
                                    $item1 = $item2;
                                    break 2;
                                } else {
                                    //swap
                                    if (!$swapped) {
                                        $tmp = $item1;
                                        $item1 = $item2;
                                        $item2 = $tmp;
                                        $swapped = true;
                                        //continue foreach
                                    }
                                }
                            } else if ($item1['size_raw'] <= $item2['size_raw'] * 1.25) break 2;
                            break;
                    }
                }
                $aux[$i] = $item1;
                ++$wnd; //next item in group
            }
            $hashes[$aux[$i]['hash']] = true;   //save
            $i += $wnd; //next group
        }
    }

    return $hashes;
}

function run($p, $r, $q) {
	$channel = array();
	$params = explode('-', $p);
	$cin = array("ñ", "Ñ", "ç", "Ç", " ", ">", "<");
	$cout = array("%C3%B1", "%C3%91", "%C3%A7", "%C3%87", "+", "%3E", "%3C");
	$q = str_replace($cin, $cout, $q);
	
	$query = "http://torrentz.eu/" . $params[0] . "?q=" . $q;

	$page = 0;
	while (($sum = process_url($query.'&p='.$page, $channel)) != 0 && ($params[1] * 2 > $page)) {
        $GLOBALS['results'] += $sum;
		$page += 2;
	}
	
	$r = explode('-', $r);
	foreach ($r as $rule) {
		switch (substr($rule, 0, 1)) {
			case 'l':
				//limit results
				if (count($channel) > intval(substr($rule, 1))) {
					//echo "RULE: $rule : ".count($channel)." > ".intval(substr($rule, 1))."<br>\n";
					$channel = array_slice($channel, 0, intval(substr($rule, 1)));
				}
				break;
			case 'm':
				//merge
				$tiny = substr($rule, 1);
				if (strlen($tiny) > 10 && file_exists("data/".$tiny)) {
					$request = unserialize(file_get_contents("data/".$tiny));
					$aux = run($request['p'], $request['r'], $request['q']);
					$channel = array_merge($channel, $aux);
				}
				break;
			case 's':
				//limit results
				$arg = (substr($rule, 2, 1) == 'A') ? SORT_ASC : SORT_DESC;
				$field = '';
				switch (substr($rule, 1, 1)) {
					case 't': $field = 'title_lowercase'; break;
					case 'd': $field = 'pubtimestamp'; break;
					case 's': $field = 'size_raw'; break;
					case 'p': $field = 'peers'; break;
					case 'e': $field = 'seeds'; break;
					case 'l': $field = 'leechers'; break;
					case 'm': $field = 'seeds-leechers'; break;
				}
				$channel = array_orderby($channel, $field, $arg);
				break;
            case 'd':
                //Handle duplicates as movies
                $hashes = handleDuplicatesAsMovies($channel, $rule);
                $i = 0;
                while ($i < count($channel)) {
                    if (isset($hashes[$channel[$i]['hash']])) {
                        unset($hashes[$channel[$i]['hash']]);
                    } else {
                        unset($channel[$i]);
                    }
                    ++$i;
                }
                $channel = array_values($channel);  //reindex
                break;
		}
	}
	
	return $channel;
}
//dsSQ
//$params = explode('-', $_REQUEST['p']);
//$cin = array("ñ", "Ñ", "ç", "Ç", " ", ">", "<");
//$cout = array("%C3%B1", "%C3%91", "%C3%A7", "%C3%87", "+", "%3E", "%3C");
//$_REQUEST['q'] = str_replace($cin, $cout, $_REQUEST['q']);

if (!isset($_REQUEST['r']))
    $_REQUEST['r'] = '';

if (isset($_REQUEST['tiny'])) {
	$data = array('q' => $_REQUEST['q'], 'p' => $_REQUEST['p'], 'r' => $_REQUEST['r']);
	$sdata = serialize($data);
	$tiny = md5($sdata);
	file_put_contents("data/".$tiny, $sdata);
	echo $tiny;
} else if (isset($_REQUEST['stats'])) {
    header('Content-Type: application/json');
    echo json_encode(unserialize(file_get_contents("data/stats")), JSON_PRETTY_PRINT);
} else {

	$data['channel'] = array(
		"title" => RSSZ_SITE_NAME.' '.urldecode($_REQUEST['q']),
		"link"  => RSSZ_SITE_URL,
		"dataSource"  => "http://torrentz.eu/",
		"project"  => "https://github.com/Theadd/TorrentzRSS",
		"author"  => "Theadd",
		"version"  => "1.0",
		"license"  => "GPL v2",
		"ttl"  => RSSZ_TTL,
		"total" => 0,
		"excluded" => 0
	);

	$value = run($_REQUEST['p'], $_REQUEST['r'], $_REQUEST['q']);
	$data['channel'] = array_merge($data['channel'], $value);
	$data['channel']["total"] = count($value);
	$data['channel']["excluded"] = $results - $data['channel']["total"];

	if (isset($_REQUEST['f']) && strtolower($_REQUEST['f']) == 'json') {
		header('Content-Type: application/json');
		echo json_encode($data, JSON_PRETTY_PRINT);
	} else {

        $options = array(
            "indent"          => "    ",
            "linebreak"       => "\n",
            "typeHints"       => false,
            "addDecl"         => true,
            "encoding"        => "UTF-8",
            "rootName"        => "rss",
            "rootAttributes"  => array("version" => "2.0"),
            "defaultTagName"  => "item",
            "attributesArray" => "_attributes"
        );

		$serializer = new XML_Serializer($options);

		if ($serializer->serialize($data)) {
			header('Content-type: text/xml');
			echo $serializer->getSerializedData();
		}
	}

    $lockwait = 2;       // seconds to wait for lock
    $waittime = 250000;  // microseconds to wait between lock attempts
    // 2s / 250000us = 8 attempts.
    $statsfile = 'data/stats';

    if (!file_exists($statsfile)) {
        $stats = array('total' => 0, 'excluded' => 0, 'queries' => 0);
        file_put_contents($statsfile, serialize($stats));
    }
    //TODO: file_get_contents, fopen, flock, fwrite & fclose within bucle
    $stats = unserialize(file_get_contents($statsfile));
    if( $fh = fopen($statsfile, 'w+') ) {
        $waitsum = 0;
        // attempt to get exclusive, non-blocking lock
        $locked = flock($fh, LOCK_EX | LOCK_NB);
        while( !$locked && ($waitsum <= $lockwait) ) {
            $waitsum += $waittime/1000000; // microseconds to seconds
            usleep($waittime);
            $locked = flock($fh, LOCK_EX | LOCK_NB);
        }
        if( !$locked ) {
            //echo "Could not lock $statsfile for write within $lockwait seconds.";
        } else {
            $stats['queries']++;
            $stats['total'] += $data['channel']["total"];
            $stats['excluded'] += $data['channel']["excluded"];
            fwrite($fh, serialize($stats));
            flock($fh, LOCK_UN);  // ALWAYS unlock
        }
        fclose($fh);            // ALWAYS close your file handle
    } else {
        //echo "Could not open $statsfile";
    }
}

?>