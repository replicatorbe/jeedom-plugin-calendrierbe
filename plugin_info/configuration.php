<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
	include_file('desktop', '404', 'php');
	die();
}
?>
<form class="form-horizontal">
	<fieldset>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Résumer une tâche au-delà de}}</label>
			<div class="col-md-3">
				<div class="input-group">
					<input class="configKey form-control" data-l1key="threshold" type="number" min="1" max="1440" placeholder="12">
					<span class="input-group-addon">{{exécutions par jour}}</span>
				</div>
			</div>
			<div class="col-md-5 help-block">{{Une tâche qui part plus souvent dans la journée est montrée en une seule ligne (« 288 fois, toutes les 5 min ») au lieu d'une ligne par exécution.}}</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Échéances annoncées par les plugins}}</label>
			<div class="col-md-3">
				<input type="checkbox" class="configKey" data-l1key="showNextCommands">
			</div>
			<div class="col-md-5 help-block">{{Montre les commandes « next… » des équipements actifs dont la valeur est une date complète : prochaine collecte, renouvellement d'un certificat…}}</div>
		</div>
	</fieldset>
</form>
