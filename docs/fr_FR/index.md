# Calendrier des programmations

Ce plugin affiche dans un calendrier tout ce que Jeedom va exécuter : scénarios
programmés, blocs A et DANS en attente, tâches différées des commandes, tâches
des plugins et de Jeedom lui-même. Il ne modifie rien ; il lit ce que le coeur a
programmé et le calcule exactement comme lui.

## Ouvrir le calendrier

Après activation, le calendrier est dans **Accueil → Calendrier des
programmations** (et dans **Plugins → Organisation**). L'entrée du menu Accueil
se retire en décochant « Afficher le panneau desktop » dans la gestion du plugin.

- **Mois** : chaque case montre les premiers déclenchements de la journée et
  leur nombre total. Un clic sur une journée la détaille à droite.
- **Semaine** : chaque journée en colonne, avec tous ses déclenchements.
- **Jour** : le détail heure par heure.

Chaque ligne du détail donne l'heure, la catégorie, le nom (cliquable vers le
scénario ou l'équipement) et le détail : la programmation, l'équipement, la
valeur que reprendra une commande…

## Les catégories

| Catégorie | Ce qu'elle contient |
|---|---|
| Scénarios programmés | Scénarios actifs en mode « Programmé » ou « Les deux » |
| Blocs A / DANS en attente | Blocs A et DANS déjà lancés par un scénario, et interactions différées |
| Retours d'état et alertes | Retour d'état automatique, action sur valeur, alerte avec délai |
| Tâches des plugins | Tâches qu'un plugin a inscrites au moteur de tâches, et actualisation automatique (`autorefresh`) des équipements |
| Fonctions cron des plugins | Les appels `cron`, `cron5`… `cronDaily`, avec la liste des plugins concernés |
| Échéances annoncées | Commandes `next…` dont la valeur est une date, et plugins compatibles |
| Tâches de Jeedom | Sauvegarde, maintenance, archivage de l'historique… |
| Moteur interne | Tâches qui font tourner Jeedom chaque minute ; masquées par défaut |

Les filtres se retiennent d'une visite à l'autre.

## Les tâches fréquentes

Une tâche qui part plus de 12 fois par jour (réglable dans la configuration du
plugin) est résumée en une ligne par jour : « ×288 · toutes les 5 min », avec la
première et la dernière heure, et la liste complète dépliable quand elle reste
lisible.

## Les pics de charge

Jeedom lance en même temps toutes les tâches prévues à la même minute, et
certaines minutes en accumulent : minuit surtout (fonctions `cronDaily` des
plugins, scénarios quotidiens, tâches horaires). Dès que 5 lignes affichées
partent ensemble (réglable dans la configuration du plugin, 0 pour ne rien
signaler), le calendrier le montre :

- une pastille rouge <i class="fas fa-layer-group"></i> dans la case du jour,
  avec le plus gros pic, et tous les pics du jour dans l'infobulle ;
- une ligne « Pic de charge » dans le détail de la journée, à la minute
  concernée, avec la liste des tâches qui partent ensemble.

Décaler l'une d'elles de quelques minutes suffit à étaler la charge. Le compte
suit les filtres et la recherche. Une tâche qui part plus de 48 fois par jour
n'y entre pas : elle tomberait sur chaque pic sans rien apprendre. Une ligne
« Fonction … des plugins » compte pour une, quel que soit le nombre de plugins
qu'elle appelle.

## Les marques

- **estimation** : le scénario est programmé par une expression (par exemple
  l'heure du lever du soleil). On n'en connaît que la valeur d'aujourd'hui, qui
  est reportée sur les jours suivants.
- **ponctuelle** : une tâche qui ne partira qu'une fois (bloc A ou DANS, retour
  d'état…).
- **en retard** : une tâche ponctuelle dont l'heure est passée sans qu'elle
  parte. Jeedom ne rattrape pas les tâches manquées : elle attendra la même
  date l'an prochain. Elle est montrée aujourd'hui, avec son heure prévue.
- **échouée** : une tâche ponctuelle partie en erreur, restée dans le moteur de
  tâches.
- **ne partira pas** : quelque chose empêche l'exécution, et la raison est
  donnée — moteur de scénarios ou de tâches désactivé, tâche `scenario::check`
  désactivée, scénario désactivé ou supprimé.

Un bandeau signale en tête ce qui bloque tout : moteur coupé, Jeedom pas encore
démarré, ou rattrapage des scénarios réglé sur -1.

## Comment les heures sont calculées

Les heures sont celles que le coeur retiendrait lui-même : le plugin interroge
la bibliothèque cron de Jeedom et reprend ses règles — sixième champ pour
l'année, expressions refusées, jour du mois OU jour de semaine, changements
d'heure (une tâche prévue à 02:30 le jour du passage à l'heure d'été part à
03:30). Une programmation que Jeedom ne sait pas lire est signalée, et une tâche
dont la classe n'existe plus (plugin désactivé ou désinstallé) n'est pas
montrée, puisqu'elle ne partira pas.

Les heures sont celles du fuseau de Jeedom. Si le navigateur est réglé sur un
autre fuseau, un bandeau le signale.

Un scénario manqué (Jeedom arrêté à l'heure prévue) peut encore partir jusqu'à
30 minutes plus tard : c'est le rattrapage du coeur, que le calendrier ne montre
pas.

## Ce qui ne peut pas être prévu

- Les scénarios déclenchés par un événement (changement de valeur d'une
  commande), qui ne partent qu'au moment où l'événement se produit.
- Les `sleep` et `wait` à l'intérieur d'un scénario en cours.
- Les blocs A et DANS d'un scénario qui n'a pas encore tourné : ils ne sont
  créés qu'à son exécution.
- Ce qu'un plugin calcule lui-même dans son `cron()` sans l'exposer : lever et
  coucher du soleil, simulation de présence… Ces plugins peuvent publier leur
  planning par la méthode décrite ci-dessous.

## Pour les développeurs de plugins

Un plugin peut publier son propre planning en ajoutant une méthode statique à sa
classe principale :

```php
public static function calendrierbeEvents($_from, $_to) {
    return array(
        array(
            'ts'     => 1790000000,                 // timestamp de l'exécution
            'title'  => 'Fermeture des volets',
            'detail' => '[Salon][Volets]',
            'link'   => 'index.php?v=d&m=monplugin&p=monplugin&id=12',
        ),
    );
}
```

Les événements hors de la période demandée sont ignorés. Le lien doit être une
page de Jeedom (`index.php?…`) ou une adresse web ; tout autre lien est retiré.
Les commandes `next…` d'un plugin qui a cette méthode ne sont plus lues, pour
ne pas afficher deux fois la même échéance.

Sans cette méthode, les commandes info dont l'identifiant logique commence par
`next` sont lues : leur valeur est retenue si c'est une date complète
(`2026-10-01`, `2026-10-01 07:30`, `01/10/2026`, ou un timestamp).
