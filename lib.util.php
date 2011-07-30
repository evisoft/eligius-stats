<?php
/*Copyright (C) 2011 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Artefact2\EligiusStats;

date_default_timezone_set('UTC');

const FRESH_BLOCK_THRESHOLD = 600;

/** The actual true difficulty of a share.
 * Result of 0x00000000ffff0000000000000000000000000000000000000000000000000000 (target for difficulty 1)
 *           /
 *           0x00000000ffffffffffffffffffffffffffffffffffffffffffffffffffffffff (our target for a share)
 */
const SHARE_DIFF = 0.999984741210937500000000000000000000000000000000000000000000000000037091495526931787187794383295520714646689355129378026328393541675746568232157348440782664046922186356528849561891415710665458082566072220046505;

const COLOR_ALREADY_PAID = '00411C';
const COLOR_UNPAID = '31926B';
const COLOR_CURRENT_BLOCK = '3F8FD2';
const COLOR_CREDIT = 'FF9999';
const COLOR_THRESHOLD = 'FF0000';

const COLOR_HASHRATE = 'F36D91';
const COLOR_SHORTAVG = '00AC6B';
const COLOR_LONGAVG = '007046';

const DATA_RELATIVE_ROOT = 'json';
const DATA_SUFFIX = '.json';

/**
 * This is a safe wrapper for json_decode.
 * @param string $file filename, or raw JSON data
 * @param bool $getFile if false, $file actually contains the raw data to be decoded.
 * @return bool|mixed|string false if an error happened, or decoded JSON data.
 */
function json_decode_safe($file, $getFile = true) {
	if($getFile) $data = file_get_contents($file);
	else $data = $file;
	$data = json_decode($data, true);
	if(($err = json_last_error()) !== JSON_ERROR_NONE) {
		trigger_error('Call to json_decode('.($getFile ? $file : '').') failed : '.$err, E_USER_WARNING);
		return false;
	}
	return $data;
}

/**
 * This is a safe wrapper for json_encode.
 * @param array $data the data to JSON-ize.
 * @param null $toFile if not-null, the JSON will be written to this file instead of being returned.
 * @return bool|string false if an error happened, or raw JSON data
 */
function json_encode_safe($data, $toFile = null) {
	$json = json_encode($data);
	if(($err = json_last_error()) !== JSON_ERROR_NONE) {
		trigger_error('Call to json_encode('.($toFile != null ? $toFile : '').') failed : '.$err, E_USER_WARNING);
		return false;
	}

	if($toFile !== null) {
		return file_put_contents($toFile, $json) !== false;
	} else return $json;
}

/**
 * Truncate a data file (ie, delete all its contents).
 * @param string $type the type of the data, one of the T_ constants.
 * @param string $identifier an unique identifier for this data (can be an address, or a pool name, …).
 * @return bool true if the operation succeeded
 */
function truncateData($type, $identifier) {
	$file = __DIR__.'/'.DATA_RELATIVE_ROOT.'/'.$type.'_'.$identifier.DATA_SUFFIX;
	if(file_exists($file)) return unlink($file);
	else return true;
}

/**
 * Append new values to a data file.
 * @param string $type the type of the data, one of the T_ constants.
 * @param string $identifier an unique identifier for this data (can be an address, or a pool name, …).
 * @param array $entries entries to add (array of array(date, value))
 * @param integer $maxTimespan defines after how many seconds the data is considered obsolete and deleted.
 * @param bool $tryRepair if true, will try to call tryRepairJson() on the file if it is corrupted.
 * @return bool true if the operation succeeded.
 */
function updateDataBulk($type, $identifier, $entries, $maxTimespan = null, $tryRepair = true) {
	$file = __DIR__.'/'.DATA_RELATIVE_ROOT.'/'.$type.'_'.$identifier.DATA_SUFFIX;
	if(file_exists($file)) {
		$data = json_decode_safe($file);
		if($data === false) {
			tryRepairJson($file);
			return updateDataBulk($type, $identifier, $entries, $maxTimespan, false);
		}
	} else {
		$data = array();
	}
	$c = count($data);

	// Ensure chronological order
	usort($entries, function($a, $b) { return $a[0] - $b[0]; });

	if(count($entries) >= 1 && $c >= 1 && $data[$c - 1][0] > 1000 * $entries[0][0]) {
		if($tryRepair) {
			tryRepairJson($file);
			return updateDataBulk($type, $identifier, $entries, $maxTimespan, false);
		}
		trigger_error('New data to be inserted must be newer than the latest point.', E_USER_WARNING);
		return false;
	}
	foreach($entries as $entry) {
		$data[] = array((float)(1000 * $entry[0]), (float)($entry[1]));
	}

	// Wipe out old values from the array
	$threshold = bcmul(time() - $maxTimespan, 1000, 0);
	for($i = 0; $i < $c; ++$i) {
		if($i < ($c - 1) && $data[$i][0] < $threshold && $data[$i + 1][0] < $threshold) {
			unset($data[$i]);
			continue;
		}

		if($data[$i][0] < $threshold) {
			// We have now only one point that's too far in the past. We move him right at the boundary, to avoid
			// losing information.
			$data[$i][0] = $threshold;
		}

		break;
	}

	$data = array_values($data);
	return json_encode_safe($data, $file);
}

