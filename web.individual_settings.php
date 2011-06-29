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

if(!isset($_SESSION['address'])) {
	header('Location: ./authenticate');
	die();
}

$address = $_SESSION['address'];

if(isset($_POST['kzk'])) {
	$_SESSION['POST'] = $_POST;
	header('HTTP/1.1 303 See Other', 303, true);
	header('Location: '.$_SERVER['REQUEST_URI']);
	die();
}

if(isset($_SESSION['POST']['logout'])) {
	$_SESSION = array();
	addMessage('You have been logged out.');
	header('Location: ./');
	die();
}

printHeader("Change settings of address $address", "Change settings of address $address", $relative = '.');

echo <<<EOT
<form method="POST" action="">
<input type="hidden" value="kzk" name="kzk" />
<ul>
<li><input type="submit" name="logout" value="Logout" /> For extra security, log out when you are done.</li>
</ul>
</form>

EOT;


printFooter($relative);

$_SESSION['POST'] = array();