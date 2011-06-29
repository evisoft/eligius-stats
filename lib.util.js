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

var EligiusUtils = {};

EligiusUtils.formatHashrate = function(rate, axis) {
	if(rate == 0) {
		return "0 H/s";
	} else if(rate < 10 * 1000000) {
		return (rate / 1000).toFixed(2) + " KH/s";
	} else if(rate < 10 * 1000000000) {
		return (rate / 1000000).toFixed(2) + " MH/s";
	} else return (rate / 1000000000).toFixed(2) + " GH/s";
}

EligiusUtils.formatBTC = function(money, axis) {
	return money.toFixed(3) + " BTC";
}

EligiusUtils.formatTBC = function(money, axis) {
	money /= 0.00000001;
	var rem = parseInt(money % 65536, 10);
	money /= 65536;
	money = parseInt(money, 10);

	var tonalAlphabet = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "", "9", "", "", "", "", ""];
	var r;
	var s = "";
	var dec = "";

	while(money) {
		r = money % 16;
		money = (money - r) / 16;

		s = tonalAlphabet[r] + s;
	}

	while(rem) {
		r = rem % 16;
		rem = (rem - r) / 16;

		dec = tonalAlphabet[(r + 16) % 16] + dec;
	}

	while(dec.length > 0 && dec[dec.length - 1] == "0") {
		dec = dec.substr(0, dec.length - 1);
	}

	if(dec.length > 0) s = s + "." + dec;

	if(s == "") s = '0.00';

	return s + " TBC";
}

EligiusUtils.toggleAutorefresh = function() {
	if(EligiusUtils.autorefresh) {
		EligiusUtils.autorefresh = false;
		clearTimeout(EligiusUtils.autorefresh_t);
		$("#autorefresh_message").html(' &mdash; The page will no longer refresh automatically.');
	} else {
		EligiusUtils.autorefresh = true;
		EligiusUtils.autorefresh_t = setTimeout(function() {
			window.location.replace("?autorefresh=1");
		}, 300000);
		$("#autorefresh_message").html(' &mdash; The page will refresh automatically every 5 minutes.');
	}
}

EligiusUtils.movingAverage = function(data, window, interval) {
	var points = [];
	var c = data.length;
	if(c == 0) return points;

	for(var Z = 0; Z < c; ++Z) {
		data[Z][0] = parseInt(data[Z][0]);
	}
	window = parseInt(window);
	interval = parseInt(interval);

	var start = data[0][0];
	var stop = start + window;
	var sum, i;
	var k = 0;
	while(stop <= data[c - 1][0]) {
		sum = 0;
		for(i = k; i < (c - 1); ++i) {
			if(data[i + 1][0] < start) {
				++k;
				continue;
			}

			if(data[i + 1][0] <= stop) {
				sum += data[i][1] * Math.min(data[i + 1][0] - data[i][0], data[i + 1][0] - start);
			} else {
				sum += data[i][1] * Math.min(stop - data[i][0], stop - start);
				break;
			}
		}

		points.push([stop, sum / window]);

		start += interval;
		stop += interval;
	}

	return points;
}

EligiusUtils.shiftData = function(data, shiftAmount) {
	var points = [];
	var c = data.length;
	var i;
	for(i = 0; i < c; ++i) {
		points.push([data[i][0], data[i][1] + shiftAmount]);
	}

	return points;
}

EligiusUtils.findDataMin = function(data) {
	var c = data.length;
	var min = 0;
	for(var i = 1; i < c; ++i) {
		if(data[i][1] < data[min][1]) {
			min = i;
		}
	}

	return min;
}

EligiusUtils.findDataMax = function(data) {
	var c = data.length;
	var max = 0;
	for(var i = 1; i < c; ++i) {
		if(data[i][1] > data[max][1]) {
			max = i;
		}
	}

	return max;
}

EligiusUtils.splitHorizontalLine = function(data) {
	var c = data.length;
	var current = 0;
	var points = [];
	for(var i = 0; i < c; ++i) {
		if(Math.abs(data[i][1] - data[current][1]) > 0.0000001) {
			points.push([data[i][0] - 42, data[current][1]]);
			points.push(null);
			current = i;
		}

		points.push(data[i]);
	}

	return points;
}