/**
 * Append a new value to a data file.
 * @param string $type the type of the data, one of the T_ constants.
 * @param string $identifier an unique identifier for this data (can be an address, or a pool name, …).
 * @param null|int $date if null, current date is assumed
 * @param float $value the value to insert
 * @param integer $maxTimespan defines after how many seconds the data is considered obsolete and deleted.
 * @param bool $tryRepair if true, will try to call tryRepairJson() on the file if it is corrupted.
 * @return bool true if the operation succeeded.
 */
function updateData($type, $identifier, $date = null, $value = null, $maxTimespan = null, $tryRepair = true) {
	if($date === null && $value === null) $data = array();
	else if($date === null) {
		$date = time();
		$data = array(array($date, $value));
	}

	return updateDataBulk($type, $identifier, $data, $maxTimespan, $tryRepair);
}

/**
 * Try to auto-correct corrupted or malformed JSON files.
 * @param string $file which file to repair
 * @return bool true if an attempt was made to recover the JSON file.
 */
function tryRepairJson($file) {
	$contents = file_get_contents($file);
	if(strpos($contents, "]]") === false && strlen($contents) > 1) {
		$lastBracket = strrpos($contents, ',[');
		$contents = substr($contents, 0, $lastBracket).']';
		return file_put_contents($file, $contents) !== false;
	}

	if(strpos($contents, "]]]") !== false) {
		$contents = str_replace("]]]", "]]", $contents);
		return file_put_contents($file, $contents) !== false;
	}

	$split = explode("]]", $contents);
	if(count($split) >= 2) {
		$contents = $split[0]."]]";
		return file_put_contents($file, $contents) !== false;
	}

	$data = json_decode_safe($contents, false);
	$newData = array();
	$hadError = false;
	$previousDate = -1;
	$now = time();
	foreach($data as $d) {
		if(count($d) == 2) {
			if($d[0] < $previousDate) $hadError = true;
			$previousDate = $d[0];

			if($d[0] / 1000 > $now) {
				$hadError = true;
				continue;
			}

			$newData[] = $d;
		}
		else {
			$hadError = true;
		}
	}
	if($hadError) {
		$newData = usort($newData, function($a, $b) { return $b[0] - $a[0]; });

		return json_encode_safe($newData, $file);
	}

	trigger_error("Could not repair $file.", E_USER_WARNING);
	return false;
}

/**
 * Convert a money amount from Satoshis to BTC.
 * @param string|integer $satoshi the amount in Satoshis
 * @return string the specified amount, in BTC.
 */
function satoshiToBTC($satoshi) {
	return bcmul($satoshi, '0.00000001', 8).' BTC';
}

/**
 * Convert a money amount from Satoshis to TBC.
 * @param string|integer $satoshi amount of satoshis
 * @return string specified amount in TBC
 */
function satoshiToTBC($satoshi) {
	static $tonalAlphabet = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '', '9', '', '', '', '', '');
	$rem = bcmod($satoshi, '65536');
	$satoshi = bcdiv($satoshi, '65536', 0);
	$result = '';
	$i = 0;
	while($satoshi) {
		++$i;

		$mod = bcmod($satoshi, '16');
		$satoshi = bcdiv($satoshi, '16', 0);

		$result = $tonalAlphabet[intval($mod)].$result;
	}

	if($result == '') $result = '0';

	$dec = '';
	while($rem) {
		$mod = bcmod($rem, '16');
		$rem = bcdiv($rem, '16', 0);

		$dec = $tonalAlphabet[intval($mod)].$dec;
	}

	$dec = str_pad($dec, 4, '0', STR_PAD_RIGHT);
	if($dec != '') $result .= '.'.$dec;

	if($result == '') $result = '0.0000';

	return '<span class="tfix">'.$result.'</span> TBC';
}

/**
 * What unit should we use ?
 * @return string TBC or BTC, according to settings.
 */
