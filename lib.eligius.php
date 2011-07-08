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

const VERSION = '2.2';

const T_BALANCE_CURRENT_BLOCK = 'balance_current_block';
const T_BALANCE_UNPAID_REWARD = 'balance_unpaid';
const T_BALANCE_ALREADY_PAID = 'already_paid';
const T_BALANCE_CREDIT = 'credit';
const T_HASHRATE_INDIVIDUAL = 'hashrate';
const T_HASHRATE_POOL = 'hashrate_total';

const HASHRATE_AVERAGE = 10800; // Show a 3-hour average for individual stats
const HASHRATE_AVERAGE_HR = '3 hour'; // Show a 3-hour average for individual stats
const HASHRATE_AVERAGE_SHORT = 900;
const HASHRATE_AVERAGE_SHORT_HR = '15 minute';
const HASHRATE_PERIOD = 900; // Use a 15-minute average to compute the hashrate
const HASHRATE_PERIOD_LONG = 3600;
const HASHRATE_LAG = 180; // Use a 3-minute delay, to cope with MySQL replication lag

const TIMESPAN_SHORT = 604800; // Store at most 7 days of data for short-lived graphs
const TIMESPAN_LONG = 2678400; // Store at most 31 days of data for long-lived graphs

const S_CUSTOM = -2;
const S_UNKNOWN = -1;
const S_WORKING = 0;
const S_INVALID_WORK = 1;
const S_NETWORK_PROBLEM = 2;

const STATUS_TIMEOUT = 20;
const STATUS_FILE_NAME = 'pool_status.json';

const INSTANT_COUNT_PERIOD = 600;
const INSTANT_COUNT_FILE_NAME = 'instant_share_count.json';

const NUMBER_OF_RECENT_BLOCKS = 7;
const NUMBER_OF_TOP_CONTRIBUTORS = 10;

const API_HASHRATE_DELAY = 600; /* The hashrate.txt files seem to be updated every ten minutes. */
const NUM_CONFIRMATIONS = 120; /* Number of confirmations before a block is considered "valid" */

const RECENT_BLOCKS = 10;
const OLD_BLOCKS = 250;
const GENESIS_BLOCK = '000000000000066cf09f972e4e258916f1f3003a37d208555d3b527542d7695f'; /* The first valid block found by the pool. */

require __DIR__.'/lib.util.php';
require __DIR__.'/lib.cache.php';
require __DIR__.'/lib.bitcoin.php';
require __DIR__.'/lib.bitcoind.php';
require __DIR__.'/inc.sql.php';

session_start();
if(!isset($_SESSION['tok'])) {
	$_SESSION['tok'] = base64_encode(pack('H*', sha1(uniqid('Trololo', true).uniqid('Ujelly?', true))));
}

/**
 * Update the Pool's hashrate.
 * @param string $serverName the name of the server (should coincide with the "server" column in MySQL)
 * @param string $apiRoot the API root of the server.
 * @return bool true if the operation succeeded.
 */
