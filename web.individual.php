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

require __DIR__.'/lib.eligius.php';
require __DIR__.'/inc.servers.php';

function makeTicks() {
	$now = time();
	$now -= $now % 86400;
	$threshold = $now - TIMESPAN_SHORT + 1;

	$ticks = array();
	while($now > $threshold) {
		$ticks[] = bcmul($now, 1000, 0);
		$now -= 86400;
	}

	$ticks = array_reverse($ticks);
	return '['.implode(', ', $ticks).']';
}

function showHashrateAverage($server, $address) {
	$averages_long = cacheFetch('average_hashrates_long', $success0);
	$averages_short = cacheFetch('average_hashrates_short', $success1);

	echo "<h2>Hashrates</h2>\n<table>\n<thead><tr><th></th><th>".HASHRATE_AVERAGE_HR." average</th><th>".HASHRATE_AVERAGE_SHORT_HR." average</th></tr>\n</thead>\n<tbody>\n";

	if(!$success0 || !$success1 || count($averages_short) == 0) {
		echo "<tr><td>Hashrate</td><td><small>N/A</small></td><td><small>N/A</small></td></tr>\n";
		echo "<tr><td>Submitted shares</td><td><small>N/A</small></td><td><small>N/A</small></td></tr>\n";
		$error = "<p>The averages are not available at the moment. <strong class=\"more\">The graphed data below may be wrong.</strong> Try later !</p>\n";
	} else {
		$short = isset($averages_short['valid'][$server][$address]) ? prettyHashrate($averages_short['valid'][$server][$address][1]) : prettyHashrate(0);
		$long = isset($averages_long['valid'][$server][$address]) ? prettyHashrate($averages_long['valid'][$server][$address][1]) : prettyHashrate(0);
		$sharesShort = isset($averages_short['valid'][$server][$address]) ? $averages_short['valid'][$server][$address][0] : 0;
		$sharesLong = isset($averages_long['valid'][$server][$address]) ? $averages_long['valid'][$server][$address][0] : 0;
		$rejectedSharesShort = isset($averages_short['invalid'][$server][$address]) ? array_sum($averages_short['invalid'][$server][$address]) : 0;
		$rejectedSharesLong = isset($averages_long['invalid'][$server][$address]) ? array_sum($averages_long['invalid'][$server][$address]) : 0;
		if($sharesShort == 0) {
			$sClass = ' class="warn"';
		} else $sClass = '';
		if($sharesLong == 0) {
			$lClass = ' class="warn"';
		} else $lClass = '';

		if($sharesShort == 0) $rejectedSharesShortPercentage = '<small>N/A</small>';
		else $rejectedSharesShortPercentage = number_format(100 * ($rejectedSharesShort) / ($rejectedSharesShort + $sharesShort), 2). ' %';
		if($sharesLong == 0) $rejectedSharesLongPercentage = '<small>N/A</small>';
		else $rejectedSharesLongPercentage = number_format(100 * ($rejectedSharesLong) / ($rejectedSharesLong + $sharesLong), 2). ' %';

		$reasons = array();
		if(isset($averages_long['invalid'][$server][$address]) && $rejectedSharesLong > 0) {
			foreach($averages_long['invalid'][$server][$address] as $reason => $count) {
				$reasons[$reason]['long'] = $count;
			}
		}
		if(isset($averages_short['invalid'][$server][$address]) && $rejectedSharesShort > 0) {
			foreach($averages_short['invalid'][$server][$address] as $reason => $count) {
				$reasons[$reason]['short'] = $count;
			}
		}
		if(count($reasons) > 0) {
			$rjClass = ' class="tsep" style="border-color: #888;"';
		} else $rjClass = '';

		$sharesLong = prettyInt($sharesLong);
		$sharesShort = prettyInt($sharesShort);

		echo "<tr><td>Hashrate</td><td$lClass><strong class=\"moremore\">$long</strong></td><td$sClass>$short</td></tr>\n";
		echo "<tr class=\"bsep\"><td>Submitted valid shares</td><td$lClass>$sharesLong</td><td$sClass>$sharesShort</td></tr>\n";

		foreach($reasons as $reason => $d) {
			$S = isset($d['short']) ? prettyInt($d['short']) : 0;
			$L = isset($d['long']) ? prettyInt($d['long']) : 0;
			$reason = prettyInvalidReason($reason).'s';
			echo "<tr><td>Submitted $reason</td><td>$L</td><td>$S</td></tr>\n";
		}

		$rejectedSharesLong = prettyint($rejectedSharesLong);
		$rejectedSharesShort = prettyInt($rejectedSharesShort);

		echo "<tr$rjClass><td>Total submitted invalid shares</td><td>$rejectedSharesLong ($rejectedSharesLongPercentage)</td><td>$rejectedSharesShort ($rejectedSharesShortPercentage)</td></tr>\n";
	}

	echo "</tbody>\n</table>\n";

	if(isset($error)) {
		echo "<div class=\"errors\">$error</div>\n";
	}
}