function getPrefferedMonetaryUnit() {
	if(isset($_COOKIE['a2_tbc']) && $_COOKIE['a2_tbc']) {
		return 'TBC';
	} else return 'BTC';
}

/**
 * Format an amount of satoshis in the currently preffered unit.
 * @param string|integer $satoshis amount of satoshis
 * @return string formatted amount
 */
function prettySatoshis($satoshis) {
	if(getPrefferedMonetaryUnit() == 'TBC') {
		return satoshiToTBC($satoshis);
	} else return satoshiToBTC($satoshis);
}

/**
 * Format a BTC amount in BTC.
 * @param int|float|string $btc amount of BTC
 * @return string formatted amount
 */
function prettyBTC($btc) {
	return prettySatoshis(bcadd(0, bcdiv($btc, '0.00000001', 8), 0));
}

/**
 * Convert from satoshis to BTC, but output the raw result as a floating number (with no " BTC" suffix).
 * @param string|int|float $s amount of satoshis
 * @return float raw formatted amount (no suffix)
 */
function rawSatoshiToBTC($s) {
	return bcmul($s, '0.00000001', 8);
}

/**
 * Get the preffered BlockExplorer-like URI.
 */
function getBE() {
	return ((getPrefferedMonetaryUnit() == 'TBC') ? 'tonal.pident.artefact2.com' : 'pident.artefact2.com');
}

/**
 * Format a duration in a human-readable way.
 * @param int|float $duration the time, in seconds, to format
 * @param bool $align whether we should align the components.
 * @return string a human-readable version of the same duration
 */
function prettyDuration($duration, $align = false, $precision = 4) {
	if($duration < 60) return "a few seconds";
	else if($duration < 300) return "a few minutes";

	$units = array("month" => 30.5 * 86400, "week" => 7*86400, "day" => 86400, "hour" => 3600, "minute" => 60);

	$r = array();
	foreach($units as $u => $d) {
		$num = floor($duration / $d);
		if($num >= 1) {
			$plural = ($num > 1 ? 's' : ($align ? '&nbsp;' : ''));
			if($align && count($r) > 0) {
				$num = str_pad($num, 2, '_', STR_PAD_LEFT);
				$num = str_replace('_', '&nbsp;', $num);
			}
			$r[] = $num.' '.$u.$plural;
			$duration %= $d;
		}
	}

	$prefix = '';
	while(count($r) > $precision) {
		$prefix = 'about ';
		array_pop($r);
	}

	if(count($r) > 1) {
		$ret = array_pop($r);
		$ret = implode(', ', $r).' and '.$ret;
		return $prefix.$ret;
	} else return $prefix.$r[0];
}

/**
 * Format a hashrate in a human-readable fashion.
 * @param int|float|string $hps the number of hashes per second
 * @return string a formatted rate
 */
function prettyHashrate($hps) {
	if($hps < 10000000) {
		return number_format($hps / 1000, 2).' KH/s';
	} else if($hps < 10000000000) {
		return number_format($hps / 1000000, 2).' MH/s';
	} else return number_format($hps / 1000000000, 2).' GH/s';
}

/**
 * Extract a not-too-dark, not-too-light color from anything.
 * @param mixed $seed the seed to extract the color from.
 * @return string a color in the rgb($r, $g, $b) format.
 */
function extractColor($seed) {
	global $COLOR_OVERRIDES;

	if(isset($COLOR_OVERRIDES[$seed])) {
		return $COLOR_OVERRIDES[$seed];
	}

	static $threshold = 100;

	$d = sha1($seed);

	$r = hexdec(substr($d, 0, 2));
	$g = hexdec(substr($d, 2, 2));
	$b = hexdec(substr($d, 4, 2));

	if($r + $g + $b < $threshold || $r + $g + $b > (3*255 - $threshold)) return extractColor($d);
	else return "rgb($r, $g, $b)";
}

/**
 * Neatly formats a (large) integer.
 * @param integer $i integer to format
 * @return string the formatted integer
 */
function prettyInt($i) {
	return number_format($i, 0, '.', ',');
}

/**
 * Get the formatted number of seconds, minutes and hours from a duration.
 * @param integer $d the duration (number of seconds)
 * @return array array($seconds, $minutes, $hours)
 */
function extractTime($d) {
	$seconds = $d % 60;
	$minutes = (($d - $seconds) / 60) % 60;
	$hours = ($d - 60 * $minutes - $seconds) / 3600;
	if($seconds) {
		$seconds .= 's';
	} else $seconds = '';
	if($minutes) {
		$minutes .= 'm';
	} else $minutes = '';
	if($hours) {
		$hours .= 'h';
	} else $hours = '';
	if($hours && $minutes == '') {
		$minutes = '0m';
	}
	if(($hours || $minutes) && $seconds == '') {
		$seconds = '0s';
	}

	return array($seconds, $minutes, $hours);
}

