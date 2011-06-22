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

function showContributors() {
	global $SERVERS;

	$totals = array();
	foreach($SERVERS as $name => $d) {
		list(,$apiRoot) = $d;
		$totals[$name] = file_get_contents($apiRoot.'/hashrate.txt');
	}

	echo "<table id=\"contrib\">\n<thead>\n<tr><th>Rank</th><th>Server</th><th>Address</th><th>▼ Average hashrate</th><th colspan=\"2\">Relative contribution in the pool (%)</th></tr></thead>\n<tbody>\n";

	$success = null;
	$top = cacheFetch('top_contributors', $success);
	$a = 0; $i = 0;
	if($success) {
		foreach($top as $t) {
			++$i;
			$hashrate = $t['hashrate'];
			$address = $t['address'];
			$addressClass = '';
			if(!preg_match('%^[abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ123456789]{26,34}$%D', $address)) {
				$addressClass = ' class="warn"';
			}
			$server = $t['server'];

			$pHashrate = prettyHashrate($hashrate);
			$pServer = $SERVERS[$server][0];
			if(isset($totals[$server]) && $totals[$server] > 0) {
				$contribPercent = min(100, 100 * $hashrate / $totals[$server]);
				$fContribPercent = number_format($contribPercent, 6).' %';
				$fContribPercent = "<td><div class=\"hash_progress\"><div style=\"width: $contribPercent%;\">&nbsp;</div></div></td><td style=\"width: 8em;\">$fContribPercent</td>";
			} else {
				$fContribPercent = '<td><small>N/A</small></td>';
			}

			$a = ($a + 1) % 2;
			echo "<tr class=\"row$a\"><td style=\"width: 3em;\">#$i</td><td style=\"width: 6em;\">$pServer</td><td style=\"width: 25em;\"$addressClass><a href=\"./$server/$address\">$address</a></td><td style=\"width: 15em;\">$pHashrate</td>$fContribPercent</tr>\n";
		}
	} else echo "<tr><td colspan=\"5\"><small>N/A</small></td></tr>\n";

	echo "</tbody>\n</table>\n";
}

printHeader('List of contributors sorted by hashrate', 'List of contributors (sorted by hashrate)', $relative = '.');
echo "<p>The hashrate displayed below is a 3-hour average. For practical reasons, only the addresses that submitted at least one share in the last 3 hours are displayed.</p>\n";
showContributors();
printFooter($relative);