function showBalance($unpaid, $current) {
	$thresh = '0.33554432';
	$fThresh = prettyBTC($thresh);

	echo <<<EOT
<h2>Balance</h2>
<ul>
<li>Unpaid reward : <strong class="moremore">$unpaid</strong><br />
This is the reward you earned by contributing to the previous blocks. This amount will be paid to you when it reaches approximately $fThresh or after one week of inactivity, when the pool finds a block.</li>
<li>Current block estimate : <strong class="moremore">$current</strong><br />
This is an <strong>estimation</strong> of the reward that you will earn when the pool finds the block it is currently working on.</li>
</ul>
EOT;
}

function showBalanceGraph($server, $address) {
	$unit = getPrefferedMonetaryUnit();
	$paidUri = '../'.DATA_RELATIVE_ROOT.'/'.T_BALANCE_ALREADY_PAID.'_'.$server.'_'.$address.DATA_SUFFIX;
	$unpaidUri = '../'.DATA_RELATIVE_ROOT.'/'.T_BALANCE_UNPAID_REWARD.'_'.$server.'_'.$address.DATA_SUFFIX;
	$currentUri = '../'.DATA_RELATIVE_ROOT.'/'.T_BALANCE_CURRENT_BLOCK.'_'.$server.'_'.$address.DATA_SUFFIX;
	$creditUri = '../'.DATA_RELATIVE_ROOT.'/'.T_BALANCE_CREDIT.'_'.$server.'_'.$address.DATA_SUFFIX;

	$defPaid = COLOR_ALREADY_PAID;
	$defUnpaid = COLOR_UNPAID;
	$defCurrent = COLOR_CURRENT_BLOCK;
	$defThresh = COLOR_THRESHOLD;
	$defCredit = COLOR_CREDIT;

	if(getPrefferedMonetaryUnit() == 'TBC') {
		$font = ', font: { style: "normal", variant: "normal", weight: "normal", size: 10, family: "LuxiMonoTonal" } ';
	} else $font = '';

	echo "<div class=\"graph\">\n<div id=\"eligius_balance_errors\" class=\"errors\"></div>\n";
	echo "<div id=\"eligius_balance\" style=\"width:700px;height:350px;\">You must enable Javascript to see the graph.</div>\n</div>\n";
	echo "<script type=\"text/javascript\">\n$(function () {\n";
	echo "$('#eligius_balance').html('');\nvar series = [];\n";
	echo <<<EOT
var options = {
	legend: { position: "nw" },
	xaxis: { mode: "time", ticks: 5 },
	yaxis: { position: "right", tickFormatter: EligiusUtils.format$unit $font },
	series: { lines: { fill: 0.3, steps: true }, stack: true },
	selection: { mode: "xy" },
	grid: { markings: [] }
};

var cPaid = '#' + (localStorage['a2_alreadypaid'] ? localStorage['a2_alreadypaid'] : '$defPaid');
var cUnpaid = '#' + (localStorage['a2_unpaid'] ? localStorage['a2_unpaid'] : '$defUnpaid');
var cCurrent = '#' + (localStorage['a2_current'] ? localStorage['a2_current'] : '$defCurrent');
var cThresh = '#' + (localStorage['a2_threshold'] ? localStorage['a2_threshold'] : '$defThresh');
var cCredit = '#' + (localStorage['a2_credit'] ? localStorage['a2_credit'] : '$defCredit');

$.get("$paidUri", "", function(data, textStatus, xhr) {
	var alreadyPaid = data;
	options.yaxis.min = data[EligiusUtils.findDataMin(data)][1];
	series.push({ data: data, label: "Already paid", color: cPaid, lines: { lineWidth: 0.5 } });
	$.plot($('#eligius_balance'), series, options);

	$.get("$unpaidUri", "", function(data, textStatus, xhr) {
		var unpaid = data;
		series.push({ data: data, label: "Unpaid reward", color: cUnpaid, lines: { lineWidth: 0.5 } });
		$.plot($('#eligius_balance'), series, options);

		$.get("$currentUri", "", function(data, textStatus, xhr) {
			var currentEstimate = data;
			options.yaxis.max = data[EligiusUtils.findDataMax(data)][1] + alreadyPaid[alreadyPaid.length - 1][1] + unpaid[unpaid.length - 1][1];
			options.yaxis.min = Math.max(0, options.yaxis.min - (options.yaxis.max - options.yaxis.min) * 0.05);
			options.yaxis.max = options.yaxis.max + (options.yaxis.max - options.yaxis.min) * 0.10;
			series.push({ data: data, label: "Current block estimate", color: cCurrent  });
			options.grid.markings = EligiusUtils.nightMarkings(
				Math.min(alreadyPaid[0][0], unpaid[0][0], currentEstimate[0][0]),
				Math.max(alreadyPaid[alreadyPaid.length - 1][0], unpaid[unpaid.length - 1][0], currentEstimate[currentEstimate.length - 1][0])
			);
			$.plot($('#eligius_balance'), series, options);

			series.push({ data: EligiusUtils.splitHorizontalLine(EligiusUtils.shiftData(alreadyPaid, 0.33554432)), label: "Payout threshold", color: cThresh, lines: { fill: false }, stack: false });

			var maxZoomT = 600000;
			var maxZoomY = 0.005;
			$("#eligius_balance").bind("plotselected", function (event, ranges) {
				if (ranges.xaxis.to - ranges.xaxis.from < maxZoomT)
					ranges.xaxis.to = ranges.xaxis.from + maxZoomT;
				if (ranges.yaxis.to - ranges.yaxis.from < maxZoomY)
					ranges.yaxis.to = ranges.yaxis.from + maxZoomY;

				plot = $.plot($("#eligius_balance"), series,
					$.extend(true, {}, options, {
						xaxis: { min: ranges.xaxis.from, max: ranges.xaxis.to },
						yaxis: { min: ranges.yaxis.from, max: ranges.yaxis.to }
				}));
			});

			$("#eligius_balance").dblclick(function() {
				$.plot($('#eligius_balance'), series, options);
			});

			$.get("$creditUri", "", function(data, textStatus, xhr) {
				series.push({ data: data, label: "Maximum reward", color: cCredit, lines: { lineWidth: 0 }  });
				$.plot($('#eligius_balance'), series, options);
			}).error(function() {
				$('#eligius_balance_errors').append('<p>An error happened while loading the "credit" data.<br />Try reloading the page.</p>');
			});
		}, "json").error(function() {
			$('#eligius_balance_errors').append('<p>An error happened while loading the "current block estimate" data.<br />Try reloading the page.</p>');
		});

	}, "json").error(function() {
		$('#eligius_balance_errors').append('<p>An error happened while loading the "unpaid reward" data.<br />Try reloading the page.</p>');
	});

}, "json").error(function() {
	$('#eligius_balance_errors').append('<p>An error happened while loading the "already paid" data.<br />Try reloading the page.</p>');
});
EOT;

	echo "});\n</script>\n";
}