/**
 * Print the basic xHTML header of a page.
 * @param string $title the title written in <title>
 * @param string $shownTitle the title written in <h1>
 * @param string $relativePathToRoot the path to get to the root from this page, WITHOUT any trailing /
 * @param bool $includeJquery if true, include the JQuery javascript files.
 * @param bool $wColorPicker should we include the colorPicker deps too?
 * @return void
 */
function printHeader($title, $shownTitle, $relativePathToRoot = '.', $includeJquery = true, $wColorPicker = false) {
	$millis = 1000 * microtime(true) + 300; /* ~0.3s page load time */
	$shareDiff = SHARE_DIFF;
	echo <<<EOT
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<link type="text/css" rel="stylesheet" href="$relativePathToRoot/web.theme.css">

EOT;

	if($includeJquery) echo <<<EOT
<!--[if lte IE 8]><script type="text/javascript" src="$relativePathToRoot/flot/excanvas.min.js"></script><![endif]-->
<script type="text/javascript" src="$relativePathToRoot/canvas-text/canvas.text.js"></script>
<script type="text/javascript" src="$relativePathToRoot/lib.util.js"></script>
<script type="text/javascript" src="$relativePathToRoot/flot/jquery.min.js"></script>
<script type="text/javascript" src="$relativePathToRoot/flot/jquery.flot.min.js"></script>
<script type="text/javascript" src="$relativePathToRoot/flot/jquery.flot.stack.min.js"></script>
<script type="text/javascript" src="$relativePathToRoot/flot/jquery.flot.selection.min.js"></script>
<script type="text/javascript" src="$relativePathToRoot/jquery-cookie/jquery.cookie.js"></script>
<script type="text/javascript">
var __clockOffset = $millis - new Date().getTime();
var __shareDiff = $shareDiff;
EligiusUtils.twitterStuff("#tw");
</script>

EOT;

	if($wColorPicker) echo <<<EOT
<link rel="stylesheet" media="screen" type="text/css" href="$relativePathToRoot/colorpicker/css/colorpicker.css" />
<script type="text/javascript" src="$relativePathToRoot/colorpicker/js/colorpicker.js"></script>
EOT;

	echo <<<EOT
<title>$title</title>
</head>
<body>
<h1>$shownTitle <span id="tw"></span></h1>

EOT;

	if(file_exists(__DIR__.'/inc.announcement.php')) {
		echo "<p><a id=\"announcement_toggle\" href=\"javascript:void(0);\" onclick=\"EligiusUtils.toggleAnnouncementVisibility();\">Toggle announcements visibility</a></p>";
		require __DIR__.'/inc.announcement.php';
	}

	if($includeJquery) echo <<<EOT
<script type="text/javascript">
EligiusUtils.maybeHideAnnouncements();
</script>

EOT;

	if(!isset($_SESSION['_messages'])) return;
	foreach($_SESSION['_messages'] as $m) {
		list($type, $content) = $m;
		echo "<p class=\"msg $type\">$content</p>\n";
	}

	$_SESSION['_messages'] = array();
}

/**
 * Print the footer of any xHTML page.
 * @param string $relative the path to get to the root from this page, WITHOUT any trailing /
 * @param string $more optional text to print in the footer
 * @return void
 */
function printFooter($relative, $more = '') {
	$now = date('Y-m-d \a\t H:i:s');
	echo <<<EOT
<footer>
<hr />
<p style="float: right;">
Page generated the $now UTC -
<a href="$relative/noauthSettings">Local settings</a> -
<a href="https://github.com/Artefact2/eligius-stats">Source</a> -
<a href="http://eligius.st/">Eligius Wiki</a> -
Donate to <a href="bitcoin:1666R5kdy7qK2RDALPJQ6Wt1czdvn61CQR">1666R5kdy7qK2RDALPJQ6Wt1czdvn61CQR</a> !
</p>
EOT;
	if(file_exists(__DIR__.'/inc.analytics.php')) {
		require __DIR__.'/inc.analytics.php';
	}
	if($relative != '.' || (strrpos($_SERVER['REQUEST_URI'], '/') + 1) != strlen($_SERVER['REQUEST_URI'])) {
		echo "<p><a href=\"$relative/\">&larr; Get back to the main page</a></p>\n";
	}
	echo <<<EOT
$more
</footer>
</body>
</html>

EOT;
}

