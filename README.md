eligius-stats
=============

These scripts generate statistics (xHTML pages and graphes) about the Eligius pool available at http://eligius.st/~artefact2/.

It requires :

* PHP >= 5.3 with the curl extension,
* Apache2 (any other HTTPd may work too, if you rewrite the .htaccess yourself)
* A local instance of bitcoind running with the following patches :
	* https://github.com/khalahan/bitcoin/tree/signandverif
	* https://github.com/jgarzik/bitcoin/tree/getblockbycount

These scripts are distributed under the GNU Affero General Public License v3.

Author : Artefact2 <artefact2@gmail.com>

Depedencies
===========

* You must fetch the canvas-text library and place it in the canvas-text folder. One easy way to do that is to run :

	svn checkout http://canvas-text.googlecode.com/svn/trunk/ canvas-text

* You must fetch the colorpicker jQuery plugin and place it in the colorpicker folder. The library can be found at :

	http://www.eyecon.ro/colorpicker/

Using the cli.update.php script
===============================

The data should be inserted in the JSON files at regular intervals, thus it is best to use cron jobs to do that. If
too much data is inserted, one point will represent less than a pixel on the graph, and the JSON files will take
a lot of space.

Recommended crontab :

	0    * * * * /path/to/cli.update.php pool_hashrates
	*/15 * * * * /path/to/cli.update.php balances random_addresses individual_hashrates top_contributors
	*/3  * * * * /path/to/cli.update.php average_hashrates
	*    * * * * /path/to/cli.update.php pool_status blocks instant_rate