EligiusUtils.toggleAnnouncementVisibility = function() {
	var announcementClass = $('div#announcements_id').html();

	if($.cookie('a2_hide_announcements') == announcementClass) {
		$.cookie('a2_hide_announcements', '', { expires: -1, path: '/' });
		$('.' + announcementClass).slideDown(500);
	} else {
		$.cookie('a2_hide_announcements', announcementClass, { expires: 14, path: '/' });
		$('.' + announcementClass).slideUp(500);
	}

	EligiusUtils.updateToggleLink();
}

EligiusUtils.maybeHideAnnouncements = function() {
	var announcementClass = $('div#announcements_id').html();

	if($.cookie('a2_hide_announcements') == announcementClass) {
		$('.' + announcementClass).css('display', 'none');
	}

	EligiusUtils.updateToggleLink();
}

EligiusUtils.updateToggleLink = function() {
	var announcementClass = $('div#announcements_id').html();

	if($.cookie('a2_hide_announcements') == announcementClass) {
		$('a#announcement_toggle').html('Show announcements [^]');
	} else {
		$('a#announcement_toggle').html('Hide announcements [X]');
	}
}

EligiusUtils.formatNumber = function(num) {
	var str = num + '';
	var ret = num.substr(0, num.length % 3);
	for(var i = ret.length; i < num.length; i += 3) {
		ret = ret + ',' + str.substr(i, 3);
	}

	if(ret.substr(0, 1) == ',') ret = ret.substr(1);

	return ret;
}

EligiusUtils.getCDF = function(shares, difficulty) {
	return 1.0 - Math.exp(-shares / difficulty);
}

EligiusUtils.initShareCounter = function(servers) {
	var instantData;
	var rates = {};
	var totals = {};
	var lastUpdated = {};
	var c = servers.length;
	var i;
	var t;
	var name;
	var difficulty;

	$.get("./json/instant_share_count.json", "", function(data, textStatus, xhr) {
		for(i = 0; i < c; ++i) {
			rates[servers[i]] = +data[servers[i]].instantRate;
			totals[servers[i]] = +data[servers[i]].totalShares;
			lastUpdated[servers[i]] = +data[servers[i]].lastUpdated;
		}
		difficulty = data.difficulty;

		var periodicRefresh = function() {
			$.get("./json/instant_share_count.json", "", function(data, textStatus, xhr) {
				for(i = 0; i < c; ++i) {
					rates[servers[i]] = Math.max((((+data[servers[i]].totalShares) + 60 * (+data[servers[i]].instantRate))
						- totals[servers[i]]) / 60, 0);
				}
				difficulty = data.difficulty;
			}, "json");
		}
		var updateCounts = function() {
			t = 0.001 * (new Date().getTime() + __clockOffset);
			for(i = 0; i < c; ++i) {
				name = servers[i];
				totals[name] += (t - lastUpdated[name]) * rates[name];
				lastUpdated[name] = t;
				$("#instant_scount_" + name).html(EligiusUtils.formatNumber(totals[name].toFixed(0)));
				$("#instant_cdf_" + name).html((EligiusUtils.getCDF(totals[name], difficulty) * 100).toFixed(3));
			}
		};
		var magic = function() {
			updateCounts();
			requestAnimFrame(magic);
		}

		setInterval(periodicRefresh, 60000);
		setInterval(updateCounts, 60000);
		requestAnimFrame(magic);
	}, "json");
}

/* -------------------------------------------------------------------------------------------------------------------*/
/*
 * Copyright 2010, Google Inc.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are
 * met:
 *
 *     * Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above
 * copyright notice, this list of conditions and the following disclaimer
 * in the documentation and/or other materials provided with the
 * distribution.
 *     * Neither the name of Google Inc. nor the names of its
 * contributors may be used to endorse or promote products derived from
 * this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

/**
 * Provides requestAnimationFrame in a cross browser way.
 */
window.requestAnimFrame = (function() {
  return window.requestAnimationFrame ||
         window.webkitRequestAnimationFrame ||
         window.mozRequestAnimationFrame ||
         window.oRequestAnimationFrame ||
         window.msRequestAnimationFrame ||
         function(/* function FrameRequestCallback */ callback, /* DOMElement Element */ element) {
           window.setTimeout(callback, 1000/60);
         };
})();