/**
 * Format the name of an invalid share.
 * @param string $reason the reason why the share was invalid.
 * @return string formatted name
 */
function prettyInvalidReason($reason) {
	if($reason == 'unknown-work') {
		return 'corrupted share';
	} else return "$reason share";
}

/**
 * Format the status of a block.
 * @param mixed $s the status of the block.
 * @param int $when when was the block found?
 * @return string formatted block status.
 */
function prettyBlockStatus($s, $when = null) {
	if($s === null) {
		return '<td class="unconfirmed conf9" title="There is no bitcoin node available at the moment to check the status of this block."><span>???</span></td>';	
	} else if($when !== null && (time() - $when) < FRESH_BLOCK_THRESHOLD) {
		return '<td class="unconfirmed conf9" title="It is too soon to try to determine the status of this block."><span>???</span></td>';
	} else if($s === true) {
		return '<td>Confirmed</td>';
	} else if(is_numeric($s)) {
		$opacity = (int)floor(10 * $s / NUM_CONFIRMATIONS);
		if($opacity > 9) $opacity = 9;
		return '<td class="unconfirmed conf'.$opacity.'" title="'.$s.' confirmations left"><span>'.$s.' left</span></td>';
	} else if($s === false) {
		return '<td>Invalid</td>';
	} else {
		return '<td class="unconfirmed conf9" title="Unknown status"><span>Unknown</span></td>';
	}
}


/**
 * Show an error in the next printed header.
 * @param string $e error to display
 * @return void
 */
function addError($e) {
	$_SESSION['_messages'][] = array('error', $e);
}

/**
 * Show a message in the next printed header.
 * @param string $m message to display
 * @return void
 */
function addMessage($m) {
	$_SESSION['_messages'][] =  array('message', $m);
}

/**
 * Get the CDF given the number of submitted shares.
 * @param integer $shares the number of submitted shares
 * @param float $difficulty the current difficulty
 * @return float the probability that a block should have been found given this number of shares
 */
function getCDF($shares, $difficulty) {
	return 1.0 - exp(- SHARE_DIFF * $shares / $difficulty);
}

/**
 * Establish a connection to the SQL server.
 * @note this is called automatically, don't use it !
 */
function sqlConnect() {
	static $link = null;
	if($link !== null) return;

	$host = SQL_HOST;
	$user = SQL_USER;
	$pass = SQL_PASSWORD;
	$db = SQL_DB;
	$link = pg_connect("host='$host' dbname='$db' user='$user' password='$pass'");
}

/**
 * Execute a SQL query.
 * @return \resource
 */
function sqlQuery() {
	sqlConnect();

	$args = func_get_args();
	$query = array_shift($args);

	$r = pg_query_params($query, $args);
	if($r === false) {
		trigger_error("The following query failed : \n$query\n".pg_last_error()."\n", E_USER_WARNING);
	}
	return $r;
}

/**
 * Get the next row of a result returned by sqlQuery();
 * @param \resource $r resource returned by sqlQuery()
 * @return array row of data
 */
function fetchAssoc($r) {
	return pg_fetch_assoc($r);
}

function sqlTime($epoch) {
	return date('Y-m-d H:i:s', $epoch);
}

function hex2bits($hex) {
	if($hex == '') return '';

	static $trans = array(
		'0' => '0000',
		'1' => '0001',
		'2' => '0010',
		'3' => '0011',
		'4' => '0100',
		'5' => '0101',
		'6' => '0110',
		'7' => '0111',
		'8' => '1000',
		'9' => '1001',
		'a' => '1010',
		'b' => '1011',
		'c' => '1100',
		'd' => '1101',
		'e' => '1110',
		'f' => '1111',
	);

	$bits = '';
	$digits = str_split(strtolower($hex), 1);

	foreach($digits as $d) {
		$bits .= $trans[$d];
	}

	return $bits;
}

function bits2hex($bits) {
	if($bits == '') return '';

	static $trans = array(
		'0000' => '0',
		'0001' => '1',
		'0010' => '2',
		'0011' => '3',
		'0100' => '4',
		'0101' => '5',
		'0110' => '6',
		'0111' => '7',
		'1000' => '8',
		'1001' => '9',
		'1010' => 'a',
		'1011' => 'b',
		'1100' => 'c',
		'1101' => 'd',
		'1110' => 'e',
		'1111' => 'f',
	);

	$hex = '';
	$digits = str_split($bits, 4);

	foreach($digits as $d) {
		$hex .= $trans[$d];
	}

	return $hex;
}
