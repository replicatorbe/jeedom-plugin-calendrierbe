<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('calendrierbe');
sendVarToJS('eqType', $plugin->getId());
sendVarToJS('calendrierbePeakThreshold', (int) config::byKey('peakThreshold', 'calendrierbe', 5));

/*
 * Les catégories, avec leur nom, leur icône et leur réglage d'affichage par
 * défaut. Le moteur interne de Jeedom (scenario::check, queue::cron…) est
 * masqué au départ : il tourne chaque minute et n'apprend rien sur ce que
 * l'installation va faire.
 */
sendVarToJS('calendrierbeCategories', array(
	'scenario'  => array('label' => __('Scénarios programmés', __FILE__), 'icon' => 'fas fa-cogs', 'on' => 1),
	'bloc'      => array('label' => __('Blocs A / DANS en attente', __FILE__), 'icon' => 'fas fa-hourglass-half', 'on' => 1),
	'commande'  => array('label' => __('Retours d\'état et alertes', __FILE__), 'icon' => 'fas fa-undo-alt', 'on' => 1),
	'plugin'    => array('label' => __('Tâches des plugins', __FILE__), 'icon' => 'fas fa-puzzle-piece', 'on' => 1),
	'fonction'  => array('label' => __('Fonctions cron des plugins', __FILE__), 'icon' => 'fas fa-sync-alt', 'on' => 1),
	'prevision' => array('label' => __('Échéances annoncées', __FILE__), 'icon' => 'far fa-calendar-check', 'on' => 1),
	'systeme'   => array('label' => __('Tâches de Jeedom', __FILE__), 'icon' => 'fas fa-server', 'on' => 1),
	'moteur'    => array('label' => __('Moteur interne', __FILE__), 'icon' => 'fas fa-microchip', 'on' => 0),
));
?>

<div id="div_calendrierbe" class="calbe">
	<div class="calbe-toolbar">
		<div class="btn-group">
			<a class="btn btn-sm btn-default calbe-nav" role="button" tabindex="0" data-nav="prev" title="{{Précédent}}"><i class="fas fa-chevron-left"></i></a>
			<a class="btn btn-sm btn-default calbe-nav" role="button" tabindex="0" data-nav="today">{{Aujourd'hui}}</a>
			<a class="btn btn-sm btn-default calbe-nav" role="button" tabindex="0" data-nav="next" title="{{Suivant}}"><i class="fas fa-chevron-right"></i></a>
		</div>
		<span class="calbe-title"></span>
		<span class="calbe-spacer"></span>
		<input class="form-control input-sm calbe-search" type="search" placeholder="{{Rechercher…}}" aria-label="{{Rechercher dans le calendrier}}">
		<div class="btn-group calbe-views">
			<a class="btn btn-sm btn-default calbe-view" role="button" tabindex="0" data-view="month">{{Mois}}</a>
			<a class="btn btn-sm btn-default calbe-view" role="button" tabindex="0" data-view="week">{{Semaine}}</a>
			<a class="btn btn-sm btn-default calbe-view" role="button" tabindex="0" data-view="day">{{Jour}}</a>
		</div>
		<a class="btn btn-sm btn-default calbe-refresh" role="button" tabindex="0" title="{{Recalculer}}"><i class="fas fa-sync"></i></a>
		<a class="btn btn-sm btn-default calbe-config" role="button" tabindex="0" title="{{Configuration}}"><i class="fas fa-wrench"></i></a>
	</div>

	<div class="calbe-filters"></div>
	<div class="calbe-warnings"></div>

	<div class="calbe-body">
		<div class="calbe-main"></div>
		<div class="calbe-side"></div>
	</div>
</div>

<?php include_file('desktop', 'calendrierbe', 'css', 'calendrierbe'); ?>
<?php include_file('desktop', 'calendrierbe', 'js', 'calendrierbe'); ?>
