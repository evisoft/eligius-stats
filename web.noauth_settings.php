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

$__footerJS = '';

function toggleCookie($id, $cookieName, $labelOn, $labelOff) {
	global $__footerJS;
	echo '<input type="button" onclick="EligiusUtils.lcsToggleCookie(\'#stc_'.$id.'\', \''.$cookieName.'\', \''.$labelOn.'\', \''.$labelOff.'\');" id="stc_'.$id.'" value=" " /><br />';
	$__footerJS .= 'EligiusUtils.lcsUpdateContentRaw(\'#stc_'.$id.'\', \''.$cookieName.'\', \''.$labelOn.'\', \''.$labelOff.'\');';
}

function colorPicker($id, $storageName, $defaultColor) {
	global $__footerJS;
	echo '<div id="stl_'.$id.'" style="display: inline-block; vertical-align: middle;"></div> <a href="javascript:void(0);" onclick="$(\'#stl_'.$id.'\').ColorPickerSetColor(\'#'.$defaultColor.'\'); localStorage[\'a2_'.$storageName.'\'] = \''.$defaultColor.'\';">(Restore the default color)</a>';
	$__footerJS .= 'EligiusUtils.stlBindColorPicker("stl_'.$id.'", "'.$storageName.'", "'.$defaultColor.'");';

}

printHeader('Change my local settings', 'Change my local settings', $relative = '.', true, true);

echo "<p><strong>These settings will be stored in your browser's cache, so they will naturally go away when you
clean your cache. Don't forget it !</strong><br >
If you have problems, make sure you enabled Javascript, cookies and make sure your browser supports LocalStorage.</p>\n";

echo "<p>When you are done, you can <a href=\"./\">go back to the main page</a>.</p>\n<hr />\n";

echo "<form method=\"GET\" action=\"./\"><ul class=\"localsettings\">\n";

echo "<li>";
toggleCookie('tbc', 'tbc', 'Switch to BTC', 'Switch to TBC');
echo " Choose which format to use when displaying Bitcoin balances.
TBC are tonal Bitcoins, you can read more about them <a href=\"https://en.bitcoin.it/wiki/Tonal_Bitcoin\">in the wiki</a>.</li>\n";

echo "<li>";
toggleCookie('noanim', 'noanim', 'Enable realtime share count updates', 'Disable realtime share count updates');
echo " Use this if the instant share count updates are too frequent to your taste.</li>\n";

$submit = '<span style="width: 22px; height: 22px; display: inline-block; overflow: hidden; background-image: url(\'./colorpicker/images/colorpicker_submit.png\'); vertical-align: middle;"></span>';
echo "</ul>\n<hr />\n<p><strong>Note : to save your color changes, click the submit button ($submit) of the appropriate color pickers.</strong></p><ul class=\"localsettings\">\n";

echo "<li>";
colorPicker('alreadypaid', 'alreadypaid', COLOR_ALREADY_PAID);
echo " Color of the <strong>Already paid</strong> area on the balance graph.</li>\n";

echo "<li>";
colorPicker('unpaid', 'unpaid', COLOR_UNPAID);
echo " Color of the <strong>Unpaid reward</strong> area on the balance graph.</li>\n";

echo "<li>";
colorPicker('current', 'current', COLOR_CURRENT_BLOCK);
echo " Color of the <strong>Current block estimate</strong> area on the balance graph.</li>\n";

echo "<li>";
colorPicker('threshold', 'threshold', COLOR_THRESHOLD);
echo " Color of the <strong>Payout threshold</strong> line on the balance graph.</li>\n";

echo "<li>";
colorPicker('credit', 'credit', COLOR_CREDIT);
echo " Color of the <strong>Maximum reward</strong> area on the balance graph.</li>\n";

echo "<li>";
colorPicker('hashrate', 'hashrate', COLOR_HASHRATE);
echo " Color of the <strong>Hashrate</strong> area on the invidivual hashrate graph.</li>\n";

echo "<li>";
colorPicker('hashrate_short', 'hashrate_short', COLOR_SHORTAVG);
echo " Color of the <strong>3 hour average</strong> line on the invidivual hashrate graph.</li>\n";

echo "<li>";
colorPicker('hashrate_long', 'hashrate_long', COLOR_LONGAVG);
echo " Color of the <strong>12 hour average</strong> line on the invidivual hashrate graph.</li>\n";

echo "</ul></form>\n";

echo "<script type=\"text/javascript\">$__footerJS</script>\n";

printFooter($relative);
