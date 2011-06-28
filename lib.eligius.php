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

require __DIR__.'/lib.util.php';
require __DIR__.'/lib.cache.php';
require __DIR__.'/lib.bitcoind.php';
require __DIR__.'/inc.sql.php';

session_start();
if(!isset($_SESSION['tok'])) {
	$_SESSION['tok'] = base64_encode(pack('H*', sha1(uniqid('Trololo', true).uniqid('Ujelly?', true))));
}

/**
 * Update the Pool's hashrate.
 * @param string $serverName the name of the server (should coincide with the "server" column in MySQL)
 * @return bool true if the operation succeeded.
 */
function updatePoolHashrate($serverName) {
	$end = time() - HASHRATE_LAG;
	$start = $end - HASHRATE_PERIOD_LONG;
	$hashrate = sqlQuery("
		SELECT ((COUNT(*) * POW(2, 32)) / ".HASHRATE_PERIOD_LONG.") AS hashrate
		FROM shares
		WHERE our_result <> 'N'
			AND server = $serverName
			AND time BETWEEN $start AND $end
	");
	$hashrate = fetchAssoc($hashrate);
	$hashrate = $hashrate['hashrate'];

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
 * @param $server the server name
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
		$lastBlockTimestamp = null;
		usort($recent, function($a, $b) { return $b['when'] - $a['when']; });
		foreach($recent as $blk) {
			if($blk['valid'] === true || is_int($blk['valid'])) {
				$lastBlockTimestamp = $blk['when'];
				break;
			}
		}

		if(!isset($instant[$server]['roundStartTime']) || $instant[$server]['roundStartTime'] != $lastBlockTimestamp || !$instant[$server]['lastID']) {
			$instant[$server]['roundStartTime'] = $lastBlockTimestamp;

			$now = time();
			$end = $now - HASHRATE_LAG;
			$start = $lastBlockTimestamp ?: "0";
			$q = sqlQuery("
				SELECT COUNT(id) AS total, MAX(id) AS lastid
				FROM shares
				WHERE time BETWEEN $start AND $end
					AND server = $server
					AND our_result <> 'N'"
			);
			$q = fetchAssoc($q);

			$instant[$server]['totalShares'] = $q['total'];
			$instant[$server]['lastUpdated'] = $now;
			$instant[$server]['lastID'] = $q['lastid'];

			$start = $end - INSTANT_COUNT_PERIOD;
			$q = sqlQuery("
				SELECT COUNT(id) / ($end - $start) AS rate
				FROM shares
				WHERE time BETWEEN ($start + 1) AND $end
					AND server = $server
					AND our_result <> 'N'"
			);
			$q = fetchAssoc($q);

			$instant[$server]['instantRate'] = $q['rate'];
		} else {
			$now = time();
			$end = $now - HASHRATE_LAG;
			$thresholdID = $instant[$server]['lastID'];
			$q = sqlQuery("
				SELECT COUNT(id) AS total, MAX(id) AS lastid
				FROM shares
				WHERE time <= $end
					AND server = $server
					AND id > $thresholdID
					AND our_result <> 'N'"
			);
			$q = fetchAssoc($q);

			$instant[$server]['totalShares'] += $q['total'];
			$instant[$server]['lastUpdated'] = $now;
			if($q['lastid'] > 0) $instant[$server]['lastID'] = $q['lastid'];

			$start = $end - INSTANT_COUNT_PERIOD;
			$q = sqlQuery("
				SELECT COUNT(id) / ($end - $start) AS rate
				FROM shares
				WHERE time BETWEEN $start AND $end
					AND server = $server
					AND our_result <> 'N'"
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
	$start = $end - HASHRATE_AVERAGE;

	$q = sqlQuery("
		SELECT server, username AS address, ((COUNT(*) * POW(2, 32)) / ".HASHRATE_AVERAGE.") AS hashrate
		FROM shares
		WHERE time BETWEEN $start AND $end
			AND our_result <> 'N'
		GROUP BY username, server
		ORDER BY hashrate DESC"
	);

	$top = array();
	while($t = fetchAssoc($q)) {
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

	$wStart = time() - HASHRATE_LAG - 60;
	$isWorking = sqlQuery("
		SELECT COUNT(*) AS shares
		FROM shares
		WHERE our_result <> 'N'
			AND time >= $wStart
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

		$start = $end - HASHRATE_AVERAGE;
		$q = sqlQuery("
			SELECT username AS address, server, COUNT(*) AS shares
			FROM shares
			WHERE our_result <> 'N'
				AND time BETWEEN $start AND $end
			GROUP BY server, username
			ORDER BY time DESC
		");

		while($r = fetchAssoc($q)) {
			$rate = floatval(bcdiv(bcmul($r['shares'], bcpow(2, 32)), HASHRATE_AVERAGE));
			$averages3h['valid'][$r['server']][$r['address']] = array($r['shares'], $rate);
		}

		$start = $end - HASHRATE_AVERAGE_SHORT;
		$q = sqlQuery("
			SELECT username AS address, server, COUNT(*) AS shares
			FROM shares
			WHERE our_result <> 'N'
				AND time BETWEEN $start AND $end
			GROUP BY server, username
			ORDER BY time DESC
		");

		while($r = fetchAssoc($q)) {
			$rate = floatval(bcdiv(bcmul($r['shares'], bcpow(2, 32)), HASHRATE_AVERAGE_SHORT));
			$averages15min['valid'][$r['server']][$r['address']] = array($r['shares'], $rate);
		}

		$start = $end - HASHRATE_AVERAGE;
		$q = sqlQuery("
			SELECT username AS address, server, COUNT(*) AS shares, reason
			FROM shares
			WHERE our_result <> 'Y'
				AND time BETWEEN $start AND $end
			GROUP BY server, username, reason
			ORDER BY time DESC
		");

		while($r = fetchAssoc($q)) {
			$averages3h['invalid'][$r['server']][$r['address']][$r['reason']] = $r['shares'];
		}

		$start = $end - HASHRATE_AVERAGE_SHORT;
		$q = sqlQuery("
			SELECT username AS address, server, COUNT(*) AS shares, reason
			FROM shares
			WHERE our_result <> 'Y'
				AND time BETWEEN $start AND $end
			GROUP BY server, username, reason
			ORDER BY time DESC
		");

		while($r = fetchAssoc($q)) {
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
	$recent = cacheFetch('blocks_recent_'.$server, $success0);
	$old = cacheFetch('blocks_old_'.$server, $success1);
	if(!$success0) {
		$recent = array();
	}
	if(!$success1) {
		$old = array();
	}

	$gBlocks = glob($apiRoot.'/blocks/0000*.json');
	$blocks = array();
	foreach($gBlocks as $block) {
		$blocks[$block] = filemtime($block);
	}
	arsort($blocks);

	$foundAt = array_values($blocks);
	$blocks = array_keys($blocks);

	$c = count($blocks);
	$newBlocks = array();

	for($i = 0; $i < $c; ++$i) {
		$blk = pathinfo($blocks[$i], PATHINFO_FILENAME);
		if(count($recent) > 0 && $recent[0]['when'] >= $foundAt[$i]) break;

		$bData = array();

		$bData['hash'] = $blk;
		$bData['when'] = $foundAt[$i];
		$bData['duration'] = ($i < ($c - 1)) ? ($foundAt[$i] - $foundAt[$i + 1]) : null;

		$start = ($i < ($c - 1)) ? ($foundAt[$i + 1]) : 0;
		$end = $foundAt[$i];
		$q = sqlQuery("
			SELECT username, COUNT(*) AS fshares
			FROM shares
			WHERE our_result <> 'N'
				AND server = $server
				AND time BETWEEN $start AND $end
			GROUP BY username
		");

		$bData['shares_total'] = 0;
		while($r = fetchAssoc($q)) {
			$bData['shares_total'] += $r['fshares'];
			$bData['shares'][$r['username']] = $r['fshares'];
		}

		$json = json_decode_safe($blocks[$i]);
		foreach($json as $address => $row) {
			if(isset($row['earned'])) {
				$bData['rewards'][$address] = rawSatoshiToBTC($row['earned']);
			}
		}

		$newBlocks[] = $bData;
	}

	$recent = array_merge($newBlocks, $recent);

	// Transfer overflowing blocks from $recent to $old
	$c = count($recent);
	for($i = RECENT_BLOCKS; $i < $c; ++$i) {
		array_unshift($old, array_pop($recent));
	}

	// Throw away very old blocks
	$c = count($old);
	for($i = OLD_BLOCKS; $i < $c; ++$i) {
		array_pop($old);
	}

	$r = cacheStore('blocks_recent_'.$server, $recent) && cacheStore('blocks_old_'.$server, $old);
	if($r && count($newBlocks) > 0) {
		return updateAllBlockMetadata($server);
	} else return $r;
}

/**
 * Get an associative array of "instant" hashrates for the addresses that submitted shares recently.
 * This is a costly operation !
 * @param string $serverName the name of the server (should coincide with the "server" column in MySQL)
 * @return array an array (address => instant hashrate)
 */
function getIndividualHashrates($serverName) {
	$end = time() - HASHRATE_LAG;
	$start = $end - HASHRATE_PERIOD;
	$q = sqlQuery("
		SELECT username AS address, ((COUNT(*) * POW(2, 32)) / ".HASHRATE_PERIOD.") AS hashrate
		FROM shares
		WHERE our_result <> 'N'
			AND server = $serverName
			AND time BETWEEN $start AND $end
		GROUP BY username
	");

	$result = array();
	while($r = fetchAssoc($q)) {
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
 * Update the shares of invalid blocks.
 * @param array $block a potentially invalid block
 * @param array $nextBlock the block found by the pool right after $block
 * @return null nothing
 */
function maybeProcessInvalidBlock(&$block, &$nextBlock) {
	if($block['valid'] !== false) return;
	if($block['shares_total'] === null && $block['shares'] === null && $block['rewards'] === null) return;

	$nextBlock['shares_total'] += $block['shares_total'];

	foreach($block['shares'] as $address => $s) {
		if(!isset($nextBlock['shares'][$address])) $nextBlock['shares'][$address] = 0;
		$nextBlock['shares'][$address] += $s;
	}

	// Don't add the rewards, because the invalid block generated zero BTC !

	$block['shares'] = null;
	$block['shares_total'] = null;
	$block['rewards'] = null;
}

/**
 * Update the metadata of a block.
 * @param array $block block to update
 * @param null|array $previousBlock previous block found by the pool (can be null)
 * @return null nothing
 */
function updateBlock(&$block, $previousBlock = null) {
	static $lackOfConfirmations = null;
	static $lastUnconfirmedBlockDate;
	if($lackOfConfirmations === null) $lastUnconfirmedBlockDate = getUnconfirmedBlocks($lackOfConfirmations);

	$json = bitcoind('getblockbyhash '.$block['hash'], true);
	if(strpos($json, 'error:') === 0) {
		$block['valid'] = false;
		return;
	} else if(isset($lackOfConfirmations[$block['hash']])) {
		$block['valid'] = $lackOfConfirmations[$block['hash']];
	}

	$bData = json_decode_safe($json, false);
	$block['when'] = $bData['time'];

	if($bData['time'] < $lastUnconfirmedBlockDate) {
		if(!isset($block['valid']) || $block['valid'] !== false) {
			$block['valid'] = true;
		}
	}

	if(!isset($block['valid'])) $block['valid'] = false;

	if($previousBlock == null) {
		unset($block['duration']);
	} else {
		$block['duration'] = $block['when'] - $previousBlock['when'];
	}
}

/**
 * Get all the currently unconfirmed blocks.
 * @param array $lackOfConfirmations list of currently unconfirmed block
 * @return integer timestamp of oldest unconfirmed block
 */
function getUnconfirmedBlocks(&$lackOfConfirmations) {
	$lackOfConfirmations = array();
	$firstDate = null;
	$blockCount = bitcoind('getblockcount');
	for($i = (NUM_CONFIRMATIONS - 1); $i >= 0; --$i) {
		$n = $blockCount - $i;
		$bData = json_decode_safe(bitcoind('getblockbycount '.$n), false);
		$lackOfConfirmations[$bData['hash']] = NUM_CONFIRMATIONS - $i;
		if($firstDate === null) {
			$firstDate = $bData['time'];
		}
	}
	return $firstDate;
}

/**
 * Update the metadata of all the blocks found by a pool.
 * @param string $server short server name
 * @return bool true if the operation succeeded.
 */
function updateAllBlockMetadata($server) {
	$old = cacheFetch('blocks_old_'.$server, $s0);
	$recent = cacheFetch('blocks_recent_'.$server, $s1);

	if(!$s0 || !$s1) {
		trigger_error('Cannot fetch block metadata for '.$server.' : could not fetch cached blocks.', E_USER_WARNING);
		return false;
	}

	$isSane = function($blk) { return isset($blk['hash']); };
	$old = array_filter($old, $isSane);
	$recent = array_filter($recent, $isSane);

	$c = count($recent);
	$d = count($old);

	$cb = function($a, $b) { return $b['when'] - $a['when']; };
	usort($recent, $cb);
	usort($old, $cb);

	// Update blocks in the chronological order (oldest blocks first)

	for($i = ($d - 1); $i >= 0; --$i) {
		if($i < ($d - 1)) {
			$previous = $old[$i + 1];
		} else $previous = null;

		updateBlock($old[$i], $previous);
	}

	for($i = ($c - 1); $i >= 0; --$i) {
		if($i < ($c - 1)) {
			$previous = $recent[$i + 1];
		} else if($d > 0) {
			$previous = $old[0];
		} else $previous = null;

		updateBlock($recent[$i], $previous);
	}

	// Try to move the shares from invalid blocks to the next
	for($i = ($c - 1); $i >= 1; --$i) {
		maybeProcessInvalidBlock($recent[$i], $recent[$i - 1]);
	}
	for($i = ($d - 1); $i >= 0; --$i) {
		if($i > 0) $previous = $old[$i - 1];
		else $previous = $recent[$c - 1];
		maybeProcessInvalidBlock($old[$i], $previous);
	}

	$s0 = cacheStore('blocks_old_'.$server, $old);
	$s1 = cacheStore('blocks_recent_'.$server, $recent);

	if(!$s0 || !$s1) {
		trigger_error('Cannot store block metadata for '.$server.' : could not store cached blocks.', E_USER_WARNING);
		return false;
	}

	return true;
}