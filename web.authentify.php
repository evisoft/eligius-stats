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

const MAGIC_STRING = 'eligius';

require __DIR__.'/lib.eligius.php';
require __DIR__.'/inc.servers.php';

if(isset($_SESSION['address'])) {
	header('Location: ./individualSettings');
	die();
}

if(isset($_POST['signature'])) {
	$_SESSION['POST'] = $_POST;
	header('HTTP/1.1 303 See Other', 303, true);
	header('Location: '.$_SERVER['REQUEST_URI']);
	die();
}

if(isset($_SESSION['POST']['signature'])) {
	$sig = $_SESSION['POST']['signature'];

	preg_match_all('%"(pubkey|sign)"\s*:\s*"([a-fA-F0-9]+)"%', $sig, $matches);

	if(count($matches[1]) != 2 || !(($matches[1][0] == 'sign' && $matches[1][1] == 'pubkey') || ($matches[1][0] == 'pubkey' && $matches[1][1] == 'sign'))) {
		addError('Cannot parse your signature. Did you paste the correct output of the Bitcoin client ?');
	} else {
		if($matches[1][0] == 'sign' && $matches[1][1] == 'pubkey') {
			$sign = $matches[2][0];
			$pubkey = $matches[2][1];
		} else {
			$sign = $matches[2][1];
			$pubkey = $matches[2][0];
		}

		$output = bitcoind('verifymessage '.$pubkey.' '.$sign.' '.MAGIC_STRING, true);
		if(strpos($output, 'error:') !== false) {
			addError('The signature could not be verified. Are you sure you pasted the correct output of the Bitcoin client ?');
		} else {
			$json = json_decode_safe($output, false);
			if(!isset($json['address'])) {
				addError('The "verifymessage" command did not throw an error, yet returned no address. Please report the bug !');
			} else {
				$_SESSION['address'] = $json['address'];
				addMessage('Authentication was successful. Your address is : '.$json['address'].'.');
				header('Location: /individualSettings');
				die();
			}
		}
	}

	$value = htmlspecialchars($sig);
} else {
	$value = '';
}

printHeader('Login on Eligius', 'Login on Eligius', $relative = '.');

$magic = MAGIC_STRING;
echo <<<EOT

<p><strong>Important : it is not necessary to log in to see your statistics ! Logging in allows you to change some
address-related settings.</strong></p>

<h2>Instructions</h2>

<p>Since Eligius users use their Bitcoin address as their username, you must prove that you actually own the address
to log in. To do that, you must use the "signmessage" RPC command of your Bitcoin daemon.<br />
However the official client does not <strong>yet</strong> have the "signmessage" RPC command yet, so you must use an
unofficial patch <a href="https://github.com/khalahan/bitcoin/tree/signandverif">available here on GitHub</a>.</p>

<p>Once you have access to the "signmessage" command, run the following command :<br />
<tt style="padding-left: 3em;">bitcoind signmessage <em>&lt;your_address&gt;</em> "$magic"</tt><br />
(Replace your address and eventually the "bitcoind" if it is not in your path.)</p>

<p>Copy the output of this command and paste it in the box below :<br />
<form method="POST" action="">
<textarea placeholder='Paste all the generated message in this box.' name="signature" cols="125" rows="8">$value</textarea><br />
<input type="submit" value="Log in !" />
</form>
</p>

<h2>Compiling help</h2>

<p>If you need help to get the unofficial bitcoind running, here are some instructions :</p>
<code>
git clone git://github.com/khalahan/bitcoin.git # fetch the source <br />
cd bitcoin <br />
git checkout signandverif # apply the "signmessage" modifications <br />
make -f makefile.unix bitcoind # only compile the server
</code>

<p>You can then use the bitcoind executable to launch the Bitcoin server, and use "./bitcoind signmessage" to log in.</p>

EOT;


printFooter($relative);

/* Unset the signature, it is as sensitive information as a password. */
$_SESSION['POST'] = array();