function showRecentPayouts($server, $address) {
	echo "<h2>Recent blocks and rewards</h2>\n";
	$now = time();

	echo "<table id=\"rfb_indiv\">\n<thead>\n<tr><th>▼ When</th><th colspan=\"3\">Round duration</th><th>Submitted shares</th><th>Total shares</th><th>Reward per share</th><th>Reward</th><th>Status</th><th>Block</th></tr>\n</thead>\n<tbody>\n";

	$success = true;
	$recent = cacheFetch('blocks_recent_'.$server, $success);

	if(!$success) {
		echo "<tr><td><small>N/A</small></td><td colspan=\"3\"><small>N/A</small></td><td><small>N/A</small></td><td><small>N/A</small></td><td><small>N/A</small></td><td><small>N/A</small></td><td><small>N/A</small></td></tr>\n";
	} else {
		$a = 0;
		foreach($recent as $r) {
			$a = ($a + 1) % 2;

			$hash = strtoupper($r['hash']);

			$when = prettyDuration($now - $r['when'], false, 1).' ago';
			$shares = $r['shares_total'];
			if($shares > 0) {
				$myShares = isset($r['shares'][$address]) ? $r['shares'][$address] : 0;
				$rps = (isset($r['metadata']['funds'])) ? prettySatoshis($r['metadata']['paid'] / $shares) : '<small>N/A</small>';
				$shares = prettyInt($shares);
				$myShares = prettyInt($myShares);
			} else {
				$shares = '<small>N/A</small>';
				$myShares = '<small>N/A</small>';
				$rps = '<small>N/A</small>';
			}
			$block = '<a href="http://'.getBE().'/block/'.$r['hash'].'" title="'.$hash.'">…'.substr($hash, -25).'</a>';

			if($address !== null) {
				if(isset($r['valid']) && $r['valid'] === false) {
					$reward = '<td>'.prettyBTC(0).'</td>';
				} else {
					$reward = '<td>'.(isset($r['rewards'][$address]) ? prettyBTC($r['rewards'][$address]) : prettyBTC(0)).'</td>';
				}
			}

			if(isset($r['duration'])) {
				list($seconds, $minutes, $hours) = extractTime($r['duration']);
				$duration = "<td class=\"ralign\" style=\"width: 1.5em;\">$hours</td><td class=\"ralign\" style=\"width: 1.5em;\">$minutes</td><td class=\"ralign\" style=\"width: 1.5em;\">$seconds</td>";
			} else {
				$duration = "<td colspan=\"3\"><small>N/A</small></td>";
			}

			$status = prettyBlockStatus($r['valid'], $r['when']);
			echo "<tr class=\"row$a\"><td>$when</td>$duration<td class=\"ralign\">$myShares</td><td class=\"ralign\">$shares</td><td class=\"ralign\">$rps</td>$reward$status<td class=\"ralign\">$block</td></tr>\n";
		}

		$a = ($a + 1) % 2;
		echo "<tr class=\"row$a\"><td colspan=\"10\"><a href=\"../blocks/".htmlspecialchars($address)."\">Show more for this address…</a></td></tr>\n";
	}

	echo "</tbody>\n</table>\n";
}

function showHashRateGraph($server, $address) {
	$uri = '../'.DATA_RELATIVE_ROOT.'/'.T_HASHRATE_INDIVIDUAL.'_'.$server.'_'.$address.DATA_SUFFIX;
	//$ticks = makeTicks();
	$interval = HASHRATE_PERIOD * 1000;

	$cHashrate = COLOR_HASHRATE;
	$cShort = COLOR_SHORTAVG;
	$cLong = COLOR_LONGAVG;

	echo "<div class=\"graph\">\n<div id=\"eligius_indiv_hashrate_errors\" class=\"errors\"></div>\n";
	echo "<div id=\"eligius_indiv_hashrate\" style=\"width:750px;height:350px;\">You must enable Javascript to see the graph.</div>\n</div>\n";
	echo "<script type=\"text/javascript\">\n$(function () {\n";
	echo "$('#eligius_indiv_hashrate').html('');\nvar series = [];\n";
	echo <<<EOT
var options = {
	legend: { position: "nw" },
	xaxis: { mode: "time", ticks: 5 },
	yaxis: { position: "right", tickFormatter: EligiusUtils.formatHashrate },
	series: { lines: { fill: 0.3 } },
	selection: { mode: "xy" },
	grid: { markings: [] }
};

var cHashrate = '#' + (localStorage['a2_hashrate'] ? localStorage['a2_hashrate'] : '$cHashrate');
var cShort = '#' + (localStorage['a2_hashrate_short'] ? localStorage['a2_hashrate_short'] : '$cShort');
var cLong = '#' + (localStorage['a2_hashrate_long'] ? localStorage['a2_hashrate_long'] : '$cLong');

$.get("$uri", "", function(data, textStatus, xhr) {
	series.push({ data: data, label: "Hashrate", color: cHashrate, lines: { lineWidth: 1 } });
	series.push({ data: EligiusUtils.movingAverage(data, 10800000, $interval), label: "3-hour average", color: cShort, lines: { fill: false } });
	series.push({ data: EligiusUtils.movingAverage(data, 43200000, $interval), label: "12-hour average", color: cLong, lines: { fill: false } });
	options.grid.markings = EligiusUtils.nightMarkings(data[0][0], data[data.length - 1][0]);
	$.plot($('#eligius_indiv_hashrate'), series, options);

	var maxZoomT = 600000;
	var maxZoomY = 10e6;
	$("#eligius_indiv_hashrate").bind("plotselected", function (event, ranges) {
		if (ranges.xaxis.to - ranges.xaxis.from < maxZoomT)
			ranges.xaxis.to = ranges.xaxis.from + maxZoomT;
		if (ranges.yaxis.to - ranges.yaxis.from < maxZoomY)
			ranges.yaxis.to = ranges.yaxis.from + maxZoomY;

		plot = $.plot($("#eligius_indiv_hashrate"), series,
			$.extend(true, {}, options, {
				xaxis: { min: ranges.xaxis.from, max: ranges.xaxis.to },
				yaxis: { min: ranges.yaxis.from, max: ranges.yaxis.to }
		}));
	});

	$("#eligius_indiv_hashrate").dblclick(function() {
		$.plot($('#eligius_indiv_hashrate'), series, options);
	});
}, "json").error(function() {
	$('#eligius_indiv_hashrate_errors').append('<p>An error happened while loading the hashrate data.<br />Try reloading the page.</p>');
});
EOT;

	echo "});\n</script>\n";
}

$uri = explode('?', $_SERVER['REQUEST_URI']);
$uri = array_shift($uri);
$uri = explode('/', $uri);
$address = array_pop($uri);
$server = array_pop($uri);

if(preg_match('%\.htm$%Di', $address)) {
	$newUri = preg_replace('%(\.htm)$%Di', '', $_SERVER['REQUEST_URI']);
	header('Location: '.$newUri);
	die;
}

if(!isset($SERVERS[$server])) {
	header('HTTP/1.1 404 Not Found', true, 404);
	header('Content-Type: text/plain');
	echo "Unknown server.\n";
	die;
}

list($prettyName, $apiRoot) = $SERVERS[$server];
$addresses = getActiveAddresses($apiRoot);

list(, $unpaid, $current, , $total) = getBalance($apiRoot, $address);
$total = strip_tags($total);
printHeader("($total) $address on $prettyName - Eligius pool", "$address on $prettyName", $relative = '..');

if(!in_array($address, $addresses)) {
	echo <<<EOT
<h1>Unknown address !</h1>
<p>Sorry man, I have no data for this address. Here is maybe why :</p>
<ul>
<li>There is a typo in your address. Make sure <strong>$address</strong> is a valid Bitcoin address, and is yours !</li>
<li>You just started mining. The stats usually show up a few minutes after the first submitted share.</li>
<li>You haven't mined for a week. No stats for you !</li>
<li>There is a problem with the API (very unlikely). If the problem persists, and you're aware of all all the text above, then join us on IRC for help (the link is at the bottom of the main page).</li>
</ul>

EOT;
} else {
	echo "<p><a href='http://".getBE()."/address/$address'>See this address on pident &rarr;</a></p>";
	showBalance($unpaid, $current);
	showHashrateAverage($server, $address);
	echo "<h2>Graphs <small>(double-click to undo zoom)</small></h2>\n";
	showBalanceGraph($server, $address);
	showHashRateGraph($server, $address);
	showRecentPayouts($server, $address);
}

printFooter($relative, ' <p>- <a onclick="EligiusUtils.toggleAutorefresh();">Toggle autorefresh</a><span id="autorefresh_message"></span></p>'."\n".
	((isset($_GET['autorefresh']) && $_GET['autorefresh']) ? "<script type=\"text/javascript\">EligiusUtils.toggleAutorefresh();</script>" : ''));
