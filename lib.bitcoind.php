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

const BITCOIND_BIN = '/home/artefact2/.bitcoin/bitcoind';

/**
 * Execute a bitcoind command.
 * @param string $method name of the method, with its arguments
 * @param bool $silentErrors should we print bitcoind errors to stdout?
 * @return string the result
 */
function bitcoind($method, $silentErrors = false) {
	requireBitcoind();
	$args = explode(' ', $method);
	$args = array_map('escapeshellarg', $args);
	$method = implode(' ', $args);
	
	return shell_exec(escapeshellcmd(BITCOIND_BIN).' '.$method.($silentErrors ? ' 2>&1' : ''));
}

/**
 * Die if no suitable bitcoin daemon is present.
 * @return bool true if a suitable bitcoind is present.
 */
function requireBitcoind() {
	static $hasBitcoind = null;
	if($hasBitcoind !== null) return $hasBitcoind;

	$hasBitcoind = is_numeric(trim(shell_exec(BITCOIND_BIN.' getblockcount')));
	if(!$hasBitcoind) {
		die("No bitcoind found, is BITCOIND_PATH valid ?\n");
	}

	$hasBitcoind = (strpos(
		shell_exec(BITCOIND_BIN.' getblockbycount 42'),
		'00000000314e90489514c787d615cea50003af2023796ccdd085b6bcc1fa28f5'
	) !== false);
	if(!$hasBitcoind) {
		die("The present bitcoind does not support the getblockbycount command.\n");
	}

	$hasBitcoind = (strpos(
		shell_exec(BITCOIND_BIN.' verifymessage 04927d1d5822f4201f0c37ab922f682b49a0f312fd8e24ac8dc2972d197e3ffab6def0a0ab2c5fb26299a0ef25a047a8242f0041679d62c8fe9c1ada4c2e44125b 30450220120ed83ed3f96029cc178fb7b6d4c30cf0bdc0c7354b0b2d20d9458d7d1f4d66022100bccbf79e325a205a9ae5c8263c88cd96741abc8b4eeaab856186012c901a7642 Artefact2_Eligius_Test'),
		'1PEbsG61JK4xbCC2SainpJ3UqgbGheLc9N'
	) !== false);
	if(!$hasBitcoind) {
		die("The present bitcoind does not support the verifymessage command.\n");
	}
}