function updatePoolHashrate($serverName, $apiRoot) {
	$end = time() - HASHRATE_LAG;
	$start = sqlTime($end - HASHRATE_PERIOD_LONG);
	$end = sqlTime($end);
	$hashrate = sqlQuery("
		SELECT ((COUNT(*) * POW(2, 32)) / ".HASHRATE_PERIOD_LONG.") AS hashrate
		FROM shares
		WHERE our_result = true
			AND server = $serverName
			AND time BETWEEN '$start' AND '$end'
	");
	$hashrate = fetchAssoc($hashrate);
	$hashrate = $hashrate['hashrate'];

	if($hashrate == 0) {
		/* Try using the .txt files in case of SQL breakage. This is not ideal at all (the average is too short), but meh */
		$hashrate = floatval(file_get_contents($apiRoot.'/hashrate.txt'));
	}

	return updateData(T_HASHRATE_POOL, $serverName, null, $hashrate, TIMESPAN_LONG);
}

/**
 * Update the hashrate data for an address on one server.
 * @param string $serverName the name of the server (should coincide with the "server" column in MySQL)
 * @param string $address the address to process
 * @param array $hashrates the array returned by getIndividualHashrates()
 * @return bool true if the operation succeeded.
 */
function updateIndividualHashrate($serverName, $address, $hashrates) {
	$H = isset($hashrates[$address]) ? $hashrates[$address] : 0.0;
	return updateData(T_HASHRATE_INDIVIDUAL, $serverName.'_'.$address, null, $H, TIMESPAN_SHORT);
}

/**
 * Update all the balances of an address.
 * @param string $serverName the name of the server.
 * @param string $address the address to update.
 * @param string|float $current_block an estimation, in BTC, of the reward for this address with the current block.
 * @param string|float $unpaid the amount, in BTC, that is not yet paid to this address
 * @param string|float $credit diff between PPS and reward
 * @param string|float $paid the amount, in BTC, already paid to this address (ever)
 * @return bool true if the operations succeeded, false otherwise
 */
function updateBalance($serverName, $address, $current_block, $unpaid, $credit, $paid) {
	$identifier = $serverName.'_'.$address;
	$ret = true;
	$ret = $ret && updateData(T_BALANCE_CURRENT_BLOCK, $identifier, null, $current_block, TIMESPAN_SHORT);
	$ret = $ret && updateData(T_BALANCE_UNPAID_REWARD, $identifier, null, $unpaid, TIMESPAN_SHORT);
	$ret = $ret && updateData(T_BALANCE_CREDIT, $identifier, null, $credit, TIMESPAN_SHORT);
	$ret = $ret && updateData(T_BALANCE_ALREADY_PAID, $identifier, null, $paid, TIMESPAN_SHORT);
	return $ret;
}

/**
 * Update all the hashrates for one server.
 * @param string $serverName the name of the server (should coincide with the "server" column in MySQL)
 * @param string $apiRoot the API root of the server.
 * @param callback $tickCallback an optional callback that will be called after every address processed.
 * @return array an array containing the number of correctly processed addresses, and failed attempts.
 */
function updateAllIndividualHashratesOnServer($serverName, $apiRoot, $tickCallback = null) {
	$ok = 0;
	$failed = 0;

	$hashrates = getIndividualHashrates($serverName);
	$addresses = getActiveAddresses($apiRoot);
	foreach($addresses as $address) {
		if(updateIndividualHashrate($serverName, $address, $hashrates)) $ok++;
		else $failed++;

		if($tickCallback !== null) call_user_func($tickCallback, $address);
	}

	if($failed !== 0) {
		trigger_error('Could not update '.$failed.' hashrates of individual addresses.', E_USER_NOTICE);
	}

	return array($ok, $failed);
}

/**
 * Update the balances of all addresses of one server.
 * @param string $serverName the name of the server.
 * @param string $apiRoot the API root of the server.
 * @param callback $tickCallback a function to call after every address processed.
 * @return array|bool false if an error happened, or an array containing the number of correctly processed addresses
 * and failed attempts.
 */
function updateAllBalancesOnServer($serverName, $apiRoot, $tickCallback = null) {
	$ok = 0;
	$failed = 0;

	$balances = getBalanceData($apiRoot);
	$latest = json_decode_safe($apiRoot.'/blocks/latest.json');

	foreach($balances as $address => $data) {
		if(!$address) continue; /* Filter the "" entry, which represents the pool. */

		$paid = isset($latest[$address]['everpaid']) ? satoshiToBTC($latest[$address]['everpaid']) : 0.0;
		$unpaid = isset($latest[$address]['balance']) ? satoshiToBTC($latest[$address]['balance']) : 0.0;
		$current = satoshiToBTC(bcsub($balances[$address]['balance'], isset($latest[$address]['balance']) ? $latest[$address]['balance'] : 0, 0));
		$credit = isset($balances[$address]['credit']) ? satoshiToBTC($balances[$address]['credit']) : 0.0;
		if(updateBalance($serverName, $address, $current, $unpaid, $credit, $paid)) $ok++;
		else $failed++;

		if($tickCallback !== null) call_user_func($tickCallback, $address);
	}

	if($failed !== 0) {
		trigger_error('Could not update '.$failed.' balances.', E_USER_NOTICE);
	}

	return array($ok, $failed);
}

/**
 * Checks the status of a server, and writes the results in a JSON file.
 * @param string $serverName the name of the server
 * @param string $address the address of the server
 * @param int|string $port the port to connect to
 * @return bool true if the operation succeeded (ie, the status was updated successfully, regardless of its status)
 */
function updateServerStatus($serverName, $address, $port) {
	$f = __DIR__.'/'.DATA_RELATIVE_ROOT.'/'.STATUS_FILE_NAME;
	if(!file_exists($f)) {
		$status = array();
	} else {
		$status = json_decode_safe($f);
	}

	$s = S_UNKNOWN;
	$lag = -1.0;
	getServerStatus($address, $port, STATUS_TIMEOUT, $s, $lag);

	$now = time();
	$status[$serverName]['latency'] = $lag;
	$status[$serverName]['last-updated'] = $now;
	if(!isset($status[$serverName]['status']) || $status[$serverName]['status'] !== $s) {
		$status[$serverName]['status'] = $s;
		$status[$serverName]['since'] = $now;
	}

	return json_encode_safe($status, $f);
}

/**
 * Update the instant share rate and instant share count for the current round.
 * @param string $server the server name
 * @return bool true if the operation succeeded.
 */
function updateInstantShareCount($server) {
	$f = __DIR__.'/'.DATA_RELATIVE_ROOT.'/'.INSTANT_COUNT_FILE_NAME;
	if(!file_exists($f)) {
		$instant = array();
	} else {
		$instant = json_decode_safe($f);
	}

	$recent = cacheFetch('blocks_recent_'.$server, $success);
	if($success) {
		$lastBlockTimestamp = count($recent) > 0 ? $recent[0]['when'] : null;

		if(!isset($instant[$server]['roundStartTime']) || $instant[$server]['roundStartTime'] != $lastBlockTimestamp || !$instant[$server]['lastID']) {
			$instant[$server]['roundStartTime'] = $lastBlockTimestamp;

			$now = time();
			$end = $now - HASHRATE_LAG;
			$fEnd = sqlTime($end);
			$start = sqlTime($lastBlockTimestamp ?: "0");
			$q = sqlQuery("
				SELECT COUNT(id) AS total, MAX(id) AS lastid
				FROM shares
				WHERE time BETWEEN '$start' AND '$fEnd'
					AND server = $server
					AND our_result = true"
			);
			$q = fetchAssoc($q);

			$instant[$server]['totalShares'] = $q['total'];
			$instant[$server]['lastUpdated'] = $now;
			$instant[$server]['lastID'] = $q['lastid'];

			$start = sqlTime($end - INSTANT_COUNT_PERIOD);
			$interval = INSTANT_COUNT_PERIOD;
			$q = sqlQuery("
				SELECT COUNT(id) / ($interval) AS rate
				FROM shares
				WHERE time BETWEEN '$start' AND '$fEnd'
					AND server = $server
					AND our_result = true"
			);
			$q = fetchAssoc($q);

			$instant[$server]['instantRate'] = $q['rate'];
		} else {
			$now = time();
			$end = $now - HASHRATE_LAG;
			$fEnd = sqlTime($end);
			$thresholdID = $instant[$server]['lastID'];
			$q = sqlQuery("
				SELECT COUNT(id) AS total, MAX(id) AS lastid
				FROM shares
				WHERE time <= '$fEnd'
					AND server = $server
					AND id > $thresholdID
					AND our_result = true"
			);
			$q = fetchAssoc($q);

			$instant[$server]['totalShares'] += $q['total'];
			$instant[$server]['lastUpdated'] = $now;
			if($q['lastid'] > 0) $instant[$server]['lastID'] = $q['lastid'];

			$start = sqlTime($end - INSTANT_COUNT_PERIOD);
			$q = sqlQuery("
				SELECT COUNT(id) / ($end - $start) AS rate
				FROM shares
				WHERE time BETWEEN '$start' AND '$fEnd'
					AND server = $server
					AND our_result = true"
			);
			$q = fetchAssoc($q);

			$instant[$server]['instantRate'] = $q['rate'];
		}
	} else $instant[$server] = array();

	$instant['difficulty'] = trim(bitcoind('getdifficulty'));

	return json_encode_safe($instant, $f);
}

/**
 * Cache the contributors with the highest average hashrate, in average.
 * @param int $numContributors how many top contributors to fetch
 * @return bool true if the operation was successful.
 */
function updateTopContributors() {
	$end = time() - HASHRATE_LAG;
	$start = sqlTime($end - HASHRATE_AVERAGE);
	$end = sqlTime($end);

	$q = sqlQuery("
		SELECT server, keyhash, ((COUNT(*) * POW(2, 32)) / ".HASHRATE_AVERAGE.") AS hashrate
		FROM shares
		LEFT JOIN users ON shares.user_id = users.id
		WHERE time BETWEEN '$start' AND '$end'
			AND our_result = true
		GROUP BY keyhash, server
		ORDER BY hashrate DESC"
	);

	$top = array();
	while($t = fetchAssoc($q)) {
		if($t['keyhash']) $t['address'] = \Bitcoin::hash160ToAddress(bits2hex($t['keyhash']));
		else $t['address'] = '(Invalid addresses)';
		unset($t['keyhash']);
		$top[] = $t;
	}

	return cacheStore('top_contributors', $top);
}

/**
 * Cache a random address currently contributing on a server.
 * @param string $serverName the name of the server
 * @param string $apiRoot the API root for this server
 * @return bool true if the operation was successful.
 */
function updateRandomAddress($serverName, $apiRoot) {
	$addresses = getActiveAddresses($apiRoot);
	if($addresses === false) return false;
	if(count($addresses) == 0) return false;

	shuffle($addresses);
	$address = array_pop($addresses);
	return cacheStore('random_address_'.$serverName, $address);
}

/**
 * Cache the average hashrates of all the users.
 * @return bool true if the operation was successful.
 */
function updateAverageHashrates() {
	$end = time() - HASHRATE_LAG;
	$fEnd = sqlTime($end);

	$wStart = sqlTime(time() - HASHRATE_LAG - 60);
	$isWorking = sqlQuery("
		SELECT COUNT(*) AS shares
		FROM shares
		WHERE our_result = true
			AND time >= '$wStart'
		LIMIT 1
	");
	$isWorking = fetchAssoc($isWorking);
	$isWorking = $isWorking['shares'];
	if($isWorking == 0) {
		$averages3h = array();
		$averages15min = array();
	} else {
		$averages3h = array();
		$averages15min = array();

		$start = sqlTime($end - HASHRATE_AVERAGE);
		$q = sqlQuery("
			SELECT keyhash, server, COUNT(*) AS shares
			FROM shares
			LEFT JOIN users ON shares.user_id = users.id
			WHERE our_result = true
				AND time BETWEEN '$start' AND '$fEnd'
			GROUP BY server, keyhash
		");

		while($r = fetchAssoc($q)) {
			$r['address'] = \Bitcoin::hash160ToAddress(bits2hex($r['keyhash']));
			unset($r['keyhash']);

			$rate = floatval(bcdiv(bcmul($r['shares'], bcpow(2, 32)), HASHRATE_AVERAGE));
			$averages3h['valid'][$r['server']][$r['address']] = array($r['shares'], $rate);
		}

		$start = sqlTime($end - HASHRATE_AVERAGE_SHORT);
		$q = sqlQuery("
			SELECT keyhash, server, COUNT(*) AS shares
			FROM shares
			LEFT JOIN users ON shares.user_id = users.id
			WHERE our_result = true
				AND time BETWEEN '$start' AND '$fEnd'
			GROUP BY server, keyhash
		");

		while($r = fetchAssoc($q)) {
			$r['address'] = \Bitcoin::hash160ToAddress(bits2hex($r['keyhash']));
			unset($r['keyhash']);

			$rate = floatval(bcdiv(bcmul($r['shares'], bcpow(2, 32)), HASHRATE_AVERAGE_SHORT));
			$averages15min['valid'][$r['server']][$r['address']] = array($r['shares'], $rate);
		}

		$start = sqlTime($end - HASHRATE_AVERAGE);
		$q = sqlQuery("
			SELECT keyhash, server, COUNT(*) AS shares, reason
			FROM shares
			LEFT JOIN users ON shares.user_id = users.id
			WHERE our_result = false
				AND time BETWEEN '$start' AND '$fEnd'
			GROUP BY server, keyhash, reason
		");

		while($r = fetchAssoc($q)) {
			$r['address'] = \Bitcoin::hash160ToAddress(bits2hex($r['keyhash']));
			unset($r['keyhash']);

			$averages3h['invalid'][$r['server']][$r['address']][$r['reason']] = $r['shares'];
		}

		$start = sqlTime($end - HASHRATE_AVERAGE_SHORT);
		$q = sqlQuery("
			SELECT keyhash, server, COUNT(*) AS shares, reason
			FROM shares
			LEFT JOIN users ON shares.user_id = users.id
			WHERE our_result = false
				AND time BETWEEN '$start' AND '$fEnd'
			GROUP BY server, keyhash, reason
		");

		while($r = fetchAssoc($q)) {
			$r['address'] = \Bitcoin::hash160ToAddress(bits2hex($r['keyhash']));
			unset($r['keyhash']);

			$averages15min['invalid'][$r['server']][$r['address']][$r['reason']] = $r['shares'];
		}
	}

	$a = cacheStore('average_hashrates_long', $averages3h);
	$b = cacheStore('average_hashrates_short', $averages15min);

	return $a && $b;
}

/**
 * Cache the block information (shares, rewards, …)
 * @param string $server cache the blocks found by this server
 * @param string $apiRoot the API root for this server
 * @return bool true if the operation succeeded.
 */
function updateBlocks($server, $apiRoot) {
	static $blockChain = null;
	static $blockCount = null;
	if($blockChain === null) {
		$blockCount = updateBlockChain();
		$blockChain = cacheFetch('block_chain', &$success);
		if(!$success) return false;
	}

	$sharesCache = cacheFetch('shares', $success);
	if(!$success) $sharesCache = array();

	$gBlocks = glob($apiRoot.'/blocks/0000*.json');
	$blocks = array();
	foreach($gBlocks as $block) {
		$blocks[$block] = filemtime($block);
	}
	arsort($blocks);

	$foundAt = array_values($blocks);
	$blocks = array_keys($blocks);
	$processedBlocks = array();

	foreach($blocks as &$blk) {
		$blk = pathinfo($blk, PATHINFO_FILENAME);
	}

	/* Prune invalid blocks */
	$c = count($blocks);
	$now = time();
	for($i = 0; $i < $c; ++$i) {
		if(!preg_match('%^[0-9a-fA-F]{64}$%D', $blocks[$i])) {
			/* Malformed hash, probably not a block */
			unset($foundAt[$i]);
			unset($blocks[$i]);
		} else if($blockCount !== false && (($now - $foundAt[$i]) > FRESH_BLOCK_THRESHOLD) && !isset($blockChain[$blocks[$i]])) {
			/* Invalid block */
			unset($foundAt[$i]);
			unset($blocks[$i]);
		}
	}

	/* Reorganize array keys */
	$foundAt = array_values($foundAt);
	$blocks = array_values($blocks);

	$c = min(count($blocks), RECENT_BLOCKS + OLD_BLOCKS); /* Don't process more blocks than necessary */
	for($i = 0; $i < $c; ++$i) {
		$blk = $blocks[$i];

		$bData = array();
		$bData['hash'] = $blk;
		$bData['when'] = $foundAt[$i];
		$bData['duration'] = ($i < ($c - 1)) ? ($foundAt[$i] - $foundAt[$i + 1]) : null;

		$start = ($i < ($c - 1)) ? $foundAt[$i + 1] : 0;
		$end = $foundAt[$i];

		if(isset($sharesCache[$start][$end]) && $sharesCache[$start][$end][0] > 0) {
			list($total, $shares) = $sharesCache[$start][$end];
			$bData['shares_total'] = $total;
			$bData['shares'] = $shares;
		} else {
			$sharesCache[$start] = array();

			$q = sqlQuery("
				SELECT username, COUNT(*) AS fshares
				FROM shares
				LEFT JOIN users ON shares.userId = users.id
				WHERE \"ourResult\" = true
					AND server = $server
					AND time BETWEEN $start AND $end
				GROUP BY username
			");

			$bData['shares_total'] = 0;
			$bData['shares'] = array();
			while($r = fetchAssoc($q)) {
				$r['address'] = \Bitcoin::hash160ToAddress(bits2hex($r['keyhash']));
				unset($r['keyhash']);

				$bData['shares_total'] += $r['fshares'];
				$bData['shares'][$r['username']] = $r['fshares'];
			}

			$sharesCache[$start][$end] = array($bData['shares_total'], $bData['shares']);
		}

		$json = json_decode_safe($apiRoot.'/blocks/'.$blk.'.json');
		foreach($json as $address => $row) {
			if(isset($row['earned'])) {
				$bData['rewards'][$address] = rawSatoshiToBTC($row['earned']);
			}
		}

		$bData['metadata'] = $json[''];

		if($blockCount === false) {
			/* We have no bitcoind, assume everything is ??? */
			$bData['valid'] = null;
		} else if((time() - $bData['when']) > FRESH_BLOCK_THRESHOLD) {
			$confirmations = $blockCount - $blockChain[$blk];
			if($confirmations >= NUM_CONFIRMATIONS) {
				$bData['valid'] = true;
			} else $bData['valid'] = NUM_CONFIRMATIONS - $confirmations;
		} else $bData['valid'] = NUM_CONFIRMATIONS;

		$processedBlocks[] = $bData;
	}

	$recent = array_slice($processedBlocks, 0, RECENT_BLOCKS);
	$old = array_slice($processedBlocks, RECENT_BLOCKS);

	return cacheStore('shares', $sharesCache) && cacheStore('blocks_recent_'.$server, $recent) && cacheStore('blocks_old_'.$server, $old);
}

/**
 * Get an associative array of "instant" hashrates for the addresses that submitted shares recently.
 * This is a costly operation !
 * @param string $serverName the name of the server (should coincide with the "server" column in MySQL)
 * @return array an array (address => instant hashrate)
 */
function getIndividualHashrates($serverName) {
	$end = time() - HASHRATE_LAG;
	$start = sqlTime($end - HASHRATE_PERIOD);
	$end = sqlTime($end);
	$q = sqlQuery("
		SELECT keyhash, ((COUNT(*) * POW(2, 32)) / ".HASHRATE_PERIOD.") AS hashrate
		FROM shares
		LEFT JOIN users ON shares.user_id = users.id
		WHERE our_result = true
			AND server = $serverName
			AND time BETWEEN '$start' AND '$end'
		GROUP BY keyhash
	");

	$result = array();
	while($r = fetchAssoc($q)) {
		$r['address'] = \Bitcoin::hash160ToAddress(bits2hex($r['keyhash']));
		unset($r['keyhash']);

		$result[$r['address']] = $r['hashrate'];
	}

	return $result;
}

/**
 * Get the balances of addresses on a given server. This call is cached.
 * @param string $apiRoot the API root of the server.
 * @return bool|array false if the operation failed, an array (address => balance data) otherwise.
 */
function getBalanceData($apiRoot) {
	static $cache = null;
	if($cache === null) $cache = array();
	if(isset($cache[$apiRoot])) return $cache[$apiRoot];

	$balances = json_decode_safe($apiRoot.'/balances.json');

	return $cache[$apiRoot] = $balances;
}

/**
 * Get an array of addresses contributing on a given server.
 * @param string $apiRoot the API root of the server.
 * @return bool|array an array of active addresses.
 */
function getActiveAddresses($apiRoot) {
	$b = getBalanceData($apiRoot);
	if(!is_array($b)) return false;
	return array_keys($b);
}

/**
 * Issue a getwork to a server to check if it is working as expected.
 * @param string $server the server address.
 * @param string|int $port the port to connect to
 * @param int $timeout for how long should we try to connect to the server before failing ?
 * @param int $status the status of the server, is one of the S_ constants.
 * @param float $latency the latency, in seconds, to issue a getwork. Only valid if true is returned.
 * @param int $retries how many retries to do before returning an error
 * @return bool true if the server is working correctly
 */
function getServerStatus($server, $port, $timeout, &$status, &$latency, $retries = 2) {
	$body = json_encode(array(
		"method" => "getwork",
		"params" => array(),
		"id" => 42
	));

	$c = curl_init();
	curl_setopt($c, CURLOPT_POST, true);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($c, CURLOPT_HEADER, true);
	curl_setopt($c, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	curl_setopt($c, CURLOPT_POSTFIELDS, $body);
	curl_setopt($c, CURLOPT_URL, 'http://artefact2:test@'.$server.':'.$port.'/');
	curl_setopt($c, CURLOPT_CONNECTTIMEOUT, $timeout);
	curl_setopt($c, CURLOPT_TIMEOUT, $timeout);

	$lag = microtime(true);
	$resp = curl_exec($c);
	$latency = microtime(true) - $lag;

	if(curl_error($c))  {
		curl_close($c);
		if($retries > 0) return getServerStatus($server, $port, 5, $status, $latency, $retries  - 1);
		else {
			$status = S_NETWORK_PROBLEM;
			return false;
		}
	}

	curl_close($c);

	if(strpos($resp, 'Content-Type: application/json') === false) {
		if($retries > 0) return getServerStatus($server, $port, 5, $status, $latency, $retries  - 1);
		else {
			$status = S_INVALID_WORK;
			return false;
		}
	}

	$work = json_decode_safe(substr($resp, strpos($resp, '{') - 1), false);

	if(!isset($work['result']['data']) || strlen($work['result']['data']) !== 256 || $work['error'] !== null) {
		if($retries > 0) return getServerStatus($server, $port, 5, $status, $latency, $retries  - 1);
		else {
			$status = S_INVALID_WORK;
			return false;
		}
	}

	$status = S_WORKING;
	return true;
}

/**
 * Get the balance of an address on one server.
 * @param string $apiRoot the API root of the server.
 * @param string $address return the balance of this address
 * @return array|bool false if an error happened, or array($paid, $unpaid, $current).
 */
function getBalance($apiRoot, $address) {
	$balances = getBalanceData($apiRoot);
	$latest = json_decode_safe($apiRoot.'/blocks/latest.json');

	if(!isset($balances[$address]['balance'])) return false;

	$paid = prettySatoshis(isset($latest[$address]['everpaid']) ? $latest[$address]['everpaid'] : 0);
	$unpaid = prettySatoshis(isset($latest[$address]['balance']) ? $latest[$address]['balance'] : 0);
	$current = prettySatoshis(bcsub($balances[$address]['balance'], isset($latest[$address]['balance']) ? $latest[$address]['balance'] : 0, 0));
	$credit = prettySatoshis(isset($balances[$address]['credit']) ? $balances[$address]['credit'] : 0.0);
	$total = prettySatoshis($balances[$address]['balance']);

	return array($paid, $unpaid, $current, $credit, $total);
}

/**
 * Update the cached block chain of valid recent blocks.
 * @return bool true if the operation succeeded.
 */
function updateBlockChain() {
	$chain = cacheFetch('block_chain', $success);
	if($success) {
		$chain = array_flip($chain);
	} else $chain = array();

	$i = $I = intval(trim(bitcoind('getblockcount')));
	while(true) {
		$hash = json_decode_safe(bitcoind('getblockbycount '.$i), false);
		$hash = $hash['hash'];

		if(isset($chain[$i]) && $chain[$i] == $hash) break;
		$chain[$i] = $hash;
		if(strcasecmp($hash, GENESIS_BLOCK) == 0) break;

		--$i;
	}

	return cacheStore('block_chain', array_flip($chain)) ? $I : false;
